<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Task Reminders - Check every minute for tasks due in 30 minutes
Schedule::command('tasks:send-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup Old Notifications - Run daily at 3:00 AM
Schedule::command('notifications:cleanup')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
