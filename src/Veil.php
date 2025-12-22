<?php

namespace SignDeck\Veil;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Events\ExportCompleted;
use SignDeck\Veil\Events\ExportStarted;
use SignDeck\Veil\Exceptions\ContractImplementationException;
use Spatie\DbSnapshots\SnapshotFactory;

class Veil
{
    protected Filesystem $disk;

    protected VeilProgressBar $progressBar;

    protected SchemaInspector $schemaInspector;

    protected SqlProcessor $sqlProcessor;

    public function __construct(
        protected SnapshotFactory $snapshotFactory,
        protected FilesystemFactory $filesystemFactory,
    ) {
        $this->disk = $this->filesystemFactory->disk(config('veil.disk', 'local'));
        $this->progressBar = new VeilProgressBar();
        $this->schemaInspector = new SchemaInspector();
        $this->sqlProcessor = new SqlProcessor(
            $this->schemaInspector,
            new RowAnonymizer(),
            $this->progressBar
        );
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
        $this->sqlProcessor->setProgressBar($this->progressBar);

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

        // Fire pre-export event
        Event::dispatch(new ExportStarted($snapshotName, $tableNames));

        $this->progressBar->info('Creating database snapshot...');

        $snapshot = $this->createSnapshot($tableNames, $snapshotName);

        $this->progressBar->info('Anonymizing data...');

        $this->anonymizeSnapshot($snapshot, $veilTables);

        $this->progressBar->finish();
        $this->progressBar->newLine(2);

        // Fire post-export event
        Event::dispatch(new ExportCompleted($snapshot, $snapshotName, $tableNames));

        return $snapshot;
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
     * Create a snapshot using Spatie's SnapshotFactory.
     *
     * @param  string|null  $customName  Custom name for the snapshot. If null, uses timestamped name.
     */
    protected function createSnapshot(array $tableNames, ?string $customName = null): string
    {
        $snapshotName = $customName ?? 'veil_' . Carbon::now()->format('Y-m-d_H-i-s');
        $diskName = config('veil.disk', 'local');
        $connectionName = config('veil.connection') ?? config('database.default');
        $compress = config('veil.compress', false);

        $snapshot = $this->snapshotFactory->create(
            $snapshotName,
            $diskName,
            $connectionName,
            $compress,
            $tableNames,
        );

        return $snapshot->fileName;
    }

    /**
     * Anonymize the snapshot by replacing column values in the SQL dump.
     *
     * @param  VeilTable[]  $veilTables
     */
    protected function anonymizeSnapshot(
        string $fileName,
        array $veilTables
    ): void {
        $contents = $this->disk->get($fileName);

        foreach ($veilTables as $veilTable) {
            $tableName = $veilTable->table();
            $allowedIds = $this->getAllowedIds($veilTable);

            // Get row count for progress bar
            $rowCount = $this->schemaInspector->getTableRowCount($tableName, $allowedIds);
            $this->progressBar->startForTable($tableName, $rowCount);

            $contents = $this->sqlProcessor->processTableInSql($contents, $veilTable, $allowedIds);
        }

        // Strip all non-INSERT statements (CREATE TABLE, DROP TABLE, SET, etc.) to produce a data-only export
        $contents = $this->sqlProcessor->stripNonInsertStatements($contents);

        $this->disk->put($fileName, $contents);
    }

    /**
     * Get allowed IDs based on the query scope.
     *
     * @return array|null Array of allowed IDs, or null if no filtering
     */
    protected function getAllowedIds(VeilTable $veilTable): ?array
    {
        $query = $veilTable->query();

        if (! $query) {
            return null;
        }

        $tableName = $veilTable->table();

        // Get the primary key column name (default to 'id')
        $primaryKey = $this->schemaInspector->getPrimaryKeyColumn($tableName);

        // Execute the query and get IDs
        return $query->pluck($primaryKey)->toArray();
    }
}
