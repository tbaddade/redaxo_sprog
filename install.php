<?php
$table = rex_sql_table::get(rex::getTable('sprog_wildcard'));
$table
    ->ensureColumn(new rex_sql_column('pid', 'int(11) unsigned', false, null, 'auto_increment'))
    ->setPrimaryKey('pid')
    ->ensureColumn(new rex_sql_column('id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('clang_id', 'int(11)'))
    ->ensureColumn(new rex_sql_column('wildcard', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('replace', 'text'))
    ->ensureColumn(new rex_sql_column('createuser', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('updateuser', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensureColumn(new rex_sql_column('revision', 'int(11)'))
    ->ensure();
