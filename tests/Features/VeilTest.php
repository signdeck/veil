<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Tests\TestCase;
use SignDeck\Veil\Veil;

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

        $this->expectException(\SignDeck\Veil\Exceptions\ContractImplementationException::class);

        $veil = app(Veil::class);
        $veil->handle();
    }

    /** @test */
    public function veil_unchanged_returns_asis_instance(): void
    {
        $result = Veil::unchanged();

        $this->assertInstanceOf(\SignDeck\Veil\AsIs::class, $result);
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
        };

        $result = $this->callProcessTableInSql($sql, $veilTable);

        // Each row should have a different ID
        $this->assertStringContainsString('(100)', $result);
        $this->assertStringContainsString('(101)', $result);
        $this->assertStringContainsString('(102)', $result);
    }

    /**
     * Helper to call the protected processTableInSql method.
     */
    protected function callProcessTableInSql(string $sql, VeilTable $veilTable): string
    {
        $veil = app(Veil::class);
        $reflection = new ReflectionClass($veil);
        $method = $reflection->getMethod('processTableInSql');
        $method->setAccessible(true);

        return $method->invoke($veil, $sql, $veilTable);
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

// Test VeilTable implementations

class TestVeilUsersTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => 'test@example.com',
        ];
    }
}

class PartialColumnsVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => 'test@example.com',
            // name and password are intentionally excluded
        ];
    }
}

class AnonymizedVeilTable implements VeilTable
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
}

class UnchangedColumnsVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => 'anon@test.com',
        ];
    }
}

class NullableColumnsVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'created_at' => '2024-01-01 00:00:00',
        ];
    }
}

class NumericAnonymizedVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => 999,
        ];
    }
}

class QuotedValueVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'name' => "O'Brien",
        ];
    }
}

class EmptyColumnsVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [];
    }
}

class CallableVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => fn ($original) => strtoupper($original),
        ];
    }
}

class CallableWithOriginalValueVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => fn ($original) => $original . '.redacted',
        ];
    }
}

class InvalidVeilTable
{
    // Does not implement VeilTable interface
}
