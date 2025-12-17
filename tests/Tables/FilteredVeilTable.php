<?php

namespace SignDeck\Veil\Tests\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Veil;

class FilteredVeilTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),
            'email' => 'redacted@example.com',
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return DB::table('users')->where('id', 1);
    }
}

