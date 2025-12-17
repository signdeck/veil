<?php

namespace SignDeck\Veil;

use Illuminate\Support\Facades\DB;

class VeilDryRun
{
    public function __construct(
        protected Veil $veil
    ) {
        //
    }

    /**
     * Get preview data for dry-run mode.
     *
     * @param string|null $snapshotName Custom name for the snapshot (for display purposes)
     * @return array Preview data array
     */
    public function preview(?string $snapshotName = null): array
    {
        $veilTables = $this->veil->resolveVeilTables();

        if (empty($veilTables)) {
            return [];
        }

        $preview = [];

        foreach ($veilTables as $veilTable) {
            $tableName = $veilTable->table();
            $columns = $veilTable->columns();

            // Get row count from database
            $rowCount = $this->getTableRowCount($tableName);

            $preview[] = [
                'name' => $tableName,
                'columns' => array_keys($columns),
                'row_count' => $rowCount,
            ];
        }

        return $preview;
    }

    /**
     * Get the row count for a table.
     */
    protected function getTableRowCount(string $tableName): int
    {
        try {
            return DB::table($tableName)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
}

