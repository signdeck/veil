# Veil

[![Tests](https://github.com/signdeck/veil/actions/workflows/tests.yml/badge.svg)](https://github.com/signdeck/veil/actions/workflows/tests.yml)

Veil is a Laravel package that helps you export database snapshots while anonymizing sensitive columns.

It’s useful when you need to:
- Share production-like data with developers or contractors
- Create safe database snapshots for local or staging environments
- Debug real-world issues without exposing personal data
- Comply with privacy and data protection requirements

Veil lets you define anonymization rules per table and column, ensuring sensitive values are replaced consistently during export.

It uses [spatie/laravel-db-snapshots](https://github.com/spatie/laravel-db-snapshots) under the hood and focuses on keeping the workflow simple and predictable.

> "This package was created and maintained by the team behind [SignDeck — a lightweight e-signature platform for collecting documents and signatures.](https://getsigndeck.com)"

**Supports Laravel version 11+**

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

### Using Callables for Dynamic Values

You can use closures or callables to generate unique values per row. The callable receives:
- `$original` — the original value of the column
- `$row` — an array of all column values in the current row

```php
public function columns(): array
{
    return [
        'id' => Veil::unchanged(),
        
        // Generate unique fake email for each row
        'email' => fn ($original) => fake()->unique()->safeEmail(),
        
        // Transform the original value
        'name' => fn ($original) => strtoupper($original),
        
        // Access other columns via $row parameter
        'email' => fn ($original, $row) => "user{$row['id']}@example.com",
        
        // Combine multiple column values
        'display_name' => fn ($original, $row) => "{$row['name']} (ID: {$row['id']})",
    ];
}
```

This is useful when you need unique anonymized values per row or want to reference other columns in the transformation.

### ⚠️ Foreign Key Consistency

When exporting multiple related tables, **always use `Veil::unchanged()` for primary keys and foreign keys** to maintain referential integrity.

```php
// ✅ Correct - IDs are preserved, relationships remain intact
class VeilUsersTable implements VeilTable
{
    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),      // Primary key - keep unchanged
            'name' => 'John Doe',
            'email' => 'user@example.com',
        ];
    }
}

class VeilPostsTable implements VeilTable
{
    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),      // Primary key - keep unchanged
            'user_id' => Veil::unchanged(), // Foreign key - keep unchanged
            'title' => 'Anonymized Title',
        ];
    }
}
```

```php
// ❌ Wrong - This will break foreign key relationships
class VeilUsersTable implements VeilTable
{
    public function columns(): array
    {
        return [
            'id' => fn () => fake()->randomNumber(),  // Don't anonymize IDs!
            'name' => 'John Doe',
        ];
    }
}
```

**Why?** If you change a user's `id` from `1` to `999`, all their posts with `user_id = 1` will become orphaned because the foreign key no longer matches.

**Rule of thumb:** Only anonymize data columns (names, emails, addresses), never identifier columns (IDs, UUIDs, foreign keys).

### Row Filtering (Query Scope)

You must define a `query()` method to filter which rows are exported. Return `null` to export all rows:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
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
            'id' => Veil::unchanged(),
            'email' => 'redacted@example.com',
        ];
    }

    /**
     * Only export users created in the last year.
     * Return null to export all rows.
     */
    public function query(): Builder|QueryBuilder|null
    {
        return DB::table('users')
            ->where('created_at', '>', now()->subYear());
        
        // Or return null to export all rows:
        // return null;
    }
}
```

The query should return a Laravel query builder instance that filters the rows you want to export. Return `null` to export all rows.

**Note:** Filtering is based on the primary key (usually `id`). The query is executed to get matching IDs, and only rows with those IDs are included in the export.

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

You can also specify a custom name for the export:

```bash
php artisan veil:export --name=staging-export
```

This will create `staging-export.sql` instead of the timestamped filename.

## Events

Veil fires events before and after the export process, allowing you to hook into the export lifecycle.

### Available Events

- **`SignDeck\Veil\Events\ExportStarted`** - Fired before the export begins
- **`SignDeck\Veil\Events\ExportCompleted`** - Fired after the export completes

### Listening to Events

You can listen to these events in your `EventServiceProvider`:

```php
use SignDeck\Veil\Events\ExportStarted;
use SignDeck\Veil\Events\ExportCompleted;

protected $listen = [
    ExportStarted::class => [
        // Your listeners here
    ],
    ExportCompleted::class => [
        // Your listeners here
    ],
];
```

### Event Properties

**`ExportStarted`** event contains:
- `$snapshotName` - The custom name provided (or `null` if using default)
- `$tableNames` - Array of table names being exported

**`ExportCompleted`** event contains:
- `$fileName` - The filename of the created snapshot
- `$snapshotName` - The custom name provided (or `null` if using default)
- `$tableNames` - Array of table names that were exported

### Example: Logging Exports

```php
use SignDeck\Veil\Events\ExportCompleted;
use Illuminate\Support\Facades\Log;

class LogExportCompleted
{
    public function handle(ExportCompleted $event): void
    {
        Log::info('Database export completed', [
            'file' => $event->fileName,
            'tables' => $event->tableNames,
        ]);
    }
}
```

## Dry Run Mode

You can preview what would be exported without actually creating the file:

```bash
php artisan veil:export --dry-run
```

This will show:
- Which tables will be exported
- Which columns will be included
- Estimated row counts
- What filename would be created

No files are created in dry-run mode, making it safe to test your configuration.

## Security

If you discover any security related issues, please send the author an email instead of using the issue tracker.

## License

Please see the [license file](license.md) for more information.
