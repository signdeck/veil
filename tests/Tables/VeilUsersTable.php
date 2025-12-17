<?php

namespace SignDeck\Veil\Tests\Tables;

use SignDeck\Veil\Veil;
use SignDeck\Veil\Contracts\VeilTable;

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
}