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

    /** @test */
    public function it_uses_custom_name_when_provided(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export', ['--name' => 'staging-export'])
            ->assertSuccessful();

        $files = Storage::disk('veil')->files();

        $this->assertNotEmpty($files);
        $this->assertStringContainsString('staging-export', $files[0]);
        $this->assertStringEndsWith('.sql', $files[0]);
    }

    /** @test */
    public function it_uses_timestamped_name_when_no_custom_name_provided(): void
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
    public function it_shows_preview_in_dry_run_mode(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export', ['--dry-run' => true])
            ->expectsOutput('🔍 DRY RUN MODE - No files will be created')
            ->expectsOutputToContain('Preview:')
            ->expectsOutputToContain('Table:')
            ->assertSuccessful();

        // Verify no file was created
        $files = Storage::disk('veil')->files();
        $this->assertEmpty($files);
    }

    /** @test */
    public function it_shows_columns_and_row_count_in_dry_run(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers(5);

        $this->artisan('veil:export', ['--dry-run' => true])
            ->expectsOutputToContain('Columns to export:')
            ->expectsOutputToContain('Estimated rows:')
            ->assertSuccessful();
    }

    /** @test */
    public function it_shows_progress_during_export(): void
    {
        config(['veil.tables' => [VeilUsersTable::class]]);

        $this->seedUsers();

        $this->artisan('veil:export')
            ->expectsOutput('Creating database snapshot...')
            ->expectsOutput('Anonymizing data...')
            ->assertSuccessful();
    }
}

