<?php

namespace Tests\Unit;

use App\Services\ReportGeneratorService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportGeneratorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.azure.api_key', 'test-azure-api-key');
        Config::set('services.azure.endpoint', 'https://test-azure-resource.openai.azure.com/');
        Config::set('services.azure.deployment', 'gpt-4o-mini');
        Config::set('services.azure.api_version', '2024-08-01-preview');
    }

    public function test_it_builds_azure_endpoint_url_correctly(): void
    {
        $service = new ReportGeneratorService;
        $this->assertEquals(
            'https://test-azure-resource.openai.azure.com/openai/deployments/gpt-4o-mini/chat/completions?api-version=2024-08-01-preview',
            $service->getAzureUrl()
        );

        // Test with full endpoint provided directly
        Config::set('services.azure.endpoint', 'https://custom-domain.openai.azure.com/openai/deployments/my-model/chat/completions?api-version=2024-10-21');
        $customService = new ReportGeneratorService;
        $this->assertEquals(
            'https://custom-domain.openai.azure.com/openai/deployments/my-model/chat/completions?api-version=2024-10-21',
            $customService->getAzureUrl()
        );
    }

    public function test_it_generates_draft_successfully_using_azure_openai(): void
    {
        $expectedActivity = 'Mengembangkan endpoint API untuk manajemen data pengguna dan menulis unit test guna memastikan respons berjalan sesuai spesifikasi.';
        $expectedLearning = 'Memperdalam pemahaman tentang validasi request, penanganan error, dan praktik pengujian otomatis pada pengembangan layanan backend.';
        $expectedChallenges = 'Seluruh proses pengerjaan tugas berjalan lancar tanpa kendala teknis maupun hambatan operasional pada hari ini.';

        Http::fake([
            'https://test-azure-resource.openai.azure.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'uraianAktivitas' => $expectedActivity,
                                'pembelajaran' => $expectedLearning,
                                'kendala' => $expectedChallenges,
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ReportGeneratorService;
        $result = $service->generateDraft('Mengerjakan integrasi Azure API');

        $this->assertArrayHasKey('uraianAktivitas', $result);
        $this->assertArrayHasKey('pembelajaran', $result);
        $this->assertArrayHasKey('kendala', $result);

        $this->assertGreaterThanOrEqual(100, mb_strlen($result['uraianAktivitas']));
        $this->assertGreaterThanOrEqual(100, mb_strlen($result['pembelajaran']));
        $this->assertGreaterThanOrEqual(100, mb_strlen($result['kendala']));
        $this->assertEquals($expectedChallenges, $result['kendala']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('api-key', 'test-azure-api-key')
                && str_contains($request->url(), 'openai/deployments/gpt-4o-mini/chat/completions')
                && isset($request['messages'])
                && $request['messages'][0]['role'] === 'system'
                && str_contains($request['messages'][0]['content'], 'Kamu adalah asisten penyusun logbook harian magang (MagangHub)')
                && str_contains($request['messages'][0]['content'], 'PANJANG: tiap komponen WAJIB 120-160 karakter')
                && $request['response_format']['type'] === 'json_object';
        });
    }

    public function test_it_handles_markdown_wrapped_json_from_azure(): void
    {
        $expectedChallenges = 'Tidak ada hambatan teknis yang berarti selama proses konfigurasi dan integrasi API berlangsung dengan baik pada hari ini.';
        $content = "```json\n".json_encode([
            'uraianAktivitas' => 'Melakukan pembaruan komponen layanan API dengan mengintegrasikan provider Azure secara bertahap dan terencana.',
            'pembelajaran' => 'Mempelajari format JSON mode pada model bahasa besar guna menjamin konsistensi output data response sistem.',
            'kendala' => $expectedChallenges,
        ])."\n```";

        Http::fake([
            'https://test-azure-resource.openai.azure.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => $content,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new ReportGeneratorService;
        $result = $service->generateDraft('Melakukan update komponen layanan API');

        $this->assertGreaterThanOrEqual(100, mb_strlen($result['uraianAktivitas']));
        $this->assertGreaterThanOrEqual(100, mb_strlen($result['pembelajaran']));
        $this->assertGreaterThanOrEqual(100, mb_strlen($result['kendala']));
        $this->assertEquals($expectedChallenges, $result['kendala']);
    }

    public function test_it_falls_back_to_static_draft_when_all_fail(): void
    {
        Http::fake([
            'https://test-azure-resource.openai.azure.com/*' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $service = new ReportGeneratorService;
        $result = $service->generateDraft('Mengerjakan tugas harian magang');

        $this->assertArrayHasKey('uraianAktivitas', $result);
        $this->assertArrayHasKey('pembelajaran', $result);
        $this->assertArrayHasKey('kendala', $result);

        $this->assertGreaterThanOrEqual(100, mb_strlen($result['uraianAktivitas']));
        $this->assertGreaterThanOrEqual(100, mb_strlen($result['pembelajaran']));
        $this->assertGreaterThanOrEqual(100, mb_strlen($result['kendala']));
        $this->assertEquals('Seluruh proses pengerjaan tugas berjalan lancar tanpa kendala teknis maupun hambatan operasional pada hari ini.', $result['kendala']);
    }
}
