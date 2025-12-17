<?php

namespace SignDeck\Veil\Commands;

use SignDeck\Veil\Veil;
use Illuminate\Console\Command;

class VeilExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'veil:export';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export the tables listed in veil.tables config with anonymized columns.';

    /**
     * Execute the console command.
     */
    public function handle(Veil $veil): int
    {
        $tables = config('veil.tables', []);

        if (empty($tables)) {
            $this->warn('No tables configured in veil.tables. Nothing to export.');

            return self::SUCCESS;
        }

        $this->info('Starting veiled export...');
        $this->newLine();

        $this->info('Tables to export:');
        foreach ($tables as $tableClass) {
            $this->line("  • {$tableClass}");
        }
        $this->newLine();

        try {
            $fileName = $veil->handle();

            if ($fileName) {
                $disk = config('veil.disk', 'local');
                $this->newLine();
                $this->info("✓ Export completed successfully!");
                $this->line("  File: {$fileName}");
                $this->line("  Disk: {$disk}");
            }
        } catch (\Exception $e) {
            $this->error('Export failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
