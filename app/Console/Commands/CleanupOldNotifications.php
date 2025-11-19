<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete notifications older than 90 days';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of old notifications...');

        try {
            // Calculate the cutoff date (90 days ago)
            $cutoffDate = Carbon::now()->subDays(90);

            $this->info("Deleting notifications created before {$cutoffDate->format('Y-m-d H:i:s')}");

            // Count notifications to be deleted
            $count = Notification::where('created_at', '<', $cutoffDate)->count();

            if ($count === 0) {
                $this->info('No old notifications found to delete.');
                return Command::SUCCESS;
            }

            // Delete old notifications
            $deleted = Notification::where('created_at', '<', $cutoffDate)->delete();

            $this->info("Successfully deleted {$deleted} notification(s).");
            Log::info("Cleanup completed: {$deleted} old notifications deleted");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error cleaning up notifications: ' . $e->getMessage());
            Log::error('Error in CleanupOldNotifications command: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
