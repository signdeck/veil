<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use SignDeck\Veil\AsIs;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Exceptions\ContractImplementationException;
use SignDeck\Veil\RowAnonymizer;
use SignDeck\Veil\SchemaInspector;
use SignDeck\Veil\SqlProcessor;
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
    public function it_creates_snapshot_file(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class]]);

        $veil = app(Veil::class);
        $fileName = $veil->handle();

        $this->assertNotNull($fileName);
        $this->assertStringStartsWith('veil_', $fileName);
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
        $sql = $this->getMySqlDump();

        $veilTable = new PartialColumnsVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Extract just the INSERT statement for checking
        preg_match('/INSERT INTO.*?;/s', $result, $matches);
        $insertStatement = $matches[0] ?? '';

        // Should contain only id and email columns in INSERT
        $this->assertStringContainsString('`id`', $insertStatement);
        $this->assertStringContainsString('`email`', $insertStatement);

        // Should NOT contain name or password columns in INSERT
        $this->assertStringNotContainsString('`name`', $insertStatement);
        $this->assertStringNotContainsString('`password`', $insertStatement);
    }

    /** @test */
    public function it_anonymizes_columns_with_specified_values(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new AnonymizedVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Original emails should be replaced
        $this->assertStringNotContainsString('user1@example.com', $result);
        $this->assertStringNotContainsString('user2@example.com', $result);

        // Should contain the anonymized value
        $this->assertStringContainsString('redacted@example.com', $result);
    }

    /** @test */
    public function it_keeps_original_values_when_using_veil_unchanged(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new UnchangedColumnsVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

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
        $sql = "INSERT INTO `users` (`id`, `name`, `email`, `created_at`) VALUES (1, 'Test', 'test@example.com', NULL);";

        $veilTable = new NullableColumnsVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // NULL values should be preserved
        $this->assertStringContainsString('NULL', $result);
    }

    /** @test */
    public function it_handles_multiple_rows(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new TestVeilUsersTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Should have INSERT statement
        $this->assertStringContainsString('INSERT INTO `users`', $result);

        // Should have multiple value groups
        $this->assertStringContainsString('test@example.com', $result);
    }

    /** @test */
    public function it_handles_numeric_values_correctly(): void
    {
        $sql = "INSERT INTO `users` (`id`, `name`, `email`, `password`) VALUES (1, 'User 1', 'user1@example.com', 'secret');";

        $veilTable = new NumericAnonymizedVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Numeric value should not be quoted
        $this->assertStringContainsString('(999)', $result);
    }

    /** @test */
    public function it_escapes_single_quotes_in_anonymized_values(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new QuotedValueVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Single quote should be escaped
        $this->assertStringContainsString("O''Brien", $result);
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
    public function it_removes_insert_statements_when_columns_array_is_empty(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new EmptyColumnsVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // INSERT statements should be removed
        $this->assertStringNotContainsString('INSERT INTO `users`', $result);
    }

    /** @test */
    public function it_executes_callable_values(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new CallableVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // The callable should transform emails to uppercase
        $this->assertStringContainsString('USER1@EXAMPLE.COM', $result);
        $this->assertStringContainsString('USER2@EXAMPLE.COM', $result);
        $this->assertStringContainsString('USER3@EXAMPLE.COM', $result);
    }

    /** @test */
    public function callable_receives_original_value(): void
    {
        $sql = "INSERT INTO `users` (`id`, `name`, `email`, `password`) VALUES (1, 'John Doe', 'john@example.com', 'secret');";

        $veilTable = new CallableWithOriginalValueVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // The callable should append to the original value
        $this->assertStringContainsString('john@example.com.redacted', $result);
    }

    /** @test */
    public function callable_can_return_different_values_per_row(): void
    {
        $sql = $this->getMySqlDump();

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

        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Each row should have a different ID
        $this->assertStringContainsString('(100)', $result);
        $this->assertStringContainsString('(101)', $result);
        $this->assertStringContainsString('(102)', $result);
    }

    /** @test */
    public function callable_can_access_other_columns_via_row_parameter(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new RowAccessVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Email should be formatted using the id from the row
        $this->assertStringContainsString('user1@example.com', $result);
        $this->assertStringContainsString('user2@example.com', $result);
        $this->assertStringContainsString('user3@example.com', $result);
    }

    /** @test */
    public function callable_can_combine_multiple_row_values(): void
    {
        $sql = $this->getMySqlDump();

        $veilTable = new MultiColumnRowAccessVeilTable();
        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Name should be formatted using id
        $this->assertStringContainsString('Anonymous User #1', $result);
        $this->assertStringContainsString('Anonymous User #2', $result);
        $this->assertStringContainsString('Anonymous User #3', $result);
    }

    /** @test */
    public function it_filters_rows_based_on_query_scope(): void
    {
        // Insert test data
        $this->app['db']->table('users')->insert([
            ['id' => 1, 'name' => 'User 1', 'email' => 'user1@example.com', 'password' => 'secret'],
            ['id' => 2, 'name' => 'User 2', 'email' => 'user2@example.com', 'password' => 'secret'],
            ['id' => 3, 'name' => 'User 3', 'email' => 'user3@example.com', 'password' => 'secret'],
        ]);

        $sql = $this->getMySqlDump();
        $veilTable = new FilteredVeilTable();

        // Get allowed IDs from query
        $veil = app(Veil::class);
        $reflection = new ReflectionClass($veil);
        $method = $reflection->getMethod('getAllowedIds');
        $method->setAccessible(true);
        $allowedIds = $method->invoke($veil, $veilTable);

        $this->assertEquals([1], $allowedIds);

        // Test filtering in SQL processing
        $sqlProcessor = new SqlProcessor(
            new SchemaInspector(),
            new RowAnonymizer()
        );
        $result = $sqlProcessor->processTableInSql($sql, $veilTable, $allowedIds);

        // Should only contain user with id = 1 (filtered by query)
        $this->assertStringContainsString('(1,', $result);
        $this->assertStringNotContainsString('(2,', $result);
        $this->assertStringNotContainsString('(3,', $result);
    }

    /** @test */
    public function it_exports_all_rows_when_query_not_defined(): void
    {
        $sql = $this->getMySqlDump();
        $veilTable = new TestVeilUsersTable();

        $sqlProcessor = new SqlProcessor(
            new SchemaInspector(),
            new RowAnonymizer()
        );
        $result = $sqlProcessor->processTableInSql($sql, $veilTable, null);

        // Should contain all users when no filtering
        $this->assertStringContainsString('(1,', $result);
        $this->assertStringContainsString('(2,', $result);
        $this->assertStringContainsString('(3,', $result);
    }

    /**
     * Helper to call the SqlProcessor's processTableInSql method.
     */
    protected function callProcessTableInSql(string $sql, VeilTable $veilTable): string
    {
        $sqlProcessor = new SqlProcessor(
            new SchemaInspector(),
            new RowAnonymizer()
        );

        return $sqlProcessor->processTableInSql($sql, $veilTable);
    }

    /**
     * Get a sample MySQL dump for testing.
     */
    protected function getMySqlDump(): string
    {
        return <<<SQL
-- MySQL dump
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
);

INSERT INTO `users` (`id`, `name`, `email`, `password`) VALUES (1, 'User 1', 'user1@example.com', 'secret123'), (2, 'User 2', 'user2@example.com', 'secret456'), (3, 'User 3', 'user3@example.com', 'secret789');
SQL;
    }
}

// InvalidVeilTable kept inline as it doesn't implement VeilTable interface
class InvalidVeilTable
{
    // Does not implement VeilTable interface
}
