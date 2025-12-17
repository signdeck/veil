<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Support\Facades\Storage;
use SignDeck\Veil\Tests\TestCase;
use SignDeck\Veil\Tests\Tables\VeilUsersTable;

class VeilExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('veil');
    }

    /** @test */
    public function it_shows_warning_when_no_tables_configured(): void
    {
        $this->artisan('veil:export')
            ->expectsOutput('No tables configured in veil.tables. Nothing to export.')
            ->assertSuccessful();
    }

    /** @test */
    public function it_displays_tables_being_exported(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->expectsOutput('Starting veiled export...')
            ->expectsOutput('Tables to export:')
            ->assertSuccessful();
    }

    /** @test */
    public function it_creates_export_file_on_configured_disk(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->assertSuccessful();

        $files = Storage::disk('veil')->files();

        $this->assertNotEmpty($files);
        $this->assertStringStartsWith('veil_', $files[0]);
        $this->assertStringEndsWith('.sql', $files[0]);
    }

    /** @test */
    public function it_shows_success_message_with_file_info(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->expectsOutputToContain('Export completed successfully!')
            ->assertSuccessful();
    }
}

