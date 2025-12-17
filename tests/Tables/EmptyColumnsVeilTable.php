<?php

namespace SignDeck\Veil\Tests\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SignDeck\Veil\Contracts\VeilTable;

class EmptyColumnsVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

