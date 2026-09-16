# Veil

[![Tests](https://github.com/signdeck/veil/actions/workflows/tests.yml/badge.svg)](https://github.com/signdeck/veil/actions/workflows/tests.yml)

Veil is a Laravel package that helps you export database **data** with anonymized sensitive columns.

It's useful when you need to:
- Share production-like data with developers or contractors
- Create safe accurate data for local or staging environments
- Debug real-world issues without exposing personal data
- Comply with privacy and data protection requirements

Veil lets you define anonymization rules per table and column, ensuring sensitive values are replaced consistently during export. The exported SQL file contains only INSERT statements, making it easy to import into an existing database that already has the schema defined via Laravel migrations.

> "This package was created and maintained by the team behind [SignDeck — a lightweight e-signature platform for collecting documents and signatures.](https://getsigndeck.com)"

## Version Compatibility

| Veil | Laravel | PHP |
|------|---------|-----|
| 2.x  | 11.x, 12.x | 8.3+ |
| 1.x  | 11.x   | 8.3+ |

### What changed in v2

Veil v2 is a complete rewrite of the export engine. The public API (your `VeilTable` classes, config, and artisan commands) is **unchanged** — no code changes are needed in your application.

**Under the hood**, v2 replaces the `mysqldump` → parse → rewrite pipeline with a direct query-builder approach:

- **Faster scoped exports** — `query()` scopes are now applied at the database level. In v1, `mysqldump` dumped all rows and filtering happened after the fact in PHP.
- **Lower memory usage** — Data is read in chunks and streamed to the output file, instead of loading the entire SQL dump into memory.
- **Fewer dependencies** — `spatie/laravel-db-snapshots` and `phpmyadmin/sql-parser` have been removed. Veil now only depends on Laravel's own Illuminate components.
- **No `mysqldump` binary required** — The export runs entirely in PHP via Laravel's query builder.

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

**Important:** If you want to use Faker or other generators to create different values per row, you **must wrap them in a closure**:

```php
// ❌ Wrong - executes once, same value for all rows
'first_name' => app(Generator::class)->firstName(),

// ✅ Correct - executes per row, different value for each row
'first_name' => fn () => app(Generator::class)->firstName(),
// or
'first_name' => fn () => fake()->firstName(),
```


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

In v2, the query scope is applied directly at the database level — only matching rows are read from the database. This makes scoped exports significantly faster on large tables compared to v1, which dumped all rows and filtered afterward.

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

### What Gets Exported?

**Veil exports data only**. It does **not** export:
- CREATE TABLE statements
- DROP TABLE statements
- ALTER TABLE statements
- Database schema definitions

**Why?** Laravel manages database schema through migrations, so Veil focuses solely on exporting and anonymizing data. This approach:
- Keeps exported files smaller and focused on data
- Aligns with Laravel's migration-based schema management
- Makes it easy to import data into existing databases that already have the schema

**To use the exported data:**
1. Ensure your target database has the schema (run migrations)
2. Import the Veil export file to populate the data

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
- `$fileName` - The filename of the created export
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

## Upgrading from v1 to v2

No changes to your application code are required. Your `VeilTable` classes, config file, and artisan commands work exactly the same.

The only steps needed:

1. Update the package:
   ```bash
   composer require signdeck/veil:^2.0
   ```

2. If you previously required `spatie/laravel-db-snapshots` solely for Veil, you can remove it:
   ```bash
   composer remove spatie/laravel-db-snapshots
   ```

3. The `mysqldump` binary is no longer required on your server.

## Security

If you discover any security related issues, please send the author an email instead of using the issue tracker.

## License

Please see the [license file](license.md) for more information.
