<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyReport;
use App\Models\DailyReport;
use App\Services\ReportGeneratorService;
use App\Services\ScriptBuilderService;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateDailyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_3_points_and_sends_preview_and_script(): void
    {
        $report = DailyReport::factory()->create([
            'activity_notes' => 'Hari ini saya menindaklanjuti beberapa pertanyaan terkait Bruno API dan materi Mamitalks.',
            'sender_phone' => '6281234567890',
            'status' => DailyReport::STATUS_GENERATING_DRAFT,
        ]);

        // Mock ReportGeneratorService
        $generatorMock = Mockery::mock(ReportGeneratorService::class);
        $generatorMock->shouldReceive('generateDraft')
            ->once()
            ->andReturn([
                'uraianAktivitas' => 'Menindaklanjuti pertanyaan terkait Bruno API Tool.',
                'pembelajaran' => 'Memperoleh pemahaman lebih lanjut mengenai Bruno API.',
                'kendala' => 'Tidak ada kendala signifikan yang dihadapi hari ini.',
            ]);

        // Mock WahaService to verify the draft preview message is sent
        $sentMessages = [];
        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function ($phone, $message) use (&$sentMessages) {
                $sentMessages[] = $message;

                return true;
            });

        $job = new GenerateDailyReport($report);
        $job->handle($generatorMock, new ScriptBuilderService, $wahaMock);

        // Verify database updated
        $report->refresh();
        $this->assertEquals(DailyReport::STATUS_AWAITING_APPROVAL, $report->status);
        $this->assertEquals('Menindaklanjuti pertanyaan terkait Bruno API Tool.', $report->activity_description);
        $this->assertEquals('Memperoleh pemahaman lebih lanjut mengenai Bruno API.', $report->lessons_learned);
        $this->assertEquals('Tidak ada kendala signifikan yang dihadapi hari ini.', $report->challenges);

        // Verify preview message sent with action commands
        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString('Draf Laporan MagangHub Disiapkan!', $sentMessages[0]);
        $this->assertStringContainsString('Menindaklanjuti pertanyaan terkait Bruno API Tool.', $sentMessages[0]);
        $this->assertStringContainsString('Memperoleh pemahaman lebih lanjut mengenai Bruno API.', $sentMessages[0]);
        $this->assertStringContainsString('Tidak ada kendala signifikan yang dihadapi hari ini.', $sentMessages[0]);
        $this->assertStringContainsString('Balas *KIRIM*', $sentMessages[0]);
    }
}
