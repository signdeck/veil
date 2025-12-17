# Veil


A Laravel package that helps export and anonymise database columns. Uses [spatie/laravel-db-snapshots](https://github.com/spatie/laravel-db-snapshots) under the hood.

**Supports Laravel version 11+**

> "This package was created and maintained by the team behind [SignDeck — a lightweight e-signature platform for collecting documents and signatures.](https://getsigndeck.com)"


## Installation

You can install the package via Composer:

```bash
composer require signdeck/veil
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=veil-config
```

This will create a `config/veil.php` file where you can configure your export settings.

Make sure you also have a filesystem disk configured for storing exports. By default, Veil uses the `local` disk.

## Usage

### 1. Create a Veil Table Class

Generate a new Veil table class using the artisan command:

```bash
php artisan veil:make-table users
```

This creates `app/Veil/VeilUsersTable.php`:

```php
<?php

namespace App\Veil;

use SignDeck\Veil\Veil;
use SignDeck\Veil\Contracts\VeilTable;

class VeilUsersTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),     // Keep original value
            'email' => 'user@example.com', // Replace with this value
        ];
    }
}
```

### 2. Define Columns to Export

In the `columns()` method, specify which columns to include in the export:

```php
public function columns(): array
{
    return [
        'id' => Veil::unchanged(),          // Keep original value
        'name' => 'John Doe',               // Replace all names with "John Doe"
        'email' => 'redacted@example.com',  // Replace all emails
        'phone' => '000-000-0000',          // Replace all phone numbers
        // 'password' - not listed, so it won't be exported
    ];
}
```

**Important:** Only columns defined in `columns()` will be included in the export. Any columns not listed will be excluded from the exported SQL.

### 3. Register Your Tables

Add your Veil table classes to `config/veil.php`:

```php
'tables' => [
    \App\Veil\VeilUsersTable::class,
    \App\Veil\VeilOrdersTable::class,
    // Add more tables as needed
],
```

### 4. Run the Export

Execute the export command:

```bash
php artisan veil:export
```

This will create a timestamped SQL file (e.g., `veil_2025-01-15_10-30-00.sql`) on your configured disk with all specified tables and anonymized column values.

## Configuration

```php
// config/veil.php

return [
    // Tables to export
    'tables' => [],

    // Filesystem disk for storing exports (default: 'local')
    'disk' => env('VEIL_DISK', 'local'),

    // Compress the export with gzip (default: false)
    'compress' => env('VEIL_COMPRESS', false),

    // Database connection to use (default: null uses default connection)
    'connection' => env('VEIL_CONNECTION', null),
];
```

## Security

If you discover any security related issues, please send the author an email instead of using the issue tracker.

## License

Please see the [license file](license.md) for more information.
