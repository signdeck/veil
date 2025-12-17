<?php

namespace SignDeck\Veil;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class VeilProgressBar
{
    protected ?ProgressBar $progressBar = null;

    protected ?Command $command = null;

    public function __construct(?Command $command = null)
    {
        $this->command = $command;
    }

    /**
     * Start a new progress bar for processing a table.
     */
    public function startForTable(string $tableName, int $rowCount): void
    {
        if (!$this->command) {
            return;
        }

        // Finish any existing progress bar
        if ($this->progressBar) {
            $this->progressBar->finish();
            $this->command->newLine();
        }

        $this->progressBar = $this->command->getOutput()->createProgressBar($rowCount);
        $this->progressBar->setFormat(" Processing {$tableName}: %current%/%max% [%bar%] %percent:3s%%");
        $this->progressBar->start();
    }

    /**
     * Advance the progress bar by one step.
     */
    public function advance(int $step = 1): void
    {
        if ($this->progressBar) {
            $this->progressBar->advance($step);
        }
    }

    /**
     * Finish the current progress bar.
     */
    public function finish(): void
    {
        if ($this->progressBar) {
            $this->progressBar->finish();
        }
    }

    /**
     * Display an info message.
     */
    public function info(string $message): void
    {
        if ($this->command) {
            $this->command->info($message);
        }
    }

    /**
     * Add a new line.
     */
    public function newLine(int $count = 1): void
    {
        if ($this->command) {
            $this->command->newLine($count);
        }
    }
}

