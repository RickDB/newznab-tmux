ALTER TABLE users ADD COLUMN style VARCHAR(255) NULL DEFAULT NULL;
/* Add a column to pick a site theme */

UPDATE `site` SET `value` = '119' WHERE `setting` = 'sqlpatch';
