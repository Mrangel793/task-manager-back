<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Display a listing of the user's in-app notifications.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Notification::where('user_id', $user->id)
            ->where('channel', 'in_app')
            ->with(['task:id,title,status', 'task.assignee:id,name'])
            ->orderBy('created_at', 'desc');

        // Optional: filter by read status
        if ($request->has('unread_only') && $request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        // Pagination
        $perPage = $request->get('per_page', 20);
        $notifications = $query->paginate($perPage);

        $formattedNotifications = $notifications->getCollection()->map(function ($notification) {
            return [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message ?? $this->getNotificationMessage($notification),
                'data' => $notification->data,
                'task' => $notification->task ? [
                    'id' => $notification->task->id,
                    'title' => $notification->task->title,
                    'status' => $notification->task->status,
                    'assignee_name' => $notification->task->assignee->name ?? null,
                ] : null,
                'is_read' => !is_null($notification->read_at),
                'created_at' => $notification->created_at->toISOString(),
                'read_at' => $notification->read_at?->toISOString(),
            ];
        });

        $unreadCount = Notification::where('user_id', $user->id)
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $formattedNotifications,
                'unread_count' => $unreadCount,
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
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
                'message' => 'Notificacion no encontrada',
            ], 404);
        }

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notificacion marcada como leida',
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
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leidas',
            'data' => [
                'updated_count' => $updated,
            ],
        ]);
    }

    /**
     * Get unread count only.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::where('user_id', $user->id)
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    /**
     * Get pending WhatsApp notifications (for n8n integration).
     * This endpoint is called by n8n to fetch notifications to send.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPendingWhatsApp(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        $notifications = Notification::withoutGlobalScopes()
            ->where('channel', 'whatsapp')
            ->where('status', 'pending')
            ->where('retry_count', '<', 3) // Max 3 retries
            ->with(['user:id,name,phone,whatsapp_phone', 'task:id,title'])
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'user_id' => $notification->user_id,
                    'task_id' => $notification->task_id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'data' => $notification->data,
                    'retry_count' => $notification->retry_count,
                    'created_at' => $notification->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'count' => $notifications->count(),
            ],
        ]);
    }

    /**
     * Update notification status (for n8n integration).
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $notification = Notification::withoutGlobalScopes()->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificacion no encontrada',
            ], 404);
        }

        $status = $request->input('status');
        $validStatuses = ['pending', 'sent', 'failed', 'deferred'];

        if (!in_array($status, $validStatuses)) {
            return response()->json([
                'success' => false,
                'message' => 'Estado invalido. Valores permitidos: ' . implode(', ', $validStatuses),
            ], 400);
        }

        $updateData = ['status' => $status];

        if ($status === 'sent') {
            $updateData['sent_at'] = $request->input('sent_at', now());
        } elseif ($status === 'failed') {
            $updateData['failure_reason'] = $request->input('failure_reason', 'Unknown error');
            $updateData['retry_count'] = $notification->retry_count + 1;
        }

        $notification->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Estado de notificacion actualizado',
            'data' => [
                'id' => $notification->id,
                'status' => $notification->status,
                'sent_at' => $notification->sent_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Delete a single notification.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificación eliminada',
        ]);
    }

    /**
     * Delete all user's notifications.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $deleted = Notification::where('user_id', $user->id)
            ->where('channel', 'in_app')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones eliminadas',
            'data' => [
                'deleted_count' => $deleted,
            ],
        ]);
    }

    /**
     * Delete only read notifications.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function destroyRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $deleted = Notification::where('user_id', $user->id)
            ->where('channel', 'in_app')
            ->whereNotNull('read_at')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones leídas eliminadas',
            'data' => [
                'deleted_count' => $deleted,
            ],
        ]);
    }

    /**
     * Get a human-readable message for the notification (fallback).
     *
     * @param Notification $notification
     * @return string
     */
    private function getNotificationMessage(Notification $notification): string
    {
        $taskTitle = $notification->task->title ?? 'Tarea desconocida';

        return match ($notification->type) {
            'task_assigned', 'task_created' => "Se te ha asignado la tarea: {$taskTitle}",
            'task_reminder' => "Recordatorio: {$taskTitle}",
            'task_due_soon' => "Tarea por vencer: {$taskTitle}",
            'task_overdue' => "Tarea vencida: {$taskTitle}",
            'status_changed', 'task_status_changed' => "Cambio de estado en tarea: {$taskTitle}",
            'task_reassigned' => "Tarea reasignada: {$taskTitle}",
            default => "Notificacion sobre: {$taskTitle}",
        };
    }
}
