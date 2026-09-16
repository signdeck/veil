<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Storage;
use SignDeck\Veil\AsIs;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Exceptions\ContractImplementationException;
use SignDeck\Veil\Tests\Tables\AnonymizedVeilTable;
use SignDeck\Veil\Tests\Tables\CallableVeilTable;
use SignDeck\Veil\Tests\Tables\CallableWithOriginalValueVeilTable;
use SignDeck\Veil\Tests\Tables\EmptyColumnsVeilTable;
use SignDeck\Veil\Tests\Tables\FilteredVeilTable;
use SignDeck\Veil\Tests\Tables\MultiColumnRowAccessVeilTable;
use SignDeck\Veil\Tests\Tables\NullableColumnsVeilTable;
use SignDeck\Veil\Tests\Tables\NumericAnonymizedVeilTable;
use SignDeck\Veil\Tests\Tables\PartialColumnsVeilTable;
use SignDeck\Veil\Tests\Tables\QuotedValueVeilTable;
use SignDeck\Veil\Tests\Tables\RowAccessVeilTable;
use SignDeck\Veil\Tests\Tables\TestVeilUsersTable;
use SignDeck\Veil\Tests\Tables\UnchangedColumnsVeilTable;
use SignDeck\Veil\Tests\TestCase;
use SignDeck\Veil\Veil;
use SignDeck\Veil\VeilDryRun;

class VeilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('veil');
    }

    /** @test */
    public function it_returns_null_when_no_tables_configured(): void
    {
        config(['veil.tables' => []]);

        $veil = app(Veil::class);
        $result = $veil->handle();

        $this->assertNull($result);
    }

    /** @test */
    public function it_creates_export_file(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $veil = app(Veil::class);
        $fileName = $veil->handle();

        $this->assertNotNull($fileName);
        $this->assertStringStartsWith('veil_', $fileName);
        $this->assertStringEndsWith('.sql', $fileName);
        $this->assertTrue(Storage::disk('veil')->exists($fileName));
    }

    /** @test */
    public function it_uses_custom_snapshot_name_when_provided(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $veil = app(Veil::class);
        $fileName = $veil->handle('custom-export-name');

        $this->assertNotNull($fileName);
        $this->assertStringContainsString('custom-export-name', $fileName);
        $this->assertStringEndsWith('.sql', $fileName);
        $this->assertTrue(Storage::disk('veil')->exists($fileName));
    }

    /** @test */
    public function it_returns_preview_array_in_dry_run_mode(): void
    {
        $this->seedUsers(3);

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $veil = app(Veil::class);
        $veilDryRun = app(VeilDryRun::class);
        $preview = $veilDryRun->preview();

        $this->assertIsArray($preview);
        $this->assertNotEmpty($preview);
        $this->assertArrayHasKey('name', $preview[0]);
        $this->assertArrayHasKey('columns', $preview[0]);
        $this->assertArrayHasKey('row_count', $preview[0]);
        $this->assertEquals('users', $preview[0]['name']);
        $this->assertEquals(3, $preview[0]['row_count']);

        // Verify no file was created
        $files = Storage::disk('veil')->files();
        $this->assertEmpty($files);
    }

    /** @test */
    public function it_only_exports_columns_defined_in_veil_table(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [PartialColumnsVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Should contain only id and email columns in INSERT
        $this->assertStringContainsString('`id`', $result);
        $this->assertStringContainsString('`email`', $result);

        // Should NOT contain name or password columns in INSERT
        $this->assertStringNotContainsString('`name`', $result);
        $this->assertStringNotContainsString('`password`', $result);
    }

    /** @test */
    public function it_anonymizes_columns_with_specified_values(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [AnonymizedVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Original emails should be replaced
        $this->assertStringNotContainsString('user1@example.com', $result);
        $this->assertStringNotContainsString('user2@example.com', $result);

        // Should contain the anonymized value
        $this->assertStringContainsString('redacted@example.com', $result);
    }

    /** @test */
    public function it_keeps_original_values_when_using_veil_unchanged(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [UnchangedColumnsVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Original IDs should be preserved (1, 2, 3)
        $this->assertStringContainsString('(1,', $result);
        $this->assertStringContainsString('(2,', $result);
        $this->assertStringContainsString('(3,', $result);

        // Email should be anonymized
        $this->assertStringContainsString('anon@test.com', $result);
    }

    /** @test */
    public function it_preserves_null_values(): void
    {
        // Insert a row with NULL created_at
        $this->app['db']->table('users')->insert([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'secret',
            'created_at' => null,
            'updated_at' => null,
        ]);

        config(['veil.tables' => [NullableColumnsVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // NULL values should be preserved
        $this->assertStringContainsString('NULL', $result);
    }

    /** @test */
    public function it_handles_multiple_rows(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $result = $this->exportAndGetContents();

        // Should have INSERT statement
        $this->assertStringContainsString('INSERT INTO `users`', $result);

        // Should have the anonymized email for each row
        $this->assertStringContainsString('test@example.com', $result);
    }

    /** @test */
    public function it_handles_numeric_values_correctly(): void
    {
        $this->seedUsers(1);

        config(['veil.tables' => [NumericAnonymizedVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Numeric value should not be quoted
        $this->assertStringContainsString('(999)', $result);
    }

    /** @test */
    public function it_escapes_single_quotes_in_anonymized_values(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [QuotedValueVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Single quote should be escaped
        $this->assertStringContainsString("O\\'Brien", $result);
    }

    /** @test */
    public function it_throws_exception_for_non_veil_table_class(): void
    {
        config(['veil.tables' => [InvalidVeilTable::class]]);

        $this->expectException(ContractImplementationException::class);

        $veil = app(Veil::class);
        $veil->handle();
    }

    /** @test */
    public function veil_unchanged_returns_asis_instance(): void
    {
        $result = Veil::unchanged();

        $this->assertInstanceOf(AsIs::class, $result);
    }

    /** @test */
    public function it_produces_empty_file_when_columns_array_is_empty(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [EmptyColumnsVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // No INSERT statements should be generated for empty columns
        $this->assertStringNotContainsString('INSERT INTO `users`', $result);
    }

    /** @test */
    public function it_executes_callable_values(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [CallableVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // The callable should transform emails to uppercase
        $this->assertStringContainsString('USER1@EXAMPLE.COM', $result);
        $this->assertStringContainsString('USER2@EXAMPLE.COM', $result);
        $this->assertStringContainsString('USER3@EXAMPLE.COM', $result);
    }

    /** @test */
    public function callable_receives_original_value(): void
    {
        $this->app['db']->table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret',
        ]);

        config(['veil.tables' => [CallableWithOriginalValueVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // The callable should append to the original value
        $this->assertStringContainsString('john@example.com.redacted', $result);
    }

    /** @test */
    public function callable_can_return_different_values_per_row(): void
    {
        $this->seedUsers();

        $counter = 0;
        $veilTable = new class($counter) implements VeilTable {
            private int $counter;

            public function __construct(int &$counter)
            {
                $this->counter = &$counter;
            }

            public function table(): string
            {
                return 'users';
            }

            public function columns(): array
            {
                return [
                    'id' => function ($original) {
                        static $count = 100;
                        return $count++;
                    },
                ];
            }

            public function query(): Builder|QueryBuilder|null
            {
                return null;
            }
        };

        // Bind the anonymous class so it can be resolved
        app()->instance(get_class($veilTable), $veilTable);
        config(['veil.tables' => [get_class($veilTable)]]);

        $result = $this->exportAndGetContents();

        // Each row should have a different ID
        $this->assertStringContainsString('(100)', $result);
        $this->assertStringContainsString('(101)', $result);
        $this->assertStringContainsString('(102)', $result);
    }

    /** @test */
    public function callable_can_access_other_columns_via_row_parameter(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [RowAccessVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Email should be formatted using the id from the row
        $this->assertStringContainsString('user1@example.com', $result);
        $this->assertStringContainsString('user2@example.com', $result);
        $this->assertStringContainsString('user3@example.com', $result);
    }

    /** @test */
    public function callable_can_combine_multiple_row_values(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [MultiColumnRowAccessVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Name should be formatted using id
        $this->assertStringContainsString('Anonymous User #1', $result);
        $this->assertStringContainsString('Anonymous User #2', $result);
        $this->assertStringContainsString('Anonymous User #3', $result);
    }

    /** @test */
    public function it_filters_rows_based_on_query_scope(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [FilteredVeilTable::class]]);

        $result = $this->exportAndGetContents();

        // Should only contain user with id = 1 (filtered by query)
        $this->assertStringContainsString('(1,', $result);
        $this->assertStringNotContainsString('(2,', $result);
        $this->assertStringNotContainsString('(3,', $result);
    }

    /** @test */
    public function it_exports_all_rows_when_query_returns_null(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $result = $this->exportAndGetContents();

        // Should contain all users when no filtering
        $this->assertStringContainsString('(1,', $result);
        $this->assertStringContainsString('(2,', $result);
        $this->assertStringContainsString('(3,', $result);
    }

    /** @test */
    public function it_produces_only_insert_statements(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $result = $this->exportAndGetContents();

        // Should only contain INSERT statements
        $this->assertStringContainsString('INSERT INTO', $result);
        $this->assertStringNotContainsString('CREATE TABLE', $result);
        $this->assertStringNotContainsString('DROP TABLE', $result);
    }

    /**
     * Export using Veil and return the file contents.
     */
    protected function exportAndGetContents(): string
    {
        $veil = app(Veil::class);
        $fileName = $veil->handle('test-export');

        return Storage::disk('veil')->get($fileName);
    }
}

// InvalidVeilTable kept inline as it doesn't implement VeilTable interface
class InvalidVeilTable
{
    // Does not implement VeilTable interface
}
