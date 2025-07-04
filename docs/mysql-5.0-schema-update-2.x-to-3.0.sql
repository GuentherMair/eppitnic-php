--
-- $Id$
--
create table tbl_users (
  id                    serial,
  billingID             varchar(64) unique NOT NULL,
  description           varchar(64),
  username              varchar(32),
  password              varchar(32),
  email                 varchar(64),
  dns                   text,
  techc                 text,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8;

insert into tbl_users (billingID, description, username, password) values ('defaultBillingID', 'Default User', 'system', 'X');

alter table tbl_contacts ADD PRIMARY KEY (id);
alter table tbl_contacts ADD KEY (handle);
alter table tbl_contacts ADD column userID bigint unsigned NOT NULL DEFAULT 1;
alter table tbl_contacts ADD column active tinyint DEFAULT 1;
alter table tbl_contacts MODIFY handle varchar(32) unique NOT NULL;
alter table tbl_contacts MODIFY province varchar(128);
alter table tbl_contacts ADD CONSTRAINT FOREIGN KEY (userID) REFERENCES tbl_users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

alter table tbl_domains ADD PRIMARY KEY (id);
alter table tbl_domains ADD column userID bigint unsigned NOT NULL DEFAULT 1;
alter table tbl_domains ADD column lastInvoice timestamp DEFAULT CURRENT_TIMESTAMP;
alter table tbl_domains ADD column active tinyint DEFAULT 1;
alter table tbl_domains MODIFY registrant varchar(32) NOT NULL;
alter table tbl_domains MODIFY domain varchar(255) unique NOT NULL;
alter table tbl_domains ADD CONSTRAINT FOREIGN KEY (userID) REFERENCES tbl_users(id) ON DELETE RESTRICT ON UPDATE CASCADE;
alter table tbl_domains ADD CONSTRAINT FOREIGN KEY (registrant) REFERENCES tbl_contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE;

create table tbl_transfers (
  id                    serial,
  domain                varchar(255) unique NOT NULL,
  registrant            varchar(32) NOT NULL,
  time                  timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES tbl_contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8;

create table tbl_messages (
  id                    serial,
  type                  varchar(64) NOT NULL,
  domain                varchar(255),
  data                  text NOT NULL,
  archived              tinyint DEFAULT 0,
  archivedUserID        bigint unsigned,
  archivedTime          datetime,
  createdTime           timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8;
