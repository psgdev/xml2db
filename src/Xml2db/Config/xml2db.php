<?php

return [

    'dumpFilePath' => storage_path('logs/xml2db'),
    'parsedXmlDbNameFixPart' => 'demo_',
    'databaseConnections' =>
        [
            'xml2db' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '192.168.1.15'),
                'port' => env('DB_PORT', '3306'),
                'database' => '',
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', 'jkl987'),
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => '',
                'strict' => false,
                'engine' => null,
            ]
        ]

    ];