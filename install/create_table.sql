DROP TABLE IF EXISTS `cumulus_files`;
CREATE TABLE `cumulus_files` (
  `fkey` varchar(40) NOT NULL DEFAULT '',
  `name` varchar(255) DEFAULT NULL,
  `path` varchar(255) NOT NULL COMMENT '"folder"-like path, starting with "/"',
  `storage_path` text NOT NULL COMMENT 'real path of the file on the disk / storage backend',
  `mimetype` varchar(127) DEFAULT NULL,
  `size` int(11) DEFAULT NULL COMMENT 'file size in bytes',
  `owner` varchar(127) DEFAULT NULL COMMENT 'identifier of the file owner if any',
  `groups` text COMMENT 'identifiers of the groups (for ex. project) if any, separated by commas (",")',
  `permissions` varchar(10) DEFAULT NULL COMMENT '@TODO set up a strategy!',
  `keywords` text COMMENT 'list of keywords, separated by commas (",")',
  `license` varchar(50) DEFAULT NULL COMMENT 'license chosen by the owner : CC-BY-SA, GPL, LPRAB, copyright...',
  `meta` text COMMENT 'free metadata JSON string; useful for license, comments...',
  `creation_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'date when the file was added - immutable',
  `last_modification_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'date when the file was modified for the last time',
  PRIMARY KEY (`fkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
