<?php

namespace SignDeck\Veil\Commands;

use SignDeck\Veil\Veil;
use SignDeck\Veil\VeilDryRun;
use Illuminate\Console\Command;

class VeilExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'veil:export 
        {--name= : Custom name for the snapshot file} 
        {--dry-run : Preview what would be exported without creating the file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export the tables listed in veil.tables config with anonymized columns.';

    /**
     * Execute the console command.
     */
    public function handle(Veil $veil, VeilDryRun $veilDryRun): int
    {
        $tables = config('veil.tables', []);

        if (empty($tables)) {
            $this->warn('No tables configured in veil.tables. Nothing to export.');

            return self::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No files will be created');
            $this->newLine();
        } else {
            $this->info('Starting veiled export...');
            $this->newLine();
        }

        $this->info('Tables to export:');
        foreach ($tables as $tableClass) {
            $this->line("  • {$tableClass}");
        }
        $this->newLine();

        try {
            $snapshotName = $this->option('name');

            if ($isDryRun) {
                $preview = $veilDryRun->preview($snapshotName);
                $this->displayDryRunPreview($preview);
            } else {
                $veil->withProgressBar($this);
                $fileName = $veil->handle($snapshotName);

                if ($fileName) {
                    $disk = config('veil.disk', 'local');
                    $this->newLine();
                    $this->info("✓ Export completed successfully!");
                    $this->line("  File: {$fileName}");
                    $this->line("  Disk: {$disk}");
                }
            }
        } catch (\Exception $e) {
            $this->error('Export failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Display dry-run preview information.
     */
    protected function displayDryRunPreview(array $preview): void
    {
        $this->info('Preview:');
        $this->newLine();

        foreach ($preview as $table) {
            $this->line("  Table: <comment>{$table['name']}</comment>");
            $this->line("    Columns to export: " . implode(', ', $table['columns']));
            $this->line("    Estimated rows: {$table['row_count']}");
            $this->newLine();
        }

        $snapshotName = $this->option('name') ?? 'veil_' . date('Y-m-d_H-i-s');
        $disk = config('veil.disk', 'local');
        $this->info("Would create: <comment>{$snapshotName}.sql</comment> on disk: <comment>{$disk}</comment>");
    }
}
