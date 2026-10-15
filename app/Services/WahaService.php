<?php

namespace App\Services;

use App\Models\InternUser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WahaService
{
    protected string $baseUrl;

    protected ?string $apiKey;

    protected string $session;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.waha.base_url', 'http://localhost:3000'), '/');
        $this->apiKey = config('services.waha.api_key');
        $this->session = (string) config('services.waha.session', 'default');
    }

    /**
     * Format phone number to WAHA chat ID format (e.g. 628123456789@c.us).
     * Always ensures that WhatsApp LIDs (@lid) are converted to real phone numbers.
     */
    public function formatChatId(string $phone): string
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($cleanPhone, '08')) {
            $cleanPhone = '628'.substr($cleanPhone, 2);
        }

        // If an LID was passed, check if we have a linked InternUser and use their real phone number
        if (class_exists(InternUser::class)) {
            $user = InternUser::where('whatsapp_lid', $cleanPhone)->first();
            if ($user && ! empty($user->phone_number)) {
                $cleanPhone = InternUser::cleanPhoneNumber($user->phone_number);
            }
        }

        if (! str_ends_with($cleanPhone, '@c.us')) {
            $cleanPhone .= '@c.us';
        }

        return $cleanPhone;
    }

    /**
     * Resolve a WhatsApp LID to a real phone number using WAHA API.
     */
    public function getPhoneNumberByLid(string $lid): ?string
    {
        $cleanLid = preg_replace('/[^0-9]/', '', $lid) ?? '';
        if (empty($cleanLid)) {
            return null;
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/api/{$this->session}/lids/{$cleanLid}");
            if ($response->successful()) {
                $data = $response->json();
                $rawPn = $data['pn'] ?? $data['phoneNumber'] ?? $data['id'] ?? null;
                if ($rawPn) {
                    $digits = preg_replace('/[^0-9]/', '', (string) $rawPn);

                    return $digits ?: null;
                }
            }
        } catch (\Throwable $e) {
            Log::debug("WAHA getPhoneNumberByLid failed for {$lid}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Send a text message via WAHA.
     */
    public function sendMessage(string $phoneOrChatId, string $text): bool
    {
        $chatId = $this->formatChatId($phoneOrChatId);

        try {
            $response = $this->client()->post("{$this->baseUrl}/api/sendText", [
                'session' => $this->session,
                'chatId' => $chatId,
                'text' => $text,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('WAHA sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'chatId' => $chatId,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WAHA sendMessage exception', [
                'error' => $e->getMessage(),
                'chatId' => $chatId,
            ]);

            return false;
        }
    }

    /**
     * Send an image or screenshot via WAHA.
     */
    public function sendImage(string $phoneOrChatId, string $filePathOrBase64, string $caption = '', string $filename = 'screenshot.png'): bool
    {
        $chatId = $this->formatChatId($phoneOrChatId);

        try {
            $payload = [
                'session' => $this->session,
                'chatId' => $chatId,
                'caption' => $caption,
            ];

            if (file_exists($filePathOrBase64)) {
                $mimeType = mime_content_type($filePathOrBase64) ?: 'image/png';
                $base64 = base64_encode((string) file_get_contents($filePathOrBase64));
                $payload['file'] = [
                    'mimetype' => $mimeType,
                    'filename' => basename($filePathOrBase64),
                    'data' => $base64,
                ];
            } else {
                $cleanBase64 = preg_replace('/^data:image\/[a-z]+;base64,/', '', $filePathOrBase64);
                $payload['file'] = [
                    'mimetype' => 'image/png',
                    'filename' => $filename,
                    'data' => (string) $cleanBase64,
                ];
            }

            $response = $this->client()->post("{$this->baseUrl}/api/sendImage", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::error('WAHA sendImage failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'chatId' => $chatId,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WAHA sendImage exception', [
                'error' => $e->getMessage(),
                'chatId' => $chatId,
            ]);

            return false;
        }
    }

    /**
     * Check WAHA session status.
     *
     * @return array<string, mixed>
     */
    public function getSessionStatus(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/api/sessions/{$this->session}");

            return $response->json() ?? ['status' => 'UNKNOWN'];
        } catch (\Throwable $e) {
            return [
                'status' => 'UNREACHABLE',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Internal HTTP client with authentication headers.
     */
    protected function client(): PendingRequest
    {
        $client = Http::timeout(15);

        if (! empty($this->apiKey)) {
            $client = $client->withHeaders([
                'X-Api-Key' => $this->apiKey,
            ]);
        }

        return $client;
    }
}
