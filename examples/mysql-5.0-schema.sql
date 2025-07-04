--
-- $Id$
--
create table tbl_transactions (
  id                    serial,
  clTRID                varchar(32),
  clTRType              varchar(32),
  clTRObject            varchar(256),
  clTRData              text
);

create table tbl_responses (
  id                    serial,
  clTRID                varchar(32),
  svTRID                varchar(32),
  svEPPCode             varchar(4),
  status                tinyint unsigned,
  svHTTPCode            smallint unsigned,
  svHTTPHeaders         text,
  svHTTPData            text,
  extValueReasonCode    varchar(4),
  extValueReason        text
);

create table tbl_msgqueue (
  id                    serial,
  clTRID                varchar(32),
  svTRID                varchar(32),
  svCode                varchar(4),
  status                tinyint unsigned,
  svHTTPCode            smallint unsigned,
  svHTTPHeaders         text,
  svHTTPData            text
);

create table tbl_domains (
  id                    serial,
  status                tinyint unsigned,
  domain                varchar(256),
  ns                    text,
  registrant            varchar(32),
  admin                 varchar(32),
  tech                  text,
  authinfo              varchar(64)
);

create table tbl_contacts (
  id                    serial,
  status                tinyint unsigned,
  handle                varchar(32),
  name                  varchar(64),
  org                   varchar(64),
  street                varchar(64),
  street2               varchar(64),
  street3               varchar(64),
  city                  varchar(64),
  province              varchar(2),
  postalcode            varchar(16),
  countrycode           varchar(2),
  voice                 varchar(64),
  fax                   varchar(64),
  email                 varchar(64),
  authinfo              varchar(64),
  consentforpublishing  tinyint unsigned,
  nationalitycode       varchar(2),
  entitytype            tinyint unsigned,
  regcode               varchar(32)
);
