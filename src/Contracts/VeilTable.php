<?php

namespace SignDeck\Veil\Contracts;

interface VeilTable
{
    public function table(): string;

    public function columns(): array;
}