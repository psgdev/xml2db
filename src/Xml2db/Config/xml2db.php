<?php

return [

    'dumpFilePath' => storage_path('logs/xml2db'),
    'parsedXmlDbNameFixPart' => 'demo_',
    'databaseConnections' =>
        [
            'xml2db' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', 'localhost'),
                'port' => env('DB_PORT', '3306'),
                'database' => '',
                'username' => env('DB_USERNAME', 'demo_user'),
                'password' => env('DB_PASSWORD', 'demo_pass'),
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => '',
                'strict' => false,
                'engine' => null,
            ]
        ]

    ];