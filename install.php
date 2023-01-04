<?php
$table = rex_sql_table::get(rex::getTable('sprog_wildcard'));
$table
    ->ensureColumn(new rex_sql_column('pid', 'int(11) unsigned', false, null, 'AUTO_INCREMENT'))
    ->setPrimaryKey('pid')
    ->ensureColumn(new rex_sql_column('id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('clang_id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('wildcard', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('replace', 'text'))
    ->ensureGlobalColumns()
    ->ensureColumn(new rex_sql_column('revision', 'int(11)'))
    ->ensure();

$table = rex_sql_table::get(rex::getTable('sprog_abbreviation'));
$table
    ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'AUTO_INCREMENT'))
    ->setPrimaryKey('id')
    ->ensureColumn(new rex_sql_column('clang_id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('abbreviation', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('text', 'text'))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)'))
    ->ensureGlobalColumns()
    ->ensureColumn(new rex_sql_column('revision', 'int(11)'))
    ->ensureIndex(new rex_sql_index('find_abbreviations', ['clang_id', 'abbreviation'], rex_sql_index::UNIQUE))
    ->ensure();
