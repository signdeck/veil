<?php

namespace SignDeck\Veil\Tests\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Veil;

class VeilUsersTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => Veil::unchanged(),     // Keep original value
            'email' => 'user@example.com', // Anonymize to this value
            'name' => 'John Doe',          // Anonymize to this value
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}