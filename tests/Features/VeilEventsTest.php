<?php

namespace SignDeck\Veil\Tests\Features;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SignDeck\Veil\Contracts\VeilTable;
use SignDeck\Veil\Events\ExportCompleted;
use SignDeck\Veil\Events\ExportStarted;
use SignDeck\Veil\Tests\TestCase;
use SignDeck\Veil\Veil;

class VeilEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('veil');
        Event::fake();
    }

    /** @test */
    public function it_fires_export_started_event(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilEventsUsersTable::class]]);

        $veil = app(Veil::class);
        $veil->handle();

        Event::assertDispatched(ExportStarted::class, function ($event) {
            return $event->snapshotName === null
                && in_array('users', $event->tableNames);
        });
    }

    /** @test */
    public function it_fires_export_started_event_with_custom_name(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilEventsUsersTable::class]]);

        $veil = app(Veil::class);
        $veil->handle('custom-export');

        Event::assertDispatched(ExportStarted::class, function ($event) {
            return $event->snapshotName === 'custom-export'
                && in_array('users', $event->tableNames);
        });
    }

    /** @test */
    public function it_fires_export_completed_event(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilEventsUsersTable::class]]);

        $veil = app(Veil::class);
        $fileName = $veil->handle();

        Event::assertDispatched(ExportCompleted::class, function ($event) use ($fileName) {
            return $event->fileName === $fileName
                && $event->snapshotName === null
                && in_array('users', $event->tableNames);
        });
    }

    /** @test */
    public function it_fires_export_completed_event_with_custom_name(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilEventsUsersTable::class]]);

        $veil = app(Veil::class);
        $fileName = $veil->handle('custom-export');

        Event::assertDispatched(ExportCompleted::class, function ($event) use ($fileName) {
            return $event->fileName === $fileName
                && $event->snapshotName === 'custom-export'
                && in_array('users', $event->tableNames);
        });
    }

    /** @test */
    public function it_fires_both_events_in_order(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilEventsUsersTable::class]]);

        $veil = app(Veil::class);
        $fileName = $veil->handle();

        Event::assertDispatched(ExportStarted::class);
        Event::assertDispatched(ExportCompleted::class);

        // Verify ExportStarted was fired before ExportCompleted
        $events = Event::dispatched(ExportStarted::class);
        $completedEvents = Event::dispatched(ExportCompleted::class);

        $this->assertNotEmpty($events);
        $this->assertNotEmpty($completedEvents);
    }

    /** @test */
    public function it_includes_all_table_names_in_events(): void
    {
        $this->seedUsers();

        config(['veil.tables' => [TestVeilUsersTable::class, TestVeilEventsPostsTable::class]]);

        $veil = app(Veil::class);
        $veil->handle();

        Event::assertDispatched(ExportStarted::class, function ($event) {
            return in_array('users', $event->tableNames)
                && in_array('posts', $event->tableNames)
                && count($event->tableNames) === 2;
        });

        Event::assertDispatched(ExportCompleted::class, function ($event) {
            return in_array('users', $event->tableNames)
                && in_array('posts', $event->tableNames)
                && count($event->tableNames) === 2;
        });
    }

    /** @test */
    public function it_does_not_fire_events_when_no_tables_configured(): void
    {
        config(['veil.tables' => []]);

        $veil = app(Veil::class);
        $veil->handle();

        Event::assertNotDispatched(ExportStarted::class);
        Event::assertNotDispatched(ExportCompleted::class);
    }
}

// Test table classes
class TestVeilEventsUsersTable implements VeilTable
{
    public function table(): string
    {
        return 'users';
    }

    public function columns(): array
    {
        return [
            'id' => \SignDeck\Veil\Veil::unchanged(),
            'name' => 'Anonymized Name',
            'email' => 'anonymized@example.com',
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

class TestVeilEventsPostsTable implements VeilTable
{
    public function table(): string
    {
        return 'posts';
    }

    public function columns(): array
    {
        return [
            'id' => \SignDeck\Veil\Veil::unchanged(),
            'title' => 'Anonymized Title',
        ];
    }

    public function query(): Builder|QueryBuilder|null
    {
        return null;
    }
}

