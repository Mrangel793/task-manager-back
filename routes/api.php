<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PushNotificationController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserTabController;
use App\Http\Controllers\Api\WhatsAppCommandController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('register', 'register')->name('api.auth.register');
        Route::post('verify', 'verify')->name('api.auth.verify');
        Route::post('login', 'login')->name('api.auth.login');
        Route::post('forgot-password', 'forgotPassword')->name('api.auth.forgot-password');
        Route::post('reset-password', 'resetPassword')->name('api.auth.reset-password');
    });
});

// Protected routes (authentication required)
Route::prefix('v1')->middleware(['auth:sanctum', 'org.context'])->group(function () {
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('logout', 'logout')->name('api.auth.logout');
        Route::post('refresh-token', 'refreshToken')->name('api.auth.refresh-token');
        Route::post('change-password', 'changePassword')->name('api.auth.change-password');
        Route::get('me', 'me')->name('api.auth.me');
        Route::patch('me', 'updateProfile')->name('api.auth.update-profile');
    });

    // Users CRUD routes
    Route::get('users', [UserController::class, 'index'])->name('api.users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('api.users.show');
    Route::post('users', [UserController::class, 'store'])
        ->middleware('permission:manage-users')
        ->name('api.users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('api.users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:manage-users')
        ->name('api.users.destroy');

    // Notifications routes
    Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index')->name('api.notifications.index');
        Route::get('unread-count', 'unreadCount')->name('api.notifications.unread-count');
        Route::post('mark-all-read', 'markAllAsRead')->name('api.notifications.mark-all-read');
        Route::patch('{id}/read', 'markAsRead')->name('api.notifications.mark-as-read');
        Route::delete('{id}', 'destroy')->name('api.notifications.destroy');
        Route::delete('/', 'destroyAll')->name('api.notifications.destroy-all');
        Route::delete('read', 'destroyRead')->name('api.notifications.destroy-read');
    });

    // Push notifications routes
    Route::prefix('push')->controller(PushNotificationController::class)->group(function () {
        Route::get('vapid-public-key', 'vapidPublicKey')->name('api.push.vapid-key');
        Route::post('subscribe', 'subscribe')->name('api.push.subscribe');
        Route::post('unsubscribe', 'unsubscribe')->name('api.push.unsubscribe');
    });

    // User Tabs routes (for custom tab synchronization)
    Route::prefix('tabs')->controller(UserTabController::class)->group(function () {
        Route::get('/', 'index')->name('api.tabs.index');
        Route::post('/', 'store')->name('api.tabs.store');
        Route::patch('{id}', 'update')->name('api.tabs.update');
        Route::delete('{id}', 'destroy')->name('api.tabs.destroy');
        Route::post('reorder', 'reorder')->name('api.tabs.reorder');
    });

    // Tasks CRUD routes
    Route::get('tasks', [TaskController::class, 'index'])->name('api.tasks.index');
    Route::get('tasks/{task}', [TaskController::class, 'show'])
        ->middleware('check.task.permissions')
        ->name('api.tasks.show');
    Route::post('tasks', [TaskController::class, 'store'])
        ->middleware('permission:create-tasks')
        ->name('api.tasks.store');
    Route::match(['patch', 'put'], 'tasks/{task}', [TaskController::class, 'update'])
        ->middleware('check.task.permissions')
        ->name('api.tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
        ->middleware(['permission:delete-tasks', 'check.task.permissions'])
        ->name('api.tasks.destroy');
    Route::post('tasks/{task}/reassign', [TaskController::class, 'reassign'])
        ->middleware('permission:reassign-tasks')
        ->name('api.tasks.reassign');
    Route::get('tasks/{task}/history', [TaskController::class, 'getHistory'])
        ->middleware('check.task.permissions')
        ->name('api.tasks.history');
});

// WhatsApp Command Routes (N8n integration) - NO requiere auth:sanctum, usa n8n.auth
Route::prefix('v1/whatsapp/commands')->middleware('n8n.auth')->controller(WhatsAppCommandController::class)->group(function () {
    Route::post('help', 'help')->name('api.whatsapp.commands.help');
    Route::post('list-tasks', 'listTasks')->name('api.whatsapp.commands.list-tasks');
    Route::post('get-task', 'getTask')->name('api.whatsapp.commands.get-task');
    Route::post('start-task', 'startTask')->name('api.whatsapp.commands.start-task');
    Route::post('complete-task', 'completeTask')->name('api.whatsapp.commands.complete-task');
    Route::post('create-task', 'createTask')->name('api.whatsapp.commands.create-task');
});

// N8n Notification Routes - Para enviar notificaciones de WhatsApp
Route::prefix('v1/notifications')->middleware('n8n.auth')->controller(NotificationController::class)->group(function () {
    Route::get('pending-whatsapp', 'getPendingWhatsApp')->name('api.notifications.pending-whatsapp');
    Route::patch('{id}/status', 'updateStatus')->name('api.notifications.update-status');
});
