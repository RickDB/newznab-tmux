ALTER TABLE prehash ADD COLUMN searched tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE prehash ADD INDEX ix_prehash_searched (searched);
ALTER TABLE releases DROP COLUMN proc_filenames;

UPDATE `site` SET `value` = '39' WHERE `setting` = 'sqlpatch';