<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleCalendarService
{
    public function getAuthUrl(string $userId): string
    {
        $client = $this->buildBaseClient();
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $state = Str::random(32);
        // Map state → user ID with 10-minute TTL
        Cache::put("google_oauth_state:{$state}", $userId, now()->addMinutes(10));

        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function exchangeCodeAndStore(string $code, string $state): User
    {
        $userId = Cache::pull("google_oauth_state:{$state}");
        if (!$userId) {
            throw new \RuntimeException('Estado OAuth inválido o expirado.');
        }

        $client = $this->buildBaseClient();
        $tokenData = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokenData['error'])) {
            throw new \RuntimeException('Error al obtener token de Google: ' . $tokenData['error_description']);
        }

        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $this->storeTokens($user, $tokenData);

        return $user;
    }

    public function disconnect(User $user): void
    {
        $user->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
        ]);
    }

    public function isConnected(User $user): bool
    {
        return !empty($user->google_refresh_token);
    }

    public function createEvent(User $user, Task $task, string $role = 'assignee'): string
    {
        $client = $this->getAuthenticatedClient($user);
        $service = new Calendar($client);

        $event = $this->buildEvent($task, $role);
        $created = $service->events->insert('primary', $event);

        return $created->getId();
    }

    public function updateEvent(User $user, Task $task, string $eventId, string $role = 'assignee'): void
    {
        $client = $this->getAuthenticatedClient($user);
        $service = new Calendar($client);

        try {
            $existing = $service->events->get('primary', $eventId);
            if ($existing->getStatus() === 'cancelled') {
                return;
            }
        } catch (\Exception) {
            return;
        }

        $event = $this->buildEvent($task, $role);
        $service->events->update('primary', $eventId, $event);
    }

    public function deleteEvent(User $user, string $eventId): void
    {
        $client = $this->getAuthenticatedClient($user);
        $service = new Calendar($client);

        try {
            $service->events->delete('primary', $eventId);
        } catch (\Google\Service\Exception $e) {
            // 410 = already deleted/cancelled — safe to ignore
            if ($e->getCode() !== 410) {
                throw $e;
            }
        }
    }

    private function buildEvent(Task $task, string $role): Event
    {
        $assignee = $task->assignee ?? User::withoutGlobalScopes()->find($task->assignee_id);
        $creator = $task->creator ?? User::withoutGlobalScopes()->find($task->creator_id);

        $description = $task->description ?? '';
        if ($role === 'assignee') {
            $description .= "\n\nCreado por: " . ($creator?->name ?? 'Desconocido');
            $description .= "\nEstado: {$task->status}";
        } else {
            $description .= "\n\nAsignado a: " . ($assignee?->name ?? 'Desconocido');
            $description .= "\nEstado: {$task->status}";
        }

        $event = new Event([
            'summary' => "[{$task->id}] {$task->title}",
            'description' => trim($description),
        ]);

        if ($task->due_time) {
            // due_time puede ser 'HH:MM' o 'HH:MM:SS' — tomamos solo los primeros 5 caracteres
            $dueTime = substr($task->due_time, 0, 5);
            $startDt = Carbon::createFromFormat('Y-m-d H:i', "{$task->due_date} {$dueTime}");
            $endDt = $startDt->copy()->addHour();

            $start = new EventDateTime();
            $start->setDateTime($startDt->toRfc3339String());
            $start->setTimeZone(config('app.timezone', 'America/Bogota'));

            $end = new EventDateTime();
            $end->setDateTime($endDt->toRfc3339String());
            $end->setTimeZone(config('app.timezone', 'America/Bogota'));
        } else {
            // Sin hora definida: usar la hora de creación de la tarea como referencia
            $createdTime = $task->created_at
                ? Carbon::parse($task->created_at)->setTimezone(config('app.timezone', 'America/Bogota'))
                : Carbon::now(config('app.timezone', 'America/Bogota'));

            $startDt = Carbon::createFromFormat('Y-m-d H:i', "{$task->due_date} " . $createdTime->format('H:i'));
            $endDt = $startDt->copy()->addHour();

            $start = new EventDateTime();
            $start->setDateTime($startDt->toRfc3339String());
            $start->setTimeZone(config('app.timezone', 'America/Bogota'));

            $end = new EventDateTime();
            $end->setDateTime($endDt->toRfc3339String());
            $end->setTimeZone(config('app.timezone', 'America/Bogota'));
        }

        $event->setStart($start);
        $event->setEnd($end);

        return $event;
    }

    private function getAuthenticatedClient(User $user): Client
    {
        $client = $this->buildBaseClient();

        $accessToken = json_decode(Crypt::decryptString($user->google_access_token), true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            if (!$user->google_refresh_token) {
                throw new \RuntimeException("Usuario {$user->id} no tiene refresh token.");
            }

            $client->fetchAccessTokenWithRefreshToken(
                Crypt::decryptString($user->google_refresh_token)
            );

            $newToken = $client->getAccessToken();
            if (isset($newToken['error'])) {
                // Token revocado — limpiar para evitar loops
                $this->disconnect($user);
                throw new \RuntimeException("Token de Google revocado para usuario {$user->id}.");
            }

            $this->storeTokens($user, $newToken, preserveRefreshToken: true);
        }

        return $client;
    }

    private function buildBaseClient(): Client
    {
        $client = new Client();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->addScope(config('google.scopes'));

        return $client;
    }

    private function storeTokens(User $user, array $tokenData, bool $preserveRefreshToken = false): void
    {
        $updates = [
            'google_access_token' => Crypt::encryptString(json_encode($tokenData)),
            'google_token_expires_at' => isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'] - 60)
                : null,
        ];

        if (!$preserveRefreshToken && isset($tokenData['refresh_token'])) {
            $updates['google_refresh_token'] = Crypt::encryptString($tokenData['refresh_token']);
        }

        $user->update($updates);
    }
}
