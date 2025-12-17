<?php

namespace SignDeck\Veil\Tests\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Veil;

class NonExistentTable implements VeilTable
{
    public function table(): string
    {
        return 'nonexistent_table';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

