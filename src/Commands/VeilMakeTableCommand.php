<?php

namespace SignDeck\Veil\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\GeneratorCommand;

class VeilMakeTableCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'veil:make-table {table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a veil table class.';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'VeilTable';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__.'/../../stubs/veiltable.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Veil';
    }

    /**
     * Get the desired class name from the input.
     *
     * @return string
     */
    protected function getNameInput(): string
    {
        $table = trim($this->argument('table'));
        
        // Remove "Veil" prefix and "Table" suffix if user included them
        $table = preg_replace('/^Veil/', '', $table);
        $table = preg_replace('/Table$/', '', $table);
        
        return 'Veil' . Str::studly($table) . 'Table';
    }

    /**
     * Build the class with the given name.
     *
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $stub = parent::buildClass($name);
        
        // Get the table name from the argument
        $table = trim($this->argument('table'));
        
        // Remove "Veil" prefix and "Table" suffix if user included them
        $table = preg_replace('/^Veil/', '', $table);
        $table = preg_replace('/Table$/', '', $table);
        
        // Convert to lowercase to match database table naming conventions
        $table = strtolower($table);
        
        // Replace DummyTableName with the actual table name (as a string literal)
        $stub = str_replace('DummyTableName', "'{$table}'", $stub);
        
        return $stub;
    }
}