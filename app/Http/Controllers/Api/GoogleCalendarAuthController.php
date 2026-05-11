<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleCalendarAuthController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $googleCalendar) {}

    public function connect(Request $request): JsonResponse
    {
        $url = $this->googleCalendar->getAuthUrl($request->user()->id);

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (!$code || !$state) {
            return redirect(config('google.frontend_error_url') . '&reason=missing_params');
        }

        try {
            $this->googleCalendar->exchangeCodeAndStore($code, $state);
            return redirect(config('google.frontend_success_url'));
        } catch (\Exception $e) {
            return redirect(config('google.frontend_error_url') . '&reason=' . urlencode($e->getMessage()));
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->googleCalendar->disconnect($request->user());

        return response()->json(['message' => 'Google Calendar desconectado correctamente.']);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'connected' => $this->googleCalendar->isConnected($request->user()),
        ]);
    }
}
