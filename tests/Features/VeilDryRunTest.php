<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Storage;
use SignDeck\Veil\Tests\TestCase;
use SignDeck\Veil\Veil;
use SignDeck\Veil\VeilDryRun;

class VeilDryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('veil');
    }

    /** @test */
    public function it_returns_empty_array_when_no_tables_configured(): void
    {
        config(['veil.tables' => []]);

        $veil = app(Veil::class);
        $veilDryRun = new VeilDryRun($veil);

        $preview = $veilDryRun->preview();

        $this->assertIsArray($preview);
        $this->assertEmpty($preview);
    }

    /** @test */
    public function it_returns_preview_with_table_information(): void
    {
        $this->seedUsers(5);

        config(['veil.tables' => [\SignDeck\Veil\Tests\Tables\VeilUsersTable::class]]);

        $veil = app(Veil::class);
        $veilDryRun = new VeilDryRun($veil);

        $preview = $veilDryRun->preview();

        $this->assertIsArray($preview);
        $this->assertCount(1, $preview);
        $this->assertEquals('users', $preview[0]['name']);
        $this->assertContains('id', $preview[0]['columns']);
        $this->assertContains('email', $preview[0]['columns']);
        $this->assertEquals(5, $preview[0]['row_count']);
    }

    /** @test */
    public function it_does_not_create_any_files(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [\SignDeck\Veil\Tests\Tables\VeilUsersTable::class]]);

        $veil = app(Veil::class);
        $veilDryRun = new VeilDryRun($veil);

        $veilDryRun->preview();

        $files = Storage::disk('veil')->files();
        $this->assertEmpty($files);
    }

    /** @test */
    public function it_handles_multiple_tables(): void
    {
        $this->seedUsers(3);
        $this->seedPosts(2);

        config(['veil.tables' => [
            \SignDeck\Veil\Tests\Tables\VeilUsersTable::class,
            TestVeilPostsTable::class,
        ]]);

        $veil = app(Veil::class);
        $veilDryRun = new VeilDryRun($veil);

        $preview = $veilDryRun->preview();

        $this->assertCount(2, $preview);
        $this->assertEquals('users', $preview[0]['name']);
        $this->assertEquals(3, $preview[0]['row_count']);
        $this->assertEquals('posts', $preview[1]['name']);
        $this->assertEquals(2, $preview[1]['row_count']);
    }

    /** @test */
    public function it_handles_nonexistent_tables_gracefully(): void
    {
        config(['veil.tables' => [NonExistentTable::class]]);

        $veil = app(Veil::class);
        $veilDryRun = new VeilDryRun($veil);

        // Should return 0 row count for non-existent table
        $preview = $veilDryRun->preview();

        $this->assertIsArray($preview);
        $this->assertNotEmpty($preview);
        $this->assertEquals(0, $preview[0]['row_count']);
    }
}

// Helper class for testing
class TestVeilPostsTable implements \SignDeck\Veil\Contracts\VeilTable
{
    public function table(): string
    {
        return 'posts';
    }

    public function columns(): array
    {
        return [
            'id' => \SignDeck\Veil\Veil::unchanged(),
            'title' => 'Anonymized Title',
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

class NonExistentTable implements \SignDeck\Veil\Contracts\VeilTable
{
    public function table(): string
    {
        return 'nonexistent_table';
    }

    public function columns(): array
    {
        return [
            'id' => \SignDeck\Veil\Veil::unchanged(),
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

