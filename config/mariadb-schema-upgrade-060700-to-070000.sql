-- ============================================================================
-- Migration: rename tbl_* -> * and convert charset/collation to
--            utf8mb4 / utf8mb4_unicode_ci
--
-- IMPORTANT:
--   * Take a full backup: CONVERT TO CHARACTER SET rebuilds every text
--     column and is not reversible by re-running.
--   * DESTROYS DATA: tbl_accounting (PART 2, dropped in full) and
--     users.billingID (PART 3) go, since invoicing has left this codebase.
--     Export first if you want them.
--   * Decodes the HTML entities 6.x stored in text columns (PART 3):
--     "Rossi &amp; Figli" becomes "Rossi & Figli".
--   * Run via a non-interactive client that stops on the first error, e.g.
--     mysql -u USER -p DBNAME < file.sql (the default; do NOT pass --force).
--   * Test on a staging copy first.
--   * Runs with FOREIGN_KEY_CHECKS=0 so a not-yet-converted child's
--     temporary charset mismatch with an already-converted parent doesn't
--     block the ALTER -- no rows are inserted/deleted, so no bad data gets in.
--   * `handleID` (MyISAM, utf8mb3) is untouched -- not part of any target
--     schema seen so far. Confirm whether it's legacy or still needed.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- PART 1: PRE-FLIGHT CHECKS
--
-- Read-only (SELECTs only) until the final SIGNAL/no-op decision -- an
-- abort here leaves the database untouched. Covers two risks:
--
--   (a) Case-insensitive collisions in UNIQUE text columns moving to a
--       case-insensitive collation. Only tbl_domains.domain is actually at
--       risk (utf8mb3_bin, case-sensitive); the other two checks are cheap
--       insurance since they're already case-insensitive today.
--
--   (b) Data violating PART 3's stricter `reminder` shape (TEXT ->
--       VARCHAR(255), nullable `date` -> NOT NULL) or blocking its new
--       reminder.domain -> domains.domain FK (orphaned values).
--
-- Any failing check SIGNALs an error, halting the script (default `mysql`
-- CLI behaviour) before PART 2 runs.
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

    -- tbl_contacts.userID / tbl_domains.userID: PART 3 restores their FKs
    -- to users.id. A row pointing at a nonexistent user would make that
    -- ADD CONSTRAINT fail.
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

-- Drops the 3 FK constraints on a TEXT column CONVERT TO CHARACTER SET is
-- about to rebuild -- MariaDB's error 1833 blocks that in place regardless
-- of FOREIGN_KEY_CHECKS. tbl_domains_ibfk_2 and tbl_transfers_ibfk_1 are
-- recreated further down, once contacts.handle has its new charset.
ALTER TABLE tbl_domains DROP FOREIGN KEY tbl_domains_ibfk_2;
ALTER TABLE tbl_transfers DROP FOREIGN KEY tbl_transfers_ibfk_1;

-- ############################################################################
-- DESTRUCTIVE: tbl_accounting is DROPPED in full -- invoicing has left
-- this codebase and no target schema keeps it. EXPORT FIRST IF YOU WANT
-- IT; unrecoverable short of the backup already taken. Also drops
-- tbl_accounting_ibfk_1: users.billingID goes in PART 3, and a referenced
-- TEXT column can't be rebuilt (error 1833).
-- ############################################################################
DROP TABLE IF EXISTS tbl_accounting;

-- tbl_contacts_ibfk_1 and tbl_domains_ibfk_1 (userID -> users.id) are left
-- alone: both sides are BIGINT, which CONVERT TO CHARACTER SET never
-- touches, so error 1833 can't trip over them.

ALTER TABLE tbl_users
  RENAME TO users,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_contacts
  RENAME TO contacts,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tbl_domains
  RENAME TO domains,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Drops domains' 4 DNSSEC columns in one ALTER TABLE -- one metadata
-- operation instead of four separate rebuilds.
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

-- tbl_reminder holds real data, so it's renamed + converted like the other
-- tables, not created fresh (which would strand existing rows).
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
-- Renames columns from camelCase to lower_snake_case per the target
-- schema, and brings reminder the rest of the way to its target shape.
--
-- CHANGE COLUMN needs the full definition, not just the new name -- text
-- columns restate their charset so the rename can't regress Part 2's
-- work. Indexes follow a renamed column automatically and must not be
-- redeclared here.
-- ----------------------------------------------------------------------------

-- `billingID` is dropped, not renamed: nothing reads it, and PART 2
-- already dropped tbl_accounting along with the FK on this column.
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

-- `reminder` pre-dates this migration and holds real data, so it's
-- altered in place, not recreated (PART 2 only renamed + converted its
-- charset). Reorders columns, narrows `notice` to VARCHAR(255), makes
-- `date` NOT NULL, adds `action`/`created_time`, a real PRIMARY KEY, and
-- the domain -> domains.domain FK (all pre-flight checked in PART 1).
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

-- The two ownership FKs the target schema declares (contacts/domains
-- .user_id -> users.id), added only if a 6.7 dump doesn't already carry
-- them. Checked on the column pair, not a constraint name -- an existing
-- one carries whatever name InnoDB generated. Orphans are pre-flight
-- checked in PART 1, so this applies cleanly.
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
-- Contact::set()/Domain::set() ran every value through htmlspecialchars()
-- before storing it, so 6.x data holds entities instead of characters --
-- e.g. "Rossi & Figli S.r.l." is stored as "Rossi &amp; Figli S.r.l.".
-- Wrong layer anyway: these go to the registry as XML, now escaped once
-- at serialization instead.
--
-- Order matters: &amp; is decoded LAST, the exact inverse of one
-- htmlspecialchars() pass (ENT_COMPAT: no &#039; to undo). Decoding it
-- first would turn a literal "&amp;lt;" (someone who typed "&lt;") into
-- "<". A no-op where there are no entities to replace.
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
-- Unlike reminder, history has no equivalent in the dump, so this is a
-- fresh CREATE TABLE. It also holds `security` rows for non-mutating
-- events (e.g. credential reads). Must run after Part 2 (needs `users`
-- under its final name); independent of Part 3's renames.
--
-- `data` uses utf8mb4_bin, not utf8mb4_unicode_ci: it's raw JSON, where
-- case-insensitive collation is meaningless. Its CHECK (json_valid())
-- needs MariaDB 10.4.3+/MySQL 8.0.16+ to be enforced -- your dump's
-- 11.8.8-MariaDB comfortably qualifies.
-- ----------------------------------------------------------------------------

CREATE TABLE `history` (
  `id`                    serial,
  `timestamp`             datetime NOT NULL DEFAULT current_timestamp(),
  -- nullable: a login against a nonexistent username has no user to
  -- attribute it to; defaulting to user 1 would misattribute it.
  `user_id`               bigint unsigned DEFAULT NULL,
  `object`                enum('users', 'contacts', 'domains', 'security') NOT NULL,
  `object_id`             int(11) NOT NULL,
  `action`                enum('create','update','delete','read','login','denied') NOT NULL,
  -- client address masked to its rate-limit prefix (`security` rows only);
  -- own column, not JSON, so it can be indexed.
  `network`               varchar(64) DEFAULT NULL,
  `data`                  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  -- who reviewed this and when; NULL = not yet (security rows only, kept
  -- sparse here rather than a table of their own, at this size). Timestamp
  -- + user, not a flag: who acted matters too. Matches messages.archived_time.
  `acknowledged_time`     datetime DEFAULT NULL,
  `acknowledged_user_id`  bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object`,`object_id`),
  KEY `rate_limit_window` (`network`,`timestamp`),
  -- what an operator opens: the security rows nobody has looked at yet
  KEY `outstanding` (`object`,`acknowledged_time`,`timestamp`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- PART 5: EXTEND users TABLE
--
-- Widens `password` (32 -> 255 chars, for bcrypt/argon2) and adds 9
-- columns (active/admin flags, TOTP secrets, session/token limits, debug
-- level, API token + expiry), each AFTER to match the new column order.
--
-- `dns` is dropped: a legacy-UI leftover nothing reads (its sibling
-- `techc` is still used, by POST /v1/domains/{name}/owner).
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

-- 6a. Confirm every renamed table reports utf8mb4/utf8mb4_unicode_ci at
--     both table and column level (history's `data` is excluded: it's
--     intentionally utf8mb4_bin).
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
-- ^ expect ZERO rows; a row means a column still has an old charset.

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

-- 6d. Confirm no old camelCase column names remain across the 10
--     migrated tables.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','contacts','domains','transfers',
                      'transactions','responses','msgqueue','messages',
                      'reminder')
  AND BINARY COLUMN_NAME REGEXP '[A-Z]';
-- ^ expect ZERO rows.

-- 6e. Confirm history/reminder's shape: PKs, secondary indexes, and
--     history's data column charset/collation/CHECK constraint.
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
--           reminder:  PRIMARY (id), id (pre-existing redundant UNIQUE
--                      KEY, harmless), domain (domain), action (action)

SELECT CONSTRAINT_NAME, CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'history';
-- ^ expect one row enforcing json_valid(`data`)

-- 6f. Confirm users' new columns are present, in order, and password
--     was widened to 255.
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
-- ^ a row means it was double-encoded before migration; decode it again
--   by hand after checking what it should read.

-- 6h. DATA coherence: domains whose registrant contact belongs to a
--     different local user than the domain itself.
--
--     domains.user_id (the domain's owner) and contacts.user_id (the
--     registrant's owner) are independent, and 6.x never kept them in
--     step; from 7.0.0 they're expected to agree, since routes scope by
--     domains.user_id and the API refuses a registrant the caller
--     doesn't own -- so a mismatch can't be fixed by re-saving.
--
--     A report, not an abort: pre-existing data, and nothing here fails
--     because of it. Fix the rows afterwards.
SELECT
    d.domain,
    d.user_id     AS domain_owner,
    d.registrant  AS registrant_handle,
    c.user_id     AS registrant_owner
FROM domains d
JOIN contacts c ON c.handle = d.registrant
WHERE d.user_id <> c.user_id
ORDER BY d.domain;
-- ^ expect ZERO rows. For each one, decide who should own the domain:
--     (a) duplicate the registrant contact under the domain's owner and
--         repoint the domain at the copy (POST /v1/domains/{name}/owner
--         does exactly this), or
--     (b) UPDATE domains SET user_id = <owner> WHERE domain = '<domain>';
--         -- moves it out of the current owner's listings, confirm first.


-- ----------------------------------------------------------------------------
-- PART 7: SETTINGS TABLE + SCHEMA VERSION STAMP
--
-- The `settings` table (key/value, JSON-validated) holds all config
-- except DB credentials (config/config.php); appended here since it
-- postdates this migration, not folded into PART 4.
--
-- 'schema_version' is what Config checks to decide whether further
-- upgrade files need to run. No `settings` table at all means no stamp
-- yet, so Config assumes baseline '060700' and finds this file by the
-- same filename convention every migration uses.
--
-- The explicit INSERT is redundant with Config's own post-migration
-- stamp -- kept so running this file by hand (no Config involved)
-- still leaves `settings` correctly stamped.
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
  -- Proxies whose X-Forwarded-For is trusted; empty = none (else
  -- client-supplied). Also decides safe_network membership (skips MFA)
  -- and rate-limit attribution. List only your reverse proxy here.
  ('trusted_proxies', '[]'),
  -- Failed logins per network per timespan (s) before 429; per-network
  -- since IPv6 makes per-address limits useless. /48 is the usual
  -- end-site allocation (narrow via ipv4/6_prefix). 0 disables the limit.
  ('login_ratelimit', '{"max_failures":10,"timespan":900,"ipv4_prefix":24,"ipv6_prefix":48}'),
  ('allowed_origins', '[]'),
  ('allowed_headers', '["Authorization","Content-Type","X-Api-Key","Content-Disposition"]'),
  ('allowed_methods', '["GET","POST","PUT","PATCH","DELETE","OPTIONS"]'),
  -- epp.lastPasswordUpdate: unix time of the last automatic password
  -- rotation attempt (via `eppitnic poll process`), capping it to once
  -- per 24h. 0 = never attempted.
  ('epp', '{"server":"https://epp.nic.it","server_deleted":"https://epp-deleted.nic.it","port":null,"interface":"","username":"","password":"","lang":"en","cl_trid_prefix":"EPPITNIC","lastPasswordUpdate":0}'),
  ('dnssec', '{"active":0,"algorithm":10,"digesttype":2}'),
  ('debugfile', '""'),
  ('certificatefile', 'null'),
  -- keepalive: hold one registry session open across processes instead of
  -- logging out per request; refreshed by `session keepalive` before the
  -- registry's 300s timeout. session_* hold that session's state (code-only).
  ('keepalive', 'false'),
  ('session_cookies', '{}'),
  ('session_timestamp', '0'),
  ('pdnsutil_path', 'null'),
  ('pdnsutil_ttl', '3600')
  -- idempotent: a half-finished or hand-seeded `settings` table would
  -- otherwise abort on the first duplicate key. Existing values win --
  -- this seeds defaults, never overwrites an operator's configuration.
  ON DUPLICATE KEY UPDATE `value` = `settings`.`value`;
