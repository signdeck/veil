<?php

namespace SignDeck\Veil\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportStarted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string|null $snapshotName The name of the snapshot being created
     * @param array $tableNames Array of table names being exported
     */
    public function __construct(
        public ?string $snapshotName,
        public array $tableNames
    ) {
    }
}

