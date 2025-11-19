<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTaskReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The task instance.
     *
     * @var Task
     */
    public Task $task;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param Task $task
     */
    public function __construct(Task $task)
    {
        $this->task = $task;
    }

    /**
     * Execute the job.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function handle(NotificationService $notificationService): void
    {
        Log::info("Processing SendTaskReminderJob for task {$this->task->id}");

        // Refresh task to get latest data
        $this->task->refresh();

        // Only send reminder if task is still pending or in progress
        if (!in_array($this->task->status, ['Pendiente', 'En Progreso'])) {
            Log::info("Task {$this->task->id} is {$this->task->status}, skipping reminder");
            return;
        }

        // Send the reminder
        try {
            $notificationService->sendTaskReminder($this->task);
            Log::info("Task reminder sent successfully for task {$this->task->id}");
        } catch (\Exception $e) {
            Log::error("Error sending task reminder for task {$this->task->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SendTaskReminderJob failed for task {$this->task->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
