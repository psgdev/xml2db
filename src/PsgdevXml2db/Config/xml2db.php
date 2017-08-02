<?php

return [
    'dumpFileDirPath' => storage_path('logs'),
    'parsedXmlDbNamePrefix' => env('PARSER_DB_NAME_PREFIX', 'demo_'),
    'databaseConnections' =>
        [
            'xml2db' => [
                'driver' => 'mysql',
                'host' => env('PARSER_DB_HOST', 'localhost'),
                'port' => env('PARSER_DB_PORT', '3306'),
                'database' => '',
                'username' => env('PARSER_DB_USERNAME', 'demo_user'),
                'password' => env('PARSER_DB_PASSWORD', 'demo_pass'),
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => '',
                'strict' => false,
                'engine' => 'MYISAM'
            ]
        ]

    ];