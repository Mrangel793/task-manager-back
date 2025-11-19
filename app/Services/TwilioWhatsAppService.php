<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwilioWhatsAppService
{
    protected Client $client;
    protected string $fromNumber;

    public function __construct()
    {
        $sid = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');
        $this->fromNumber = config('services.twilio.whatsapp_from');

        if ($sid && $token) {
            $this->client = new Client($sid, $token);
        }
    }

    /**
     * Send WhatsApp message via Twilio.
     *
     * @param string $to Phone number in format: +1234567890
     * @param string $message
     * @return bool
     */
    public function sendMessage(string $to, string $message): bool
    {
        try {
            if (!isset($this->client)) {
                Log::warning('Twilio client not configured');
                return false;
            }

            // Format phone number for WhatsApp
            $formattedTo = $this->formatPhoneNumber($to);
            $formattedFrom = $this->formatPhoneNumber($this->fromNumber);

            Log::info('Sending WhatsApp message via Twilio', [
                'to' => $formattedTo,
                'from' => $formattedFrom,
            ]);

            $twilioMessage = $this->client->messages->create(
                $formattedTo,
                [
                    'from' => $formattedFrom,
                    'body' => $message,
                ]
            );

            Log::info('WhatsApp message sent successfully via Twilio', [
                'sid' => $twilioMessage->sid,
                'status' => $twilioMessage->status,
            ]);

            return true;
        } catch (\Twilio\Exceptions\TwilioException $e) {
            Log::error('Twilio API error', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp message via Twilio', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Format phone number for WhatsApp (add whatsapp: prefix).
     *
     * @param string $phone
     * @return string
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove any existing whatsapp: prefix
        $phone = str_replace('whatsapp:', '', $phone);

        // Ensure phone starts with +
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        // Add whatsapp: prefix for Twilio
        return 'whatsapp:' . $phone;
    }
}
