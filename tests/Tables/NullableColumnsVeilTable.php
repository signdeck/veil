<?php

namespace SignDeck\Veil\Tests\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Veil;

class NullableColumnsVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'created_at' => '2024-01-01 00:00:00',
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

