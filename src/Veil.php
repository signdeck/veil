<?php

namespace SignDeck\Veil;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Events\ExportCompleted;
use SignDeck\Veil\Events\ExportStarted;
use SignDeck\Veil\Exceptions\ContractImplementationException;

class Veil
{
    protected Filesystem $disk;

    protected VeilProgressBar $progressBar;

    protected RowAnonymizer $rowAnonymizer;

    public function __construct(
        protected FilesystemFactory $filesystemFactory,
    ) {
        $this->disk = $this->filesystemFactory->disk(config('veil.disk', 'local'));
        $this->progressBar = new VeilProgressBar();
        $this->rowAnonymizer = new RowAnonymizer();
    }

    /**
     * Indicate that a column value should remain unchanged.
     */
    public static function unchanged(): AsIs
    {
        return new AsIs;
    }

    /**
     * Set a progress bar for the export process.
     *
     * @param  Command  $command  Command instance for progress updates
     * @return $this
     */
    public function withProgressBar(Command $command): self
    {
        $this->progressBar = new VeilProgressBar($command);

        return $this;
    }

    /**
     * Handle the export process.
     *
     * @param  string|null  $snapshotName  Custom name for the snapshot. If null, uses timestamped name.
     * @return string|null Snapshot filename or null
     */
    public function handle(?string $snapshotName = null): ?string
    {
        $veilTables = $this->resolveVeilTables();

        if (empty($veilTables)) {
            return null;
        }

        $tableNames = array_map(
            fn (VeilTable $table) => $table->table(),
            $veilTables
        );

        Event::dispatch(new ExportStarted($snapshotName, $tableNames));

        $fileName = ($snapshotName ?? 'veil_' . Carbon::now()->format('Y-m-d_H-i-s')) . '.sql';

        $this->progressBar->info('Exporting and anonymizing data...');

        $this->exportTables($fileName, $veilTables);

        $this->progressBar->finish();
        $this->progressBar->newLine(2);

        if (config('veil.compress', false)) {
            $fileName = $this->compressFile($fileName);
        }

        Event::dispatch(new ExportCompleted($fileName, $snapshotName, $tableNames));

        return $fileName;
    }

    /**
     * Resolve and validate the VeilTable classes.
     *
     * @return VeilTable[]
     */
    public function resolveVeilTables(): array
    {
        $tables = config('veil.tables', []);
        $resolved = [];

        foreach ($tables as $tableClass) {
            $instance = app($tableClass);

            if (! $instance instanceof VeilTable) {
                throw new ContractImplementationException(
                    $tableClass . ' must implement ' . VeilTable::class . ' interface.'
                );
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * Export all tables to the SQL file.
     *
     * @param  VeilTable[]  $veilTables
     */
    protected function exportTables(string $fileName, array $veilTables): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'veil_');
        $handle = fopen($tempPath, 'w');

        foreach ($veilTables as $veilTable) {
            $this->exportTable($handle, $veilTable);
        }

        fclose($handle);

        $this->disk->writeStream($fileName, fopen($tempPath, 'r'));

        unlink($tempPath);
    }

    /**
     * Export a single table's data, anonymized, to the file handle.
     */
    protected function exportTable($handle, VeilTable $veilTable): void
    {
        $tableName = $veilTable->table();
        $columns = $veilTable->columns();

        if (empty($columns)) {
            return;
        }

        $exportColumnNames = array_keys($columns);

        $query = $veilTable->query() ?? DB::table($tableName);

        $rowCount = $this->getRowCount($query);
        $this->progressBar->startForTable($tableName, $rowCount);

        $columnList = implode(', ', array_map(fn ($col) => "`{$col}`", $exportColumnNames));

        $query->orderBy(DB::raw(1))->chunk(500, function ($rows) use ($handle, $tableName, $columns, $columnList) {
            $processedRows = [];

            foreach ($rows as $row) {
                $rowArray = (array) $row;

                $this->progressBar->advance();

                $values = $this->rowAnonymizer->anonymizeRow($rowArray, $columns);

                if (! empty($values)) {
                    $processedRows[] = '(' . implode(', ', $values) . ')';
                }
            }

            if (! empty($processedRows)) {
                $insert = "INSERT INTO `{$tableName}` ({$columnList}) VALUES " . implode(', ', $processedRows) . ";\n";
                fwrite($handle, $insert);
            }
        });
    }

    /**
     * Get the row count for a query.
     */
    protected function getRowCount($query): int
    {
        try {
            return (clone $query)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Compress the export file using gzip.
     *
     * @return string The new filename with .gz extension
     */
    protected function compressFile(string $fileName): string
    {
        $contents = $this->disk->get($fileName);
        $compressedFileName = $fileName . '.gz';

        $this->disk->put($compressedFileName, gzencode($contents, 9));
        $this->disk->delete($fileName);

        return $compressedFileName;
    }
}
