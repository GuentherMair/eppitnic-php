CREATE TABLE `users` (
  `id`                    serial,
  `description`           varchar(64),
  `username`              varchar(32),
  `password`              varchar(255),
  `email`                 varchar(64),
  `max_operations`        int DEFAULT 0,
  `techc`                 text,
  `active`                tinyint DEFAULT 1,
  `admin`                 tinyint DEFAULT 0,
  `totp_secret`           varchar(64),
  `totp_secret_pending`   varchar(64),
  `max_token_age`         int,
  `max_idle_time`         int,
  `debug`                 tinyint    DEFAULT 0,
  `api_token`             varchar(64),
  `api_token_expires`     bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`api_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Not only changes: `security` rows record events that alter nothing, such as
-- an admin retrieving the registry credential, which is why this is `history`
-- rather than `changelog`. Those carry `action` = 'read'.
CREATE TABLE `history` (
  `id`                    serial,
  `timestamp`             datetime NOT NULL DEFAULT current_timestamp(),
  -- nullable: a login attempt at a username that does not exist has nobody
  -- to attribute it to, and defaulting it to user 1 would put a false entry
  -- in the trail
  `user_id`               bigint unsigned DEFAULT NULL,
  `object`                enum('users', 'contacts', 'domains', 'security') NOT NULL,
  `object_id`             int(11) NOT NULL,
  `action`                enum('create','update','delete','read','login','denied') NOT NULL,
  -- the client's address masked to its rate-limiting prefix, for `security`
  -- rows only. Its own column rather than a field inside `data` because the
  -- login rate limit reads it on every authentication attempt, and an index
  -- cannot reach inside JSON.
  `network`               varchar(64) DEFAULT NULL,
  `data`                  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  -- Who has reviewed this and when; NULL means nobody yet. Only `security`
  -- rows are meant to be worked through, but the columns live here rather than
  -- in a table of their own -- as `network` does -- because one sparse column
  -- pair is cheaper than a join, at this size. A timestamp and a user rather
  -- than a flag: for a security log, who dismissed an alert matters as much as
  -- that somebody did. Matches `messages`.`archived_time`.
  `acknowledged_time`     datetime DEFAULT NULL,
  `acknowledged_user_id`  bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object`,`object_id`),
  KEY `rate_limit_window` (`network`,`timestamp`),
  -- what an operator opens: the security rows nobody has looked at yet
  KEY `outstanding` (`object`,`acknowledged_time`,`timestamp`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transactions` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `cl_trtype`             varchar(32),
  `cl_trobject`           varchar(256),
  `cl_trdata`             text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `responses` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `sv_trid`               varchar(64),
  `sv_code`               varchar(4),
  `status`                tinyint unsigned,
  `sv_httpcode`           smallint unsigned,
  `sv_httpheaders`        text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  `sv_httpdata`           text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  `extvaluereasoncode`    varchar(4),
  `extvaluereason`        text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `msgqueue` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `sv_trid`               varchar(64),
  `sv_code`               varchar(4),
  `status`                tinyint unsigned,
  `sv_httpcode`           smallint unsigned,
  `sv_httpheaders`        text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  `sv_httpdata`           text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contacts` (
  `id`                    serial,
  `user_id`               bigint unsigned NOT NULL DEFAULT 1,
  `status`                text,
  `handle`                varchar(32) unique NOT NULL,
  `name`                  varchar(256),
  `org`                   varchar(256),
  `street`                varchar(256),
  `street2`               varchar(128),
  `street3`               varchar(128),
  `city`                  varchar(128),
  `province`              varchar(128),
  `postalcode`            varchar(16),
  `countrycode`           varchar(2),
  `voice`                 varchar(64),
  `fax`                   varchar(64),
  `email`                 varchar(64),
  `authinfo`              varchar(64),
  `consentforpublishing`  tinyint unsigned,
  `nationalitycode`       varchar(2),
  `entitytype`            tinyint unsigned,
  `regcode`               varchar(32),
  `schoolcode`            varchar(32),
  `active`                tinyint DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY (`handle`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `domains` (
  `id`                    serial,
  `user_id`               bigint unsigned NOT NULL DEFAULT 1,
  `active`                tinyint DEFAULT 1,
  `status`                text,
  `domain`                varchar(255) unique NOT NULL,
  `authinfo`              varchar(64),
  `ns`                    text,
  `registrant`            varchar(32) NOT NULL,
  `admin`                 varchar(32),
  `tech`                  text,
  `cr_date`               date,
  `ex_date`               date,
  `dnssec`                text,
  `last_invoice`          timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfers` (
  `id`                    serial,
  `user_id`               bigint unsigned NOT NULL DEFAULT 1,
  `domain`                varchar(255) unique NOT NULL,
  `techc`                 text,
  `dns`                   text,
  `registrant`            varchar(32) NOT NULL,
  `time`                  timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `sv_trid`               varchar(64),
  `type`                  varchar(64) NOT NULL,
  `domain`                varchar(255),
  `ac_id`                 varchar(255),
  `re_id`                 varchar(255),
  `data`                  text NOT NULL,
  `archived_time`         datetime DEFAULT NULL,
  `archived_user_id`      bigint unsigned,
  `created_time`          timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
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

CREATE TABLE `settings` (
  `key`   varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('schema_version', '"070000"'),
  ('region', '{"timezone":"Europe/Rome","lc_monetary":"it_IT","lc_time":"italian"}'),
  ('jwt_psk', '""'),
  ('safe_networks', '["127.0.0.1/32"]'),
  -- Proxies whose X-Forwarded-For is believed. Empty means none: the header is
  -- client-supplied, and this decides both who counts as being on a
  -- safe_network (which skips MFA) and which network a failed login is counted
  -- against. List your reverse proxy here, and nothing else.
  ('trusted_proxies', '[]'),
  -- Failed logins allowed per network per timespan (seconds) before the login
  -- endpoint answers 429. Counted per network rather than per address: an IPv6
  -- customer gets a whole allocation, so a per-address limit would stop nobody.
  -- /48 is the usual end-site assignment and is what an attacker would have to
  -- rotate within; /56 or /64 narrow the bucket if blocking a whole site is too
  -- blunt for your users. max_failures of 0 disables the limit.
  ('login_ratelimit', '{"max_failures":10,"timespan":900,"ipv4_prefix":24,"ipv6_prefix":48}'),
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
  ('pdnsutil_ttl', '3600');
