<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\InternUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MagangHubAutomationService
{
    /**
     * Submit or fill the daily report on MagangHub portal via headless browser.
     *
     * @return array{
     *     success: bool,
     *     screenshot: ?string,
     *     error: ?string,
     *     message: ?string,
     *     output: ?string
     * }
     */
    public function submitReport(DailyReport $report, bool $submitForm = true): array
    {
        $user = InternUser::findByPhoneOrLid($report->sender_phone);
        $phone = $user?->phone_number ?? $report->sender_phone;

        $sessionFile = storage_path("app/maganghub_sessions/{$phone}.json");
        $userDataDir = storage_path("app/maganghub_profiles/{$phone}");
        $reportDateStr = $report->report_date ? $report->report_date->toDateString() : now()->toDateString();
        $screenshotPath = storage_path("app/public/screenshots/{$reportDateStr}_{$phone}.png");

        // Ensure directories exist
        File::ensureDirectoryExists(dirname($sessionFile));
        File::ensureDirectoryExists($userDataDir);
        File::ensureDirectoryExists(dirname($screenshotPath));

        $payload = [
            'userDataDir' => $userDataDir,
            'sessionFile' => $sessionFile,
            'values' => [
                'uraianAktivitas' => (string) $report->activity_description,
                'pembelajaran' => (string) $report->lessons_learned,
                'kendala' => (string) $report->challenges,
            ],
            'screenshotPath' => $screenshotPath,
            'headless' => (bool) config('services.maganghub.headless', true),
            'submitForm' => $submitForm,
            'fullPage' => true,
        ];

        $tempPayloadFile = storage_path('framework/cache/maganghub_payload_'.uniqid('', true).'.json');
        File::ensureDirectoryExists(dirname($tempPayloadFile));
        file_put_contents($tempPayloadFile, (string) json_encode($payload));

        $nodeBinary = (string) config('services.maganghub.node_path', 'node');
        $scriptPath = base_path('scripts/maganghub-automator.mjs');

        try {
            $command = sprintf('"%s" "%s" "%s" 2>&1', $nodeBinary, $scriptPath, $tempPayloadFile);
            $output = trim((string) shell_exec($command));

            // Extract JSON from output
            $json = null;
            $lines = explode("\n", $output);
            foreach (array_reverse($lines) as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded) && isset($decoded['success'])) {
                        $json = $decoded;
                        break;
                    }
                }
            }

            if ($json !== null) {
                $isSuccess = (bool) ($json['success'] ?? false);
                $capturedScreenshot = ! empty($json['screenshot']) && file_exists((string) $json['screenshot'])
                    ? (string) $json['screenshot']
                    : null;

                return [
                    'success' => $isSuccess,
                    'screenshot' => $capturedScreenshot,
                    'error' => $json['error'] ?? null,
                    'message' => $json['message'] ?? null,
                    'output' => $output,
                ];
            }

            Log::error('MagangHub automation unhandled output', [
                'output' => $output,
            ]);

            return [
                'success' => false,
                'screenshot' => null,
                'error' => ! empty($output) ? $output : 'Gagal mengeksekusi automasi headless browser.',
                'message' => null,
                'output' => $output,
            ];
        } catch (\Throwable $e) {
            Log::error('MagangHub automation exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'screenshot' => null,
                'error' => $e->getMessage(),
                'message' => null,
                'output' => null,
            ];
        } finally {
            if (file_exists($tempPayloadFile)) {
                @unlink($tempPayloadFile);
            }
        }
    }

    /**
     * Save session JSON content for a specific user.
     */
    public function saveSession(InternUser|string $userOrPhone, string $jsonContent): bool
    {
        $phone = $userOrPhone instanceof InternUser
            ? $userOrPhone->phone_number
            : InternUser::cleanPhoneNumber($userOrPhone);

        $sessionFile = storage_path("app/maganghub_sessions/{$phone}.json");
        File::ensureDirectoryExists(dirname($sessionFile));

        // Validate JSON
        $decoded = json_decode($jsonContent, true);
        if (! is_array($decoded)) {
            return false;
        }

        return file_put_contents($sessionFile, json_encode($decoded, JSON_PRETTY_PRINT)) !== false;
    }
}
