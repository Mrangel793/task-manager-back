<?php

namespace App\Providers;

use App\Events\TaskAssigned;
use App\Events\TaskCreated;
use App\Events\TaskDeleted;
use App\Events\TaskStatusChanged;
use App\Events\TaskUpdated;
use App\Listeners\LogTaskHistory;
use App\Listeners\SendTaskCreatedNotification;
use App\Listeners\SendTaskStatusChangedNotification;
use App\Listeners\SyncTaskToGoogleCalendar;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        TaskCreated::class => [
            SendTaskCreatedNotification::class,
            LogTaskHistory::class,
            [SyncTaskToGoogleCalendar::class, 'handleTaskCreated'],
        ],

        TaskUpdated::class => [
            [SyncTaskToGoogleCalendar::class, 'handleTaskUpdated'],
        ],

        TaskStatusChanged::class => [
            SendTaskStatusChangedNotification::class,
            LogTaskHistory::class,
            [SyncTaskToGoogleCalendar::class, 'handleTaskStatusChanged'],
        ],

        TaskAssigned::class => [
            SendTaskCreatedNotification::class,
            LogTaskHistory::class,
            [SyncTaskToGoogleCalendar::class, 'handleTaskAssigned'],
        ],

        TaskDeleted::class => [
            [SyncTaskToGoogleCalendar::class, 'handleTaskDeleted'],
        ],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
