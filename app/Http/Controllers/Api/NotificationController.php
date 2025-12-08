<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Display a listing of the user's notifications.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::where('user_id', $user->id)
            ->with(['task:id,title,status', 'task.assignee:id,name'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'task' => [
                        'id' => $notification->task->id ?? null,
                        'title' => $notification->task->title ?? null,
                        'status' => $notification->task->status ?? null,
                        'assignee_name' => $notification->task->assignee->name ?? null,
                    ],
                    'message' => $this->getNotificationMessage($notification),
                    'is_read' => !is_null($notification->read_at),
                    'created_at' => $notification->created_at->toISOString(),
                    'read_at' => $notification->read_at?->toISOString(),
                ];
            });

        $unreadCount = $notifications->where('is_read', false)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => [
                'id' => $notification->id,
                'read_at' => $notification->read_at->toISOString(),
            ],
        ]);
    }

    /**
     * Mark all user's notifications as read.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $updated = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'data' => [
                'updated_count' => $updated,
            ],
        ]);
    }

    /**
     * Get a human-readable message for the notification.
     *
     * @param Notification $notification
     * @return string
     */
    private function getNotificationMessage(Notification $notification): string
    {
        $taskTitle = $notification->task->title ?? 'Unknown task';

        return match ($notification->type) {
            'task_assigned' => "You have been assigned to task: {$taskTitle}",
            'task_reminder' => "Reminder: {$taskTitle}",
            'task_due_soon' => "Task due soon: {$taskTitle}",
            'task_overdue' => "Task is overdue: {$taskTitle}",
            'status_changed' => "Task status changed: {$taskTitle}",
            'task_reassigned' => "Task has been reassigned: {$taskTitle}",
            default => "Notification about: {$taskTitle}",
        };
    }
}
