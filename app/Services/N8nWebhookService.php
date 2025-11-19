<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class N8nWebhookService
{
    /**
     * Send WhatsApp notification via N8n webhook.
     *
     * @param array $data
     * @return bool
     */
    public function sendWhatsAppNotification(array $data): bool
    {
        try {
            $webhookUrl = config('n8n.webhook_url');
            $webhookSecret = config('n8n.webhook_secret');

            if (!$webhookUrl) {
                Log::warning('N8N_WEBHOOK_URL not configured');
                return false;
            }

            Log::info('Sending WhatsApp notification to N8n webhook', [
                'notification_id' => $data['notification_id'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            // Prepare payload
            $payload = [
                'event' => 'whatsapp.send',
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ];

            // Add secret to headers if configured
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($webhookSecret) {
                $headers['X-Webhook-Secret'] = $webhookSecret;
            }

            // Send POST request to N8n webhook with timeout
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->post($webhookUrl, $payload);

            // Check response
            if ($response->successful()) {
                Log::info('N8n webhook responded successfully', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return true;
            } else {
                Log::error('N8n webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('N8n webhook connection timeout or failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error sending to N8n webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Send task event to N8n webhook.
     *
     * @param string $event
     * @param array $data
     * @return bool
     */
    public function sendTaskEvent(string $event, array $data): bool
    {
        try {
            $webhookUrl = config('n8n.webhook_url');
            $webhookSecret = config('n8n.webhook_secret');

            if (!$webhookUrl) {
                Log::warning('N8N_WEBHOOK_URL not configured');
                return false;
            }

            // Check if event is enabled
            $eventKey = str_replace('.', '_', $event);
            if (!config("n8n.events.{$eventKey}", true)) {
                Log::info("N8n event '{$event}' is disabled", ['event' => $event]);
                return false;
            }

            Log::info("Sending task event '{$event}' to N8n webhook");

            // Prepare payload
            $payload = [
                'event' => $event,
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ];

            // Add secret to headers if configured
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($webhookSecret) {
                $headers['X-Webhook-Secret'] = $webhookSecret;
            }

            // Send POST request to N8n webhook with timeout
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->post($webhookUrl, $payload);

            // Check response
            if ($response->successful()) {
                Log::info("N8n webhook for event '{$event}' responded successfully", [
                    'status' => $response->status(),
                ]);

                return true;
            } else {
                Log::error("N8n webhook for event '{$event}' failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("N8n webhook for event '{$event}' connection timeout", [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error("Error sending event '{$event}' to N8n webhook", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
