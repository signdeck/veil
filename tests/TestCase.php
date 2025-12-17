<?php

namespace SignDeck\Veil\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SignDeck\Veil\VeilServiceProvider;
use Spatie\DbSnapshots\DbSnapshotsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            DbSnapshotsServiceProvider::class,
            VeilServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filesystems.disks.veil', [
            'driver' => 'local',
            'root' => storage_path('app/veil'),
        ]);

        $app['config']->set('veil.disk', 'veil');
        $app['config']->set('veil.tables', []);
        
        $app['config']->set('db-snapshots.disk', 'veil');
    }

    protected function setUpDatabase(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->text('content');
            $table->timestamps();
        });
    }

    protected function seedUsers(int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->app['db']->table('users')->insert([
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'password' => 'secret123',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function seedPosts(int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->app['db']->table('posts')->insert([
                'user_id' => 1,
                'title' => "Post Title {$i}",
                'content' => "This is the content of post {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

