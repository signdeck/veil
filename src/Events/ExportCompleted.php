<?php

namespace SignDeck\Veil\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $fileName The filename of the created snapshot
     * @param string|null $snapshotName The name of the snapshot that was created
     * @param array $tableNames Array of table names that were exported
     */
    public function __construct(
        public string $fileName,
        public ?string $snapshotName,
        public array $tableNames
    ) {
    }
}

