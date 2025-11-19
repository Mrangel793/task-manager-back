<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    /**
     * Register a new push subscription.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        auth()->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            'aes128gcm'
        );

        return response()->json([
            'success' => true,
            'message' => 'Push subscription registered successfully',
        ]);
    }

    /**
     * Remove a push subscription.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url',
        ]);

        auth()->user()->deletePushSubscription($validated['endpoint']);

        return response()->json([
            'success' => true,
            'message' => 'Push subscription removed successfully',
        ]);
    }

    /**
     * Get VAPID public key for client configuration.
     *
     * @return JsonResponse
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'public_key' => config('webpush.vapid.public_key'),
        ]);
    }
}
