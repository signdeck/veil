<?php

namespace SignDeck\Veil\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

interface VeilTable
{
    /**
     * The name of the table to be exported.
     */
    public function table(): string;

    /**
     * List of columns to be exported.
     */
    public function columns(): array;

    /**
     * Define a query scope to filter which rows are exported.
     * Return null to export all rows.
     *
     * @return Builder|QueryBuilder|null
     */
    public function query(): Builder|QueryBuilder|null;
}
