-- ============================================================================
-- Migration: rename tbl_* -> * and convert charset/collation to
--            utf8mb4 / utf8mb4_unicode_ci
--
-- IMPORTANT:
--   * Take a full backup before running this. CONVERT TO CHARACTER SET
--     rebuilds every text column and is not reversible by re-running.
--   * Run this via a non-interactive client that stops on the first error,
--     e.g.:  mysql -u USER -p DBNAME < migrate_rename_charset.sql
--     (this is the default `mysql` CLI behaviour; do NOT pass --force)
--   * Test on a staging copy first.
--   * Tables are converted with FOREIGN_KEY_CHECKS=0 so that the temporary
--     charset mismatch between a not-yet-converted child and an
--     already-converted parent doesn't block the ALTER. No FK checks are
--     skipped that would let bad data in -- no rows are inserted/deleted
--     by this script, only column/table metadata + rebuild.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- PART 1: PRE-FLIGHT CHECK
--
-- utf8_bin (current, assumed) is case-sensitive. utf8mb4_unicode_ci (target)
-- is case-insensitive. Any UNIQUE column that currently holds values differing
-- only by case (e.g. 'Foo' and 'foo') will collide once the collation
-- changes, and CONVERT TO CHARACTER SET will fail (or worse, silently need
-- manual resolution) partway through the migration.
--
-- This checks every UNIQUE text column across the 8 tables. If any collision
-- is found, it SIGNALs an error, which — under default `mysql` CLI settings —
-- halts the script immediately, before Part 2 runs.
-- ----------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS `_check_case_collisions`;

DELIMITER $$

CREATE PROCEDURE `_check_case_collisions`()
BEGIN
    DECLARE cnt INT DEFAULT 0;
    DECLARE msg VARCHAR(255);

    -- tbl_users.billingID (unique, NOT NULL)
    SELECT COUNT(*) INTO cnt FROM (
        SELECT LOWER(billingID) AS k
        FROM tbl_users
        GROUP BY LOWER(billingID)
        HAVING COUNT(*) > 1
    ) x;
    IF cnt > 0 THEN
        SET msg = CONCAT('Abort: tbl_users.billingID has ', cnt,
                          ' case-insensitive collision group(s). Resolve before migrating.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

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

    -- tbl_domains.domain (unique, NOT NULL)
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

    SELECT 'PRE-FLIGHT OK: no case-insensitive collisions found in unique columns.' AS result;
END$$

DELIMITER ;

CALL `_check_case_collisions`();
DROP PROCEDURE `_check_case_collisions`;

-- If you got here without an error above, it's safe to proceed to Part 2.


-- ----------------------------------------------------------------------------
-- PART 2: RENAME + CONVERT
-- ----------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;


-- ----------------------------------------------------------------------------
-- PART 3: COLUMN NAME CORRECTIONS
--
-- Renames columns from the original camelCase names to the corrected
-- lower_snake_case names, per the target schema supplied.
--
-- CHANGE COLUMN requires the full column definition, not just the new name,
-- so each clause restates the existing type/nullability/default. Text
-- columns explicitly restate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
-- so the rename can't accidentally regress the charset work done in Part 2.
-- Existing indexes (UNIQUE, PRIMARY KEY, FOREIGN KEY) automatically follow a
-- renamed column -- they do not need to be, and must not be, redeclared here.
-- ----------------------------------------------------------------------------

ALTER TABLE users
  CHANGE COLUMN `billingID` `billing_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  CHANGE COLUMN `maxOperations` `max_operations` INT DEFAULT 0;

ALTER TABLE transactions
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `clTRType` `cl_trtype` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `clTRObject` `cl_trobject` VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `clTRData` `cl_trdata` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE responses
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svTRID` `sv_trid` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svEPPCode` `sv_code` VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svHTTPCode` `sv_httpcode` SMALLINT UNSIGNED,
  CHANGE COLUMN `svHTTPHeaders` `sv_httpheaders` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svHTTPData` `sv_httpdata` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `extValueReasonCode` `extvaluereasoncode` VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `extValueReason` `extvaluereason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE msgqueue
  CHANGE COLUMN `clTRID` `cl_trid` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svTRID` `sv_trid` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svCode` `sv_code` VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svHTTPCode` `sv_httpcode` SMALLINT UNSIGNED,
  CHANGE COLUMN `svHTTPHeaders` `sv_httpheaders` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  CHANGE COLUMN `svHTTPData` `sv_httpdata` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

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


-- ----------------------------------------------------------------------------
-- PART 4: NEW TABLES - changelog, reminder, accounting
--
-- changelog and reminder both carry a FOREIGN KEY into tables created in
-- Part 2 (users, domains respectively), so this whole part must run after
-- Part 2. Not dependent on Part 3's column renames.
--
-- Note: `changelog.data` uses utf8mb4_bin (not utf8mb4_unicode_ci like the
-- rest of the schema) as given -- a sensible choice here since it stores raw
-- JSON, where case-insensitive comparison/collation isn't meaningful. The
-- CHECK (json_valid(`data`)) constraint requires MariaDB 10.4.3+ (or MySQL
-- 8.0.16+, using JSON_VALID) for CHECK constraints to actually be enforced
-- rather than silently parsed-and-ignored -- worth confirming your server
-- version supports enforced CHECK constraints before relying on it.
--
-- Note: `accounting.billing_id` has no FOREIGN KEY back to users.billing_id,
-- unlike reminder.domain -> domains.domain. Given as specified -- if
-- billing_id here is meant to always match a real user's billing_id, that's
-- currently only enforced at the application layer, not the database.
-- ----------------------------------------------------------------------------

CREATE TABLE `changelog` (
  `id`                    serial,
  `timestamp`             datetime NOT NULL DEFAULT current_timestamp(),
  `user_id`               bigint unsigned NOT NULL DEFAULT 1,
  `object`                enum('users', 'contacts', 'domains') NOT NULL,
  `object_id`             int(11) NOT NULL,
  `action`                enum('create','update','delete') NOT NULL,
  `data`                  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object`,`object_id`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reminder` (
  `id`                    serial,
  `domain`                varchar(255) NOT NULL,
  `date`                  date NOT NULL,
  `notice`                varchar(255),
  `email`                 varchar(64),
  `action`                enum('create','update','delete'),
  `active`                tinyint DEFAULT 1,
  `created_time`          timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY (`domain`),
  KEY (`action`),
  CONSTRAINT FOREIGN KEY (domain) REFERENCES domains(domain) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accounting` (
  `id`                    serial,
  `operation`             varchar(64) NOT NULL,
  `billing_id`            varchar(64) NOT NULL,
  `object`                varchar(255) NOT NULL,
  `date`                  date NOT NULL,
  `time`                  timestamp DEFAULT CURRENT_TIMESTAMP,
  `status`                tinyint DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY (`billing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- PART 5: EXTEND users TABLE
--
-- Widens `password` (32 -> 255 chars -- needed for modern hash formats like
-- bcrypt/argon2, which don't fit in 32 chars) and adds 7 new columns
-- (active/admin flags, TOTP 2FA secrets, session/token limits, debug level),
-- inserted with AFTER so the physical column order matches the new schema.
-- ----------------------------------------------------------------------------

ALTER TABLE users
  MODIFY COLUMN `password` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  ADD COLUMN `active` TINYINT DEFAULT 1 AFTER `techc`,
  ADD COLUMN `admin` TINYINT DEFAULT 0 AFTER `active`,
  ADD COLUMN `totp_secret` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `admin`,
  ADD COLUMN `totp_secret_pending` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `totp_secret`,
  ADD COLUMN `max_token_age` INT AFTER `totp_secret_pending`,
  ADD COLUMN `max_idle_time` INT AFTER `max_token_age`,
  ADD COLUMN `debug_level` TINYINT AFTER `max_idle_time`,
  ADD COLUMN `api_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci AFTER `debug_level`,
  ADD COLUMN `api_token_expires` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `api_token`,
  ADD UNIQUE KEY (`api_token`);


-- ----------------------------------------------------------------------------
-- PART 6: POST-MIGRATION VERIFICATION
-- ----------------------------------------------------------------------------

-- 6a. Confirm every renamed table now reports utf8mb4 / utf8mb4_unicode_ci
--     at both the table default and per-column level. (changelog is
--     excluded from the per-column check below since `data` is
--     intentionally utf8mb4_bin, not utf8mb4_unicode_ci.)
SELECT TABLE_NAME, CCSA.CHARACTER_SET_NAME, T.TABLE_COLLATION
FROM information_schema.TABLES T
JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
  ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
WHERE T.TABLE_SCHEMA = DATABASE()
  AND T.TABLE_NAME IN ('users','contacts','domains','transfers',
                        'transactions','responses','msgqueue','messages',
                        'changelog','reminder','accounting');

SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','contacts','domains','transfers',
                      'transactions','responses','msgqueue','messages')
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

-- 6d. Confirm no old camelCase column names remain anywhere in the 8
--     original tables.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','contacts','domains','transfers',
                      'transactions','responses','msgqueue','messages')
  AND BINARY COLUMN_NAME REGEXP '[A-Z]';
-- ^ this query should return ZERO rows (no upper-case characters left in
--   any column name across these 8 tables).

-- 6e. Confirm changelog/reminder/accounting exist with the expected shape:
--     PKs, secondary indexes, and changelog's data column
--     charset/collation/CHECK constraint.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('changelog','reminder','accounting')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('changelog','reminder','accounting')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
-- ^ expect: changelog: PRIMARY (id), object_lookup (object, object_id)
--           reminder:  PRIMARY (id), domain (domain)
--           accounting: PRIMARY (id), billing_id (billing_id)

SELECT CONSTRAINT_NAME, CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'changelog';
-- ^ expect one row enforcing json_valid(`data`)

-- 6f. Confirm users picked up the new columns, in the expected order, and
--     that password was actually widened to 255.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;
-- ^ expect password as varchar(255), followed by active, admin, totp_secret,
--   totp_secret_pending, max_token_age, max_idle_time, debug_level (in
--   that order after techc).

-- 6g. Confirm foreign keys survived the rename/creation and point at the
--     new names, including changelog's and reminder's new FKs.
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
                      'changelog','reminder','accounting');
-- ^ expect: contacts.user_id -> users.id
--           domains.user_id -> users.id
--           domains.registrant -> contacts.handle
--           transfers.registrant -> contacts.handle
--           changelog.user_id -> users.id
--           reminder.domain -> domains.domain
-- (accounting has no FK by design -- see note above PART 4)


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
  ('allowed_origins', '[""]'),
  ('allowed_headers', '["Authorization","Content-Type","X-Api-Key","Content-Disposition"]'),
  ('allowed_methods', '["GET","POST","PUT","PATCH","DELETE","OPTIONS"]'),
  ('epp', '{"server":"https://epp.nic.it","server_deleted":"https://epp-deleted.nic.it","port":null,"interface":"","username":"","password":"","passwordexpirydays":120,"passwordexpirynext":1234567890,"lang":"en","cl_trid_prefix":"EPPITNIC"}'),
  ('dnssec', '{"active":0,"algorithm":10,"digesttype":2}'),
  ('smarty', '{"use_sub_dirs":null,"template_dir":null,"config_dir":null,"compile_dir":null,"cache_dir":null}'),
  ('debug', 'false'),
  ('debugfile', '""'),
  ('certificatefile', 'null'),
  ('cookie_dir', 'null'),
  ('pdnsutil_path', 'null'),
  ('pdnsutil_ttl', '3600');
