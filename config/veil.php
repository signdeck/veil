<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Veiled Tables
    |--------------------------------------------------------------------------
    |
    | This array should contain the list of veiled classes that 
    | will be used to export and anonymize the table columns
    |
    */
    'tables' => [
        // \App\Veil\VeilUsersTable::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk where snapshots will be stored. Make sure this
    | disk is configured in your config/filesystems.php file.
    |
    */
    'disk' => env('VEIL_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | When enabled, the exported SQL file will be compressed using gzip.
    |
    */
    'compress' => env('VEIL_COMPRESS', false),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for exporting. Set to null to use
    | the default connection configured in config/database.php.
    |
    */
    'connection' => env('VEIL_CONNECTION', null),
];