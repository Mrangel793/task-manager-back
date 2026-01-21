<?php

namespace App\Console\Commands;

use App\Jobs\SendTaskReminderJob;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendTaskReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminders for tasks due in 30 minutes';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Checking for tasks with upcoming deadlines...');

        try {
            // Calculate the time window (30 minutes from now, with 1 minute tolerance)
            $targetTime = Carbon::now()->addMinutes(30);
            $startWindow = $targetTime->copy()->subMinute();
            $endWindow = $targetTime->copy()->addMinute();

            $this->info("Looking for tasks due between {$startWindow->format('H:i')} and {$endWindow->format('H:i')}");

            // Find tasks that are:
            // 1. Due today
            // 2. Have due_time within the 30-minute window
            // 3. Are still Pendiente or En Progreso
            $tasks = Task::whereIn('status', ['Pendiente', 'En Progreso'])
                ->whereDate('due_date', Carbon::today())
                ->get()
                ->filter(function ($task) use ($startWindow, $endWindow) {
                    if (!$task->due_time) {
                        return false;
                    }

                    // Parse due_time (format: HH:MM) - due_date is already Y-m-d string
                    $dueDateTime = Carbon::parse($task->due_date . ' ' . $task->due_time);

                    // Check if due time is within our window
                    return $dueDateTime->between($startWindow, $endWindow);
                });

            if ($tasks->isEmpty()) {
                $this->info('No tasks found with upcoming deadlines.');
                return Command::SUCCESS;
            }

            $this->info("Found {$tasks->count()} task(s) with upcoming deadlines.");

            $dispatched = 0;
            foreach ($tasks as $task) {
                try {
                    // Dispatch reminder job
                    SendTaskReminderJob::dispatch($task);

                    $this->line("✓ Reminder queued for task {$task->id}: {$task->title}");
                    $dispatched++;

                    Log::info("Task reminder queued for task {$task->id}");
                } catch (\Exception $e) {
                    $this->error("✗ Failed to queue reminder for task {$task->id}: {$e->getMessage()}");
                    Log::error("Failed to queue reminder for task {$task->id}: " . $e->getMessage());
                }
            }

            $this->info("Successfully queued {$dispatched} task reminder(s).");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error processing task reminders: ' . $e->getMessage());
            Log::error('Error in SendTaskReminders command: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
