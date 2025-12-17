<?php

namespace SignDeck\Veil\Tests\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Veil;

class TestVeilPostsTable implements VeilTable
{
    public function table(): string
    {
        return 'posts';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'title' => 'Anonymized Title',
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

