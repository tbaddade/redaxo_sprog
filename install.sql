CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%sprog_wildcard` (
    `pid` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `id` int(10) unsigned NOT NULL,
    `clang_id` int(10) unsigned NOT NULL,
    `wildcard` varchar(255) DEFAULT NULL,
    `replace` text,
    `createuser` varchar(255) NOT NULL,
    `updateuser` varchar(255) NOT NULL,
    `createdate` datetime NOT NULL,
    `updatedate` datetime NOT NULL,
    `revision` int(10) unsigned NOT NULL,
    PRIMARY KEY (`pid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
