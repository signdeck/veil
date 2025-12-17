<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Support\Facades\File;
use SignDeck\Veil\Tests\TestCase;

class VeilMakeTableCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up generated files
        $veilPath = app_path('Veil');
        if (File::isDirectory($veilPath)) {
            File::deleteDirectory($veilPath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_can_generate_a_veil_table_class(): void
    {
        $this->artisan('veil:make-table', ['table' => 'users'])
            ->assertSuccessful();

        $expectedPath = app_path('Veil/VeilUsersTable.php');

        $this->assertFileExists($expectedPath);

        $contents = File::get($expectedPath);

        $this->assertStringContainsString('class VeilUsersTable', $contents);
        $this->assertStringContainsString('implements VeilTable', $contents);
        $this->assertStringContainsString('use SignDeck\Veil\Contracts\VeilTable', $contents);
    }

    /** @test */
    public function it_generates_class_with_correct_naming_format(): void
    {
        $this->artisan('veil:make-table', ['table' => 'order_items'])
            ->assertSuccessful();

        $expectedPath = app_path('Veil/VeilOrderItemsTable.php');

        $this->assertFileExists($expectedPath);

        $contents = File::get($expectedPath);

        $this->assertStringContainsString('class VeilOrderItemsTable', $contents);
    }

    /** @test */
    public function it_normalizes_name_if_user_includes_prefix_or_suffix(): void
    {
        // If user provides VeilUsersTable, it should still create VeilUsersTable (not VeilVeilUsersTableTable)
        $this->artisan('veil:make-table', ['table' => 'VeilUsersTable'])
            ->assertSuccessful();

        $expectedPath = app_path('Veil/VeilUsersTable.php');

        $this->assertFileExists($expectedPath);

        $contents = File::get($expectedPath);

        $this->assertStringContainsString('class VeilUsersTable', $contents);
        $this->assertStringNotContainsString('VeilVeil', $contents);
    }

    /** @test */
    public function generated_class_includes_veil_unchanged_example(): void
    {
        $this->artisan('veil:make-table', ['table' => 'customers'])
            ->assertSuccessful();

        $expectedPath = app_path('Veil/VeilCustomersTable.php');
        $contents = File::get($expectedPath);

        $this->assertStringContainsString('Veil::unchanged()', $contents);
        $this->assertStringContainsString('use SignDeck\Veil\Veil', $contents);
    }

    /** @test */
    public function generated_class_has_table_method(): void
    {
        $this->artisan('veil:make-table', ['table' => 'products'])
            ->assertSuccessful();

        $expectedPath = app_path('Veil/VeilProductsTable.php');
        $contents = File::get($expectedPath);

        $this->assertStringContainsString('public function table(): string', $contents);
    }

    /** @test */
    public function generated_class_has_columns_method(): void
    {
        $this->artisan('veil:make-table', ['table' => 'products'])
            ->assertSuccessful();

        $expectedPath = app_path('Veil/VeilProductsTable.php');
        $contents = File::get($expectedPath);

        $this->assertStringContainsString('public function columns(): array', $contents);
    }
}

