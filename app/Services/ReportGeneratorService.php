<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportGeneratorService
{
    protected ?string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = (string) config('services.gemini.model', 'gemini-2.5-flash');
    }

    /**
     * Generate 3-part structured daily report draft using Gemini AI.
     *
     * @return array{uraianAktivitas: string, pembelajaran: string, kendala: string}
     */
    public function generateDraft(string $rawNotes, ?string $previousDraft = null, ?string $revisionInstruction = null): array
    {
        $prompt = $this->buildPrompt($rawNotes, $previousDraft, $revisionInstruction);

        if (empty($this->apiKey)) {
            Log::warning('Gemini API key is not set. Using fallback report structure.');

            return $this->fallbackDraft($rawNotes);
        }

        $modelsToTry = array_values(array_unique(array_filter([
            $this->model,
            'gemini-flash-lite-latest',
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-flash-latest',
        ])));

        foreach ($modelsToTry as $currentModel) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent?key={$this->apiKey}";

                $response = Http::timeout(30)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                    if ($text) {
                        $decoded = json_decode($text, true);
                        if (is_array($decoded) && isset($decoded['uraianAktivitas'], $decoded['pembelajaran'], $decoded['kendala'])) {
                            return [
                                'uraianAktivitas' => $this->ensureMinLength(trim((string) $decoded['uraianAktivitas']), 'uraianAktivitas'),
                                'pembelajaran' => $this->ensureMinLength(trim((string) $decoded['pembelajaran']), 'pembelajaran'),
                                'kendala' => $this->ensureMinLength(trim((string) $decoded['kendala']), 'kendala'),
                            ];
                        }
                    }
                }

                Log::warning("Gemini API error with model {$currentModel}, trying fallback model if available.", [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("Gemini API exception with model {$currentModel}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('All Gemini AI candidate models failed. Using static fallback template.');

        return $this->fallbackDraft($rawNotes);
    }

    /**
     * Build the system & user instruction prompt.
     */
    protected function buildPrompt(string $rawNotes, ?string $previousDraft, ?string $revisionInstruction): string
    {
        $prompt = <<<EOT
Kamu adalah asisten profesional yang bertugas menyusun laporan harian magang (logbook MagangHub).
Ubah catatan harian mentah dari peserta magang menjadi draf laporan formal dalam bahasa Indonesia yang baku, jelas, dan profesional.

Draf laporan WAJIB memiliki tiga komponen:
1. "uraianAktivitas": Uraian runtut dan konkret mengenai tugas atau aktivitas yang dikerjakan hari ini.
2. "pembelajaran": Wawasan, keterampilan teknis, konsep, atau pemahaman baru yang dipelajari atau diperdalam dari aktivitas tersebut.
3. "kendala": Masalah, kendala teknis, atau hambatan yang dihadapi. Jika tidak ada kendala dalam catatan, jelaskan bahwa seluruh proses pengerjaan tugas berjalan lancar tanpa kendala teknis maupun hambatan operasional.

ATURAN PANJANG KARAKTER (SANGAT KETAT & WAJIB DIPATUHI):
- Setiap komponen ("uraianAktivitas", "pembelajaran", dan "kendala") WAJIB memiliki panjang MINIMAL 100 KARAKTER (termasuk spasi).
- JANGAN KURANG dari 100 karakter sama sekali (sistem MagangHub akan menolak jika kurang dari 100 karakter).
- JANGAN TERLALU JAUH MELEBIHI 100 karakter (jangan bertele-tele atau membuat paragraf panjang).
- Target panjang ideal adalah 100 hingga 130 karakter per komponen agar padat, ringkas, dan tepat memenuhi syarat validasi formulir.

ATURAN KETAT:
- Jangan mengarang aktivitas di luar apa yang disampaikan dalam catatan.
- Gunakan bahasa Indonesia baku, formal, dan profesional.
- Format balasan WAJIB berupa JSON murni dengan schema:
{"uraianAktivitas": "...", "pembelajaran": "...", "kendala": "..."}

Catatan Aktivitas Pengguna:
"""
{$rawNotes}
"""
EOT;

        if (! empty($previousDraft) && ! empty($revisionInstruction)) {
            $prompt .= <<<EOT


Draf Sebelumnya:
"""
{$previousDraft}
"""

Instruksi Revisi dari Pengguna:
"""
{$revisionInstruction}
"""
Harap perbaiki draf di atas sesuai instruksi revisi dari pengguna dengan tetap menjaga format JSON yang sama serta memenuhi aturan panjang minimal 100 karakter.
EOT;
        }

        return $prompt;
    }

    /**
     * Fallback structured draft when AI is unavailable or key is not set.
     *
     * @return array{uraianAktivitas: string, pembelajaran: string, kendala: string}
     */
    protected function fallbackDraft(string $rawNotes): array
    {
        return [
            'uraianAktivitas' => $this->ensureMinLength($rawNotes, 'uraianAktivitas'),
            'pembelajaran' => 'Memperdalam pemahaman teknis dan alur implementasi terkait tugas yang dikerjakan guna meningkatkan efektivitas kerja.',
            'kendala' => 'Secara keseluruhan kegiatan berjalan lancar dan tidak ada kendala signifikan yang menghambat penyelesaian tugas hari ini.',
        ];
    }

    /**
     * Ensure text meets the minimum character length required by MagangHub form (min 100 characters).
     */
    protected function ensureMinLength(string $text, string $type): string
    {
        $text = trim($text);
        if (mb_strlen($text) >= 100) {
            return $text;
        }

        $append = match ($type) {
            'uraianAktivitas' => ' serta memastikan seluruh tahapan tugas terselesaikan dengan baik.',
            'pembelajaran' => ' guna meningkatkan pemahaman teknis dan efektivitas kerja harian.',
            'kendala' => ' sehingga seluruh proses kegiatan berjalan lancar sesuai rencana.',
            default => ' serta memastikan seluruh proses berjalan terstruktur.',
        };

        $result = rtrim($text, '.').$append;
        while (mb_strlen($result) < 100) {
            $result .= ' Seluruh tahapan telah diselesaikan dengan optimal.';
        }

        return $result;
    }
}
