CREATE TABLE `users` (
  `id`                    serial,
  `billing_id`            varchar(64) unique NOT NULL,
  `description`           varchar(64),
  `username`              varchar(32),
  `password`              varchar(32),
  `email`                 varchar(64),
  `max_operations`        int DEFAULT 0,
  `dns`                   text,
  `techc`                 text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `transactions` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `cl_trtype`             varchar(32),
  `cl_trobject`           varchar(256),
  `cl_trdata`             text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `responses` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `sv_trid`               varchar(64),
  `sv_code`               varchar(4),
  `status`                tinyint unsigned,
  `sv_httpcode`           smallint unsigned,
  `sv_httpheaders`        text,
  `sv_httpdata`           text,
  `extvaluereasoncode`    varchar(4),
  `extvaluereason`        text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `msgqueue` (
  `id`                    serial,
  `cl_trid`               varchar(32),
  `sv_trid`               varchar(64),
  `sv_code`               varchar(4),
  `status`                tinyint unsigned,
  `sv_httpcode`           smallint unsigned,
  `sv_httpheaders`        text,
  `sv_httpdata`           text
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
