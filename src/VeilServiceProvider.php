<?php

namespace SignDeck\Veil;

use Illuminate\Support\ServiceProvider;
use SignDeck\Veil\Commands\VeilExportCommand;
use SignDeck\Veil\Commands\VeilMakeTableCommand;

class VeilServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/veil.php' => config_path('veil.php'),
        ], 'veil-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                VeilMakeTableCommand::class,
                VeilExportCommand::class,
            ]);
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/veil.php', 'veil');
    }
}