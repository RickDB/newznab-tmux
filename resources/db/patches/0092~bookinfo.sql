ALTER TABLE `bookinfo` ADD  `overview` VARCHAR(3000) DEFAULT NULL;
ALTER TABLE `bookinfo` ADD  `genre` VARCHAR(255) NOT NULL;
UPDATE `tmux` SET `value` = '92' WHERE `setting` = 'sqlpatch';