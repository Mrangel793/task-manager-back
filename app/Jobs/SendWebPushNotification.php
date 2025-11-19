<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWebPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The notification instance.
     *
     * @var Notification
     */
    public Notification $notification;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param Notification $notification
     */
    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }

    /**
     * Execute the job.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function handle(NotificationService $notificationService): void
    {
        Log::info("Processing SendWebPushNotification job for notification {$this->notification->id}");

        // Verify notification still exists and is pending
        $this->notification->refresh();

        if ($this->notification->status !== 'pending') {
            Log::info("Notification {$this->notification->id} is not pending, skipping");
            return;
        }

        // Send the notification
        $success = $notificationService->sendWebPushNotification($this->notification);

        if ($success) {
            Log::info("Web Push notification {$this->notification->id} sent successfully");
        } else {
            Log::warning("Web Push notification {$this->notification->id} failed to send");

            // If we've exhausted all retries, the failed() method will be called
            if ($this->attempts() >= $this->tries) {
                Log::error("Web Push notification {$this->notification->id} exhausted all retry attempts");
            }
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
        Log::error("SendWebPushNotification job failed for notification {$this->notification->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark notification as failed
        $this->notification->markAsFailed('Job failed: ' . $exception->getMessage());
    }
}
