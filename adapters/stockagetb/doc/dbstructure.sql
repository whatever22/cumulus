DROP TABLE IF EXISTS `cumulus_files` ;

CREATE  TABLE IF NOT EXISTS `cumulus_files` (
  `fkey` varchar(127) NOT NULL COMMENT 'file name in the storage - "key" is a MySQL reserved word',
  `original_name` VARCHAR(255) NOT NULL COMMENT 'original name of the file',
  `path` VARCHAR(255) NOT NULL COMMENT '"folder"-like path, starting with "/"',
  `storage_path` TEXT NOT NULL COMMENT 'real path of the file on the disk / storage backend',
  `mimetype` VARCHAR(127) NULL,
  `owner` VARCHAR(127) NULL DEFAULT NULL COMMENT 'identifier of the file owner if any',
  `groups` TEXT NULL COMMENT 'identifiers of the groups (for ex. project) if any, separated by commas (",")',
  `permissions` VARCHAR(10) NULL DEFAULT NULL COMMENT '@TODO set up a strategy!',
  `keywords` TEXT NULL DEFAULT NULL COMMENT 'list of keywords, separated by commas (",")',
  `license` VARCHAR(50) COMMENT 'license chosen by the owner : CC-BY-CA, GPL, LPRAB, copyright...',
  `meta` TEXT NULL COMMENT 'free metadata JSON string; useful for license, comments...',
  `creation_date` DATETIME NOT NULL DEFAULT NOW() COMMENT 'date when the file was added - immutable',
  `last_modification_date` DATETIME NOT NULL DEFAULT NOW() COMMENT 'date when the file was modified for the last time',
  PRIMARY KEY (`fkey`,`path`) -- WARNING, limited to 767 B so `path` and `fkey` are strongly limited
)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8
COLLATE = utf8_general_ci;