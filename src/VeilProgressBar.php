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

        if ($command) {
            $this->progressBar = $command->getOutput()->createProgressBar(0);
            $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        }
    }

    /**
     * Set the maximum number of steps.
     */
    public function setMaxSteps(int $max): void
    {
        if ($this->progressBar) {
            $this->progressBar->setMaxSteps($max);
        }
    }

    /**
     * Start the progress bar.
     */
    public function start(): void
    {
        if ($this->progressBar) {
            $this->progressBar->start();
        }
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
     * Set the message displayed next to the progress bar.
     */
    public function setMessage(string $message): void
    {
        if ($this->progressBar) {
            $this->progressBar->setMessage($message);
        }
    }

    /**
     * Finish the progress bar.
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

