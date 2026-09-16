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
     * @param  string|null  $snapshotName  Custom name for the snapshot (for display purposes)
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

            $query = $veilTable->query() ?? DB::table($tableName);
            $rowCount = $this->getRowCount($query);

            $preview[] = [
                'name' => $tableName,
                'columns' => array_keys($columns),
                'row_count' => $rowCount,
            ];
        }

        return $preview;
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
}
