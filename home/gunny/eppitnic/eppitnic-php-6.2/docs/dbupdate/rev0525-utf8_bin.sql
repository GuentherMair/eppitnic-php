SET foreign_key_checks = 0;
ALTER TABLE tbl_domains MODIFY `domain` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL;
ALTER TABLE tbl_transfers MODIFY `domain` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL;
SET foreign_key_checks = 1;
