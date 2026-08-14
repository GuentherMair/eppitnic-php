-- ============================================================================
-- Migration: rename tbl_* -> * and convert charset/collation to
--            utf8mb4 / utf8mb4_unicode_ci
--
-- IMPORTANT:
--   * Take a full backup before running this. CONVERT TO CHARACTER SET
--     rebuilds every text column and is not reversible by re-running.
--   * THIS SCRIPT DESTROYS DATA, deliberately, in two places: tbl_accounting
--     is dropped in full (PART 2) and users.billingID is dropped (PART 3).
--     Invoicing has left this codebase; export both first if you want them.
--   * Text columns are rewritten to decode the HTML entities 6.x stored (see
--     PART 3): "Rossi &amp; Figli" becomes "Rossi & Figli".
--   * Run this via a non-interactive client that stops on the first error,
--     e.g.:  mysql -u USER -p DBNAME < migrate_rename_charset.sql
--     (this is the default `mysql` CLI behaviour; do NOT pass --force)
--   * Test on a staging copy first.
--   * Tables are converted with FOREIGN_KEY_CHECKS=0 so that the temporary
--     charset mismatch between a not-yet-converted child and an
--     already-converted parent doesn't block the ALTER. No FK checks are
--     skipped that would let bad data in -- no rows are inserted/deleted
--     by this script, only column/table metadata + rebuild.
--   * `handleID` (MyISAM, utf8mb3) is NOT touched anywhere in this script --
--     it isn't part of any target schema seen so far. Confirm whether it's
--     legacy/dead or still needed before deciding what to do with it.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- PART 1: PRE-FLIGHT CHECKS
--
-- Everything in this part is read-only (SELECTs only) until the final
-- SIGNAL/no-op decision -- an abort here leaves the database completely
-- untouched. Covers two kinds of risk:
--
--   (a) Case-insensitive collisions in UNIQUE text columns moving from a
--       case-sensitive collation to a case-insensitive one. Of the columns
--       checked, only tbl_domains.domain is actually at risk in the current
--       dump -- its table default is utf8mb3_bin (case-sensitive) and
--       `domain` has no column-level override. tbl_contacts.handle and
--       tbl_transfers.domain are already utf8mb3_general_ci (case-
--       insensitive) today, so converting them to utf8mb4_unicode_ci changes
--       nothing about their case-sensitivity -- those two checks are kept
--       anyway as cheap, harmless insurance.
--
--   (b) Data that would violate PART 3's stricter shape for `reminder`
--       (TEXT -> VARCHAR(255), nullable `date` -> NOT NULL) or would make
--       PART 3's new `reminder.domain -> domains.domain` FK impossible to
--       add (orphaned domain values).
--
-- If any check fails, it SIGNALs an error, which -- under default `mysql`
-- CLI settings -- halts the script immediately, before PART 2 runs.
-- ----------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS `_migration_preflight_checks`;

DELIMITER $$

CREATE PROCEDURE `_migration_preflight_checks`()
BEGIN
    DECLARE cnt INT DEFAULT 0;
    DECLARE msg VARCHAR (255);

    -- (tbl_users.billingID is not checked: the column is dropped by this
    -- migration, so a case-insensitive collision in it cannot break anything.)

    -- tbl_contacts.handle (unique, NOT NULL)
    SELECT COUNT(*) INTO cnt FROM (
        SELECT LOWER(handle) AS k
        FROM tbl_contacts
        GROUP BY LOWER(handle)
        HAVING COUNT(*) > 1
    ) x;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_contacts.handle has ', cnt,
                          ' case-insensitive collision group(s). Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    -- tbl_domains.domain (unique, NOT NULL) -- the one column genuinely
    -- moving from case-sensitive to case-insensitive.
    SELECT COUNT(*) INTO cnt FROM (
        SELECT LOWER(domain) AS k
        FROM tbl_domains
        GROUP BY LOWER(domain)
        HAVING COUNT(*) > 1
    ) x;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_domains.domain has ', cnt,
                          ' case-insensitive collision group(s). Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    -- tbl_transfers.domain (unique, NOT NULL)
    SELECT COUNT(*) INTO cnt FROM (
        SELECT LOWER(domain) AS k
        FROM tbl_transfers
        GROUP BY LOWER(domain)
        HAVING COUNT(*) > 1
    ) x;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_transfers.domain has ', cnt,
                          ' case-insensitive collision group(s). Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    -- tbl_reminder.notice: TEXT -> VARCHAR(255) in PART 3. Anything longer
    -- would be silently truncated (or rejected in strict mode).
    SELECT COUNT(*) INTO cnt FROM tbl_reminder WHERE CHAR_LENGTH(notice) > 255;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_reminder.notice has ', cnt,
                          ' row(s) longer than 255 characters. Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    -- tbl_reminder.date: nullable today, becomes NOT NULL in PART 3.
    SELECT COUNT(*) INTO cnt FROM tbl_reminder WHERE `date` IS NULL;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_reminder.date has ', cnt,
                          ' NULL row(s). Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    -- tbl_reminder.domain: PART 3 adds a real FK to domains.domain. Any
    -- value here with no matching row in tbl_domains would make that
    -- ADD CONSTRAINT fail outright.
    SELECT COUNT(*) INTO cnt
    FROM tbl_reminder r
    LEFT JOIN tbl_domains d ON d.domain = r.domain
    WHERE d.domain IS NULL;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_reminder has ', cnt,
                          ' row(s) whose domain has no match in tbl_domains. Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    -- tbl_contacts.userID / tbl_domains.userID: PART 3 restores the FKs to
    -- users.id that the target schema declares (config/mariadb-schema.sql).
    -- A dump whose rows point at a user that no longer exists would make
    -- those ADD CONSTRAINTs fail.
    SELECT COUNT(*) INTO cnt
    FROM tbl_contacts c
    LEFT JOIN tbl_users u ON u.id = c.userID
    WHERE u.id IS NULL;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_contacts has ', cnt,
                          ' row(s) whose userID has no match in tbl_users. Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    SELECT COUNT(*) INTO cnt
    FROM tbl_domains d
    LEFT JOIN tbl_users u ON u.id = d.userID
    WHERE u.id IS NULL;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_domains has ', cnt,
                          ' row(s) whose userID has no match in tbl_users. Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    SELECT 'PRE-FLIGHT OK: no collisions, no oversized/NULL reminder data, no orphaned reminder domains or user references.' AS result;
END$$

DELIMITER ;

CALL `_migration_preflight_checks`();
DROP PROCEDURE `_migration_preflight_checks`;

-- If you got here without an error above, it's safe to proceed to PART 2.


-- ----------------------------------------------------------------------------
-- PART 2: RENAME + CONVERT
-- ----------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

-- Drop the 3 FK constraints whose referenced/referencing TEXT column is
-- about to be rebuilt by CONVERT TO CHARACTER SET below. MariaDB refuses to
-- change such a column in place (error 1833: "Cannot change column ...: used
-- in a foreign key constraint") regardless of FOREIGN_KEY_CHECKS, so the
-- constraint has to be removed first, not just have validation disabled.
--   * tbl_domains_ibfk_2 and tbl_transfers_ibfk_1 are recreated further down,
--     once contacts.handle has its new charset -- the relationship itself
--     isn't changing, only the physical column's charset underneath it.
ALTER TABLE tbl_domains DROP FOREIGN KEY tbl_domains_ibfk_2;
ALTER TABLE tbl_transfers DROP FOREIGN KEY tbl_transfers_ibfk_1;

-- ############################################################################
-- DESTRUCTIVE: tbl_accounting is DROPPED, with every row in it.
--
-- Invoicing has been taken out of this codebase and will be reimplemented
-- elsewhere: no target schema has an accounting table and nothing reads one.
--
-- EXPORT IT FIRST IF YOU STILL WANT IT. There is no way back from here short
-- of the backup this script's header told you to take.
--
-- Dropping the table also removes tbl_accounting_ibfk_1, which has to go
-- regardless: users.billingID is dropped in PART 3, and MariaDB refuses to
-- rebuild a TEXT column that a foreign key references (error 1833).
-- ############################################################################
DROP TABLE IF EXISTS tbl_accounting;

-- tbl_contacts_ibfk_1 (contacts.userID -> users.id) and tbl_domains_ibfk_1
-- (domains.userID -> users.id) are deliberately left alone: both sides are
-- BIGINT, and CONVERT TO CHARACTER SET only rewrites char/text-type columns
-- -- id/userID are never touched by it, so there's nothing for error 1833
-- to trip over there.

ALTER TABLE tbl_users
  RENAME TO users,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_contacts
  RENAME TO contacts,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_domains
  RENAME TO domains,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Drop the 4 DNSSEC-related columns from domains, now that it has been
-- renamed. Combined into a single ALTER TABLE so it's one metadata
-- operation rather than four separate table rebuilds.
ALTER TABLE domains
  DROP COLUMN dsAlgorithm,
  DROP COLUMN dsDigest,
  DROP COLUMN dsDigestType,
  DROP COLUMN dsKeyTag;

ALTER TABLE tbl_transfers
  RENAME TO transfers,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_transactions
  RENAME TO transactions,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_responses
  RENAME TO responses,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_msgqueue
  RENAME TO msgqueue,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_messages
  RENAME TO messages,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE messages
  DROP COLUMN archived;

-- tbl_reminder holds real data, so it is renamed + converted like the other
-- tables rather than created fresh, which would strand every existing row
-- under the old name.
ALTER TABLE tbl_reminder
  RENAME TO reminder,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Recreate the two FKs dropped above, now that contacts.handle carries its
-- final charset. FOREIGN_KEY_CHECKS is still 0 here, which is fine: this
-- isn't new data risk, it's restoring a relationship that was already valid
-- a moment ago and whose underlying data hasn't changed in between.
ALTER TABLE domains
  ADD CONSTRAINT FOREIGN KEY (registrant) REFERENCES contacts(handle) ON UPDATE CASCADE;

ALTER TABLE transfers
  ADD CONSTRAINT FOREIGN KEY (registrant) REFERENCES contacts(handle) ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;


-- ----------------------------------------------------------------------------
-- PART 3: COLUMN NAME CORRECTIONS + STRUCTURAL FIXES
--
-- Renames columns from the original camelCase names to the corrected
-- lower_snake_case names, per the target schema supplied, and brings
-- reminder the rest of the way to its target shape.
--
-- CHANGE COLUMN requires the full column definition, not just the new name,
-- so each clause restates the existing type/nullability/default. Text
-- columns explicitly restate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
-- so the rename can't accidentally regress the charset work done in Part 2.
-- Existing indexes (UNIQUE, PRIMARY KEY, FOREIGN KEY) automatically follow a
-- renamed column -- they do not need to be, and must not be, redeclared here.
-- ----------------------------------------------------------------------------

-- `billingID` is dropped, not renamed: nothing reads a billing identifier any
-- more. PART 2 already dropped tbl_accounting and with it the FK on this column.
ALTER TABLE users
  DROP COLUMN `billingID`,
  CHANGE COLUMN `maxOperations` `max_operations` INT DEFAULT 0;

ALTER TABLE transactions
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `clTRType` `cl_trtype` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `clTRObject` `cl_trobject` VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `clTRData` `cl_trdata` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads';

ALTER TABLE responses
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svTRID` `sv_trid` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svEPPCode` `sv_code` VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svHTTPCode` `sv_httpcode` SMALLINT UNSIGNED,
  CHANGE COLUMN `svHTTPHeaders` `sv_httpheaders` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  CHANGE COLUMN `svHTTPData` `sv_httpdata` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  CHANGE COLUMN `extValueReasonCode` `extvaluereasoncode` VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `extValueReason` `extvaluereason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads';

ALTER TABLE msgqueue
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svTRID` `sv_trid` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svCode` `sv_code` VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svHTTPCode` `sv_httpcode` SMALLINT UNSIGNED,
  CHANGE COLUMN `svHTTPHeaders` `sv_httpheaders` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  CHANGE COLUMN `svHTTPData` `sv_httpdata` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads';

ALTER TABLE contacts
  CHANGE COLUMN `userID` `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE domains
  CHANGE COLUMN `userID` `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  CHANGE COLUMN `crDate` `cr_date` DATE,
  CHANGE COLUMN `exDate` `ex_date` DATE,
  CHANGE COLUMN `lastInvoice` `last_invoice` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE transfers
  CHANGE COLUMN `userID` `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE messages
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svTRID` `sv_trid` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `acID` `ac_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `reID` `re_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `archivedUserID` `archived_user_id` BIGINT UNSIGNED,
  CHANGE COLUMN `archivedTime` `archived_time` DATETIME DEFAULT NULL,
  CHANGE COLUMN `createdTime` `created_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- `reminder` pre-dates this migration (unlike history) and already holds
-- data, so -- unlike history -- it's altered in place here rather than
-- created fresh; PART 2 only renamed + converted its charset, this finishes
-- the job: reorders columns to match the target layout, narrows `notice`
-- from TEXT to VARCHAR(255) and makes `date` NOT NULL (both pre-flight
-- checked in PART 1), adds `action`/`created_time`, adds a real PRIMARY KEY
-- (it only had a UNIQUE KEY before), and adds the domain -> domains.domain
-- FK (orphans also pre-flight checked in PART 1, so this should succeed
-- cleanly under FOREIGN_KEY_CHECKS=1, already restored by PART 2).
ALTER TABLE reminder
  MODIFY COLUMN `domain` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `id`,
  MODIFY COLUMN `date` DATE NOT NULL AFTER `domain`,
  MODIFY COLUMN `notice` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `date`,
  MODIFY COLUMN `email` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `notice`,
  ADD COLUMN `action` ENUM('create','update','delete') AFTER `email`,
  MODIFY COLUMN `active` TINYINT DEFAULT 1 AFTER `action`,
  ADD COLUMN `created_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `active`,
  ADD PRIMARY KEY (`id`),
  ADD KEY `domain` (`domain`),
  ADD KEY `action` (`action`),
  ADD CONSTRAINT FOREIGN KEY (`domain`) REFERENCES domains(domain) ON DELETE RESTRICT ON UPDATE CASCADE;

-- The two ownership FKs the target schema declares (contacts.user_id and
-- domains.user_id -> users.id). A 6.7 dump may or may not carry them, so
-- each is added only if absent.
--
-- The check is on the column pair, not a constraint name: an existing one
-- carries whatever name InnoDB generated, so IF NOT EXISTS on a name chosen
-- here would miss it and add a second, redundant constraint.
--
-- Orphan rows are pre-flight checked in PART 1, so these apply cleanly under
-- FOREIGN_KEY_CHECKS=1.
SET @fk_contacts_user := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'user_id'
    AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id');
SET @sql := IF(@fk_contacts_user = 0,
  'ALTER TABLE contacts ADD CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_domains_user := (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'user_id'
    AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id');
SET @sql := IF(@fk_domains_user = 0,
  'ALTER TABLE domains ADD CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ----------------------------------------------------------------------------
-- Decode the HTML entities in stored text.
--
-- Contact::set() and Domain::set() used to run every value through
-- htmlspecialchars() before storing it, so 6.x data holds entities rather than
-- the characters themselves: an organisation named
--
--     Rossi & Figli S.r.l.
--
-- is stored as "Rossi &amp; Figli S.r.l." and comes back that way from every
-- read. It was the wrong escaping for the job as well -- these values are sent
-- to the registry as XML, which is now escaped at serialization instead,
-- exactly once.
--
-- Order matters: &amp; is decoded LAST. Doing it first would turn a literal
-- "&amp;lt;" -- somebody who really typed "&lt;" -- into "<". Decoding the
-- others first and & last is the exact inverse of one htmlspecialchars() pass,
-- which is what was applied. ENT_COMPAT was used, which encodes & < > and "
-- but not the single quote, so there is no &#039; to undo.
--
-- A no-op on data that has no entities: every REPLACE() simply matches
-- nothing.
-- ----------------------------------------------------------------------------

UPDATE contacts SET
  name       = REPLACE(REPLACE(REPLACE(REPLACE(name,       '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  org        = REPLACE(REPLACE(REPLACE(REPLACE(org,        '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  street     = REPLACE(REPLACE(REPLACE(REPLACE(street,     '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  street2    = REPLACE(REPLACE(REPLACE(REPLACE(street2,    '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  street3    = REPLACE(REPLACE(REPLACE(REPLACE(street3,    '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  city       = REPLACE(REPLACE(REPLACE(REPLACE(city,       '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  province   = REPLACE(REPLACE(REPLACE(REPLACE(province,   '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  postalcode = REPLACE(REPLACE(REPLACE(REPLACE(postalcode, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  voice      = REPLACE(REPLACE(REPLACE(REPLACE(voice,      '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  fax        = REPLACE(REPLACE(REPLACE(REPLACE(fax,        '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  email      = REPLACE(REPLACE(REPLACE(REPLACE(email,      '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  authinfo   = REPLACE(REPLACE(REPLACE(REPLACE(authinfo,   '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  regcode    = REPLACE(REPLACE(REPLACE(REPLACE(regcode,    '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&'),
  schoolcode = REPLACE(REPLACE(REPLACE(REPLACE(schoolcode, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&');

UPDATE domains SET
  authinfo   = REPLACE(REPLACE(REPLACE(REPLACE(authinfo,   '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&amp;', '&');


-- ----------------------------------------------------------------------------
-- PART 4: NEW TABLE - history
--
-- Unlike reminder, history has no equivalent in the dump -- there is no
-- tbl_changelog -- so this genuinely is a fresh CREATE TABLE. It records more
-- than changes: `security` rows note events that alter nothing, such as an
-- admin retrieving the registry credential.
-- Depends on `users` existing under its final name (created in Part 2), so
-- this must run after Part 2. Not dependent on Part 3's column renames.
--
-- Note: `data` uses utf8mb4_bin (not utf8mb4_unicode_ci like the rest of the
-- schema) as given -- a sensible choice here since it stores raw JSON, where
-- case-insensitive comparison/collation isn't meaningful. The CHECK
-- (json_valid(`data`)) constraint requires MariaDB 10.4.3+ (or MySQL
-- 8.0.16+, using JSON_VALID) for CHECK constraints to actually be enforced
-- rather than silently parsed-and-ignored -- your dump's server version
-- (11.8.8-MariaDB) comfortably supports this.
-- ----------------------------------------------------------------------------

CREATE TABLE `history` (
  `id`                    serial,
  `timestamp`             datetime NOT NULL DEFAULT current_timestamp(),
  `user_id`               bigint unsigned NOT NULL DEFAULT 1,
  `object`                enum('users', 'contacts', 'domains', 'security') NOT NULL,
  `object_id`             int(11) NOT NULL,
  `action`                enum('create','update','delete','read') NOT NULL,
  `data`                  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object`,`object_id`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- PART 5: EXTEND users TABLE
--
-- Widens `password` (32 -> 255 chars -- needed for modern hash formats like
-- bcrypt/argon2, which don't fit in 32 chars) and adds 9 new columns
-- (active/admin flags, TOTP 2FA secrets, session/token limits, debug level,
-- API token + its expiry), inserted with AFTER so the physical column order
-- matches the new schema.
--
-- `dns` is dropped: it is a leftover of the legacy web interface and nothing in
-- this codebase ever reads or writes it (its sibling `techc` is still used, by
-- POST /v1/domains/{name}/owner).
-- ----------------------------------------------------------------------------

ALTER TABLE users
  MODIFY COLUMN `password` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  DROP COLUMN `dns`,
  ADD COLUMN `active` TINYINT DEFAULT 1 AFTER `techc`,
  ADD COLUMN `admin` TINYINT DEFAULT 0 AFTER `active`,
  ADD COLUMN `totp_secret` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `admin`,
  ADD COLUMN `totp_secret_pending` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `totp_secret`,
  ADD COLUMN `max_token_age` INT AFTER `totp_secret_pending`,
  ADD COLUMN `max_idle_time` INT AFTER `max_token_age`,
  ADD COLUMN `debug` TINYINT DEFAULT 0 AFTER `max_idle_time`,
  ADD COLUMN `api_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `debug`,
  ADD COLUMN `api_token_expires` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `api_token`,
  ADD UNIQUE KEY (`api_token`);


-- ----------------------------------------------------------------------------
-- PART 6: POST-MIGRATION VERIFICATION
-- ----------------------------------------------------------------------------

-- 6a. Confirm every renamed table now reports utf8mb4 / utf8mb4_unicode_ci
--     at both the table default and per-column level. (history is
--     excluded from the per-column check below since `data` is
--     intentionally utf8mb4_bin, not utf8mb4_unicode_ci.)
SELECT TABLE_NAME, CCSA.CHARACTER_SET_NAME, T.TABLE_COLLATION
FROM information_schema.TABLES T
JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
  ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
WHERE T.TABLE_SCHEMA = DATABASE()
  AND T.TABLE_NAME IN ('users','contacts','domains','transfers',
                        'transactions','responses','msgqueue','messages',
                        'reminder','history');

SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','contacts','domains','transfers',
                      'transactions','responses','msgqueue','messages',
                      'reminder')
  AND CHARACTER_SET_NAME IS NOT NULL
  AND (CHARACTER_SET_NAME <> 'utf8mb4' OR COLLATION_NAME <> 'utf8mb4_unicode_ci');
-- ^ this query should return ZERO rows. Any row returned means a column
--   was missed and still has an old charset/collation.

-- 6b. Confirm the 4 DNSSEC columns are actually gone from domains.
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'domains'
  AND COLUMN_NAME IN ('dsAlgorithm','dsDigest','dsDigestType','dsKeyTag');
-- ^ this query should return ZERO rows.

-- 6c. Confirm the archived column is actually gone from messages.
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'messages'
  AND COLUMN_NAME = 'archived';
-- ^ this query should return ZERO rows.

-- 6d. Confirm no old camelCase column names remain anywhere across the 10
--     migrated tables.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','contacts','domains','transfers',
                      'transactions','responses','msgqueue','messages',
                      'reminder')
  AND BINARY COLUMN_NAME REGEXP '[A-Z]';
-- ^ this query should return ZERO rows (no upper-case characters left in
--   any column name across these 10 tables).

-- 6e. Confirm history/reminder exist with the expected shape:
--     PKs, secondary indexes, and history's data column
--     charset/collation/CHECK constraint.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('history','reminder')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('history','reminder')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
-- ^ expect: history: PRIMARY (id), object_lookup (object, object_id)
--           reminder:  PRIMARY (id), id (id, the pre-existing redundant
--                      UNIQUE KEY -- harmless, matches the pattern already
--                      present on every other original table), domain
--                      (domain), action (action)

SELECT CONSTRAINT_NAME, CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'history';
-- ^ expect one row enforcing json_valid(`data`)

-- 6f. Confirm users picked up the new columns, in the expected order, and
--     that password was actually widened to 255.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;
-- ^ expect password as varchar(255), followed by active, admin, totp_secret,
--   totp_secret_pending, max_token_age, max_idle_time, debug,
--   api_token, api_token_expires (in that order after techc), and no `dns`.

-- 6g. Confirm foreign keys survived the rename/creation and point at the
--     new names, including history's and reminder's FKs.
SELECT
    TABLE_NAME        AS child_table,
    COLUMN_NAME        AS child_column,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME AS parent_table,
    REFERENCED_COLUMN_NAME AS parent_column
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN ('users','contacts','domains','transfers',
                      'transactions','responses','msgqueue','messages',
                      'reminder','history');
-- ^ expect: contacts.user_id -> users.id
--           domains.user_id -> users.id
--           domains.registrant -> contacts.handle
--           transfers.registrant -> contacts.handle
--           history.user_id -> users.id
--           reminder.domain -> domains.domain
-- (there is deliberately no accounting table any more -- PART 2 drops it)

-- 6g-bis. No HTML entities left in stored text (expect ZERO rows).
SELECT handle, name, org
FROM contacts
WHERE name LIKE '%&amp;%' OR org LIKE '%&amp;%' OR street LIKE '%&amp;%' OR city LIKE '%&amp;%';
-- ^ a row here was encoded more than once before the migration ran. Decode it
--   again by hand after checking what it should read.

-- 6h. DATA coherence, not schema shape: report any domain whose registrant
--     contact belongs to a different local user than the domain itself.
--
--     There are two independent notions of ownership in this schema --
--     domains.user_id (who owns the domain) and contacts.user_id (who owns the
--     contact acting as its registrant) -- and the legacy 6.x code never kept
--     them in step. From 7.0.0 on they are expected to agree:
--
--       * every domain list/read/write route scopes non-admins by
--         domains.user_id, so a row where the two disagree is visible and
--         editable to the domain's owner but attributed to somebody else;
--       * the API refuses to set a registrant the caller does not own
--         (canUseAsRegistrant(), src/Api/Routes/domain.php), so an inherited mismatch
--         cannot be repaired by simply re-saving the domain -- its owner is not
--         allowed to name that contact, and the contact's owner is not allowed
--         to touch the domain.
--
--     This is deliberately a report, not a pre-flight abort: it describes data
--     that was already inconsistent before the migration, and nothing about the
--     migration itself fails because of it. Fix the rows afterwards.
SELECT
    d.domain,
    d.user_id     AS domain_owner,
    d.registrant  AS registrant_handle,
    c.user_id     AS registrant_owner
FROM domains d
JOIN contacts c ON c.handle = d.registrant
WHERE d.user_id <> c.user_id
ORDER BY d.domain;
-- ^ expect ZERO rows.
--   For each row that does come back, decide which user should really own the
--   domain and then either
--     (a) duplicate the registrant contact under the domain's owner and point
--         the domain at the copy -- POST /v1/domains/{name}/owner does exactly
--         this (contact duplication + registrant change + reassignment), or
--     (b) hand the domain to the registrant's owner:
--           UPDATE domains SET user_id = <registrant_owner> WHERE domain = '<domain>';
--   Option (b) is a single statement but moves the domain out of its current
--   owner's listings, so confirm the intent before running it.


-- ----------------------------------------------------------------------------
-- PART 7: SETTINGS TABLE + SCHEMA VERSION STAMP
--
-- The `settings` table (key/value, value JSON-validated) holds every piece
-- of application configuration except database credentials themselves (see
-- config/config.php) -- introduced after this migration was first written,
-- which is why it's appended here rather than folded into PART 4 with the
-- other new tables.
--
-- The 'schema_version' row is what Config (helpers/config.php) checks on
-- every initialization to decide whether any further
-- config/mariadb-schema-upgrade-{from}-to-{to}.sql files need to run. The
-- `settings` table not existing at all is how Config detects there's no
-- stamp yet; it then assumes the legacy pre-versioning baseline '060700'
-- and looks up config/mariadb-schema-upgrade-060700-to-*.sql -- i.e. this
-- file -- through the exact same filename-convention lookup every other
-- migration goes through, no special-casing of this file's name.
--
-- The explicit INSERT below is redundant with Config's own post-migration
-- stamp (it stamps every migration file's target version after running it,
-- this one included) -- kept anyway so running this file by hand via the
-- `mysql` CLI, without Config involved at all, still leaves `settings`
-- correctly stamped.
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `settings` (
  `key`   varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('schema_version', '"070000"')
  ON DUPLICATE KEY UPDATE `value` = '"070000"';
INSERT INTO `settings` (`key`, `value`) VALUES
  ('region', '{"timezone":"Europe/Rome","lc_monetary":"it_IT","lc_time":"italian"}'),
  ('jwt_psk', '""'),
  ('safe_networks', '["127.0.0.1/32"]'),
  ('allowed_origins', '[]'),
  ('allowed_headers', '["Authorization","Content-Type","X-Api-Key","Content-Disposition"]'),
  ('allowed_methods', '["GET","POST","PUT","PATCH","DELETE","OPTIONS"]'),
  -- lastPasswordUpdate is a unix timestamp, maintained by the passwdReminder
  -- handler run by `eppitnic poll process`: it records when an automated
  -- registry-password rotation was last attempted, so at most one is tried per
  -- 24 hours. 0 means "never attempted".
  ('epp', '{"server":"https://epp.nic.it","server_deleted":"https://epp-deleted.nic.it","port":null,"interface":"","username":"","password":"","lang":"en","cl_trid_prefix":"EPPITNIC","lastPasswordUpdate":0}'),
  ('dnssec', '{"active":0,"algorithm":10,"digesttype":2}'),
  ('debugfile', '""'),
  ('certificatefile', 'null'),
  ('cookie_dir', 'null'),
  ('pdnsutil_path', 'null'),
  ('pdnsutil_ttl', '3600')
  -- keep the seed idempotent: a partially populated `settings` table (a half
  -- finished earlier run, or a hand-seeded one) would otherwise abort the whole
  -- migration on the first duplicate key. Existing values win -- this seeds
  -- defaults, it must never overwrite something an operator has configured.
  ON DUPLICATE KEY UPDATE `value` = `settings`.`value`;
