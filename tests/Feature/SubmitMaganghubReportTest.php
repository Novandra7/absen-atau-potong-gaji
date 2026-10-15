<?php

namespace Tests\Feature;

use App\Jobs\SubmitMaganghubReport;
use App\Models\DailyReport;
use App\Models\InternUser;
use App\Services\MagangHubAutomationService;
use App\Services\ScriptBuilderService;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SubmitMaganghubReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_submits_report_successfully_and_sends_screenshot(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6281234567890',
            'maganghub_email' => 'test@maganghub.go.id',
            'is_active' => true,
        ]);

        $report = DailyReport::factory()->create([
            'sender_phone' => $user->phone_number,
            'activity_description' => 'Mengerjakan fitur auth',
            'lessons_learned' => 'Belajar JWT',
            'challenges' => 'Tidak ada',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        // Create temporary screenshot file
        $tempScreenshot = tempnam(sys_get_temp_dir(), 'test_sc_').'.png';
        file_put_contents($tempScreenshot, 'fake image content');

        $automatorMock = Mockery::mock(MagangHubAutomationService::class);
        $automatorMock->shouldReceive('submitReport')
            ->once()
            ->with($report, true)
            ->andReturn([
                'success' => true,
                'screenshot' => $tempScreenshot,
                'error' => null,
                'message' => 'Laporan berhasil diisi dan dikirim.',
            ]);

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendImage')
            ->once()
            ->with($user->phone_number, $tempScreenshot, Mockery::type('string'))
            ->andReturn(true);

        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->with($user->phone_number, Mockery::on(fn ($msg) => str_contains($msg, 'Laporan MagangHub Berhasil Terkirim!')))
            ->andReturn(true);

        $job = new SubmitMaganghubReport($report);
        $job->handle($automatorMock, $wahaMock, new ScriptBuilderService);

        $report->refresh();
        $this->assertEquals(DailyReport::STATUS_SUBMITTED, $report->status);
        $this->assertNotNull($report->submitted_at);
        $this->assertEquals($tempScreenshot, $report->screenshot_path);
        $this->assertNull($report->error_message);

        @unlink($tempScreenshot);
    }

    public function test_it_handles_failure_and_sends_fallback_script(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6281234567890',
            'is_active' => true,
        ]);

        $report = DailyReport::factory()->create([
            'sender_phone' => $user->phone_number,
            'activity_description' => 'Mengerjakan fitur auth',
            'lessons_learned' => 'Belajar JWT',
            'challenges' => 'Tidak ada',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $automatorMock = Mockery::mock(MagangHubAutomationService::class);
        $automatorMock->shouldReceive('submitReport')
            ->once()
            ->with($report, true)
            ->andReturn([
                'success' => false,
                'screenshot' => null,
                'error' => 'Sesi login MagangHub tidak ditemukan atau kedaluwarsa.',
                'message' => null,
            ]);

        $sentMessages = [];
        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->times(3)
            ->andReturnUsing(function ($phone, $message) use (&$sentMessages) {
                $sentMessages[] = $message;

                return true;
            });

        $job = new SubmitMaganghubReport($report);
        $job->handle($automatorMock, $wahaMock, new ScriptBuilderService);

        $report->refresh();
        $this->assertEquals(DailyReport::STATUS_FAILED, $report->status);
        $this->assertEquals('Sesi login MagangHub tidak ditemukan atau kedaluwarsa.', $report->error_message);

        $this->assertCount(3, $sentMessages);
        $this->assertStringContainsString('Pengisian Otomatis ke MagangHub Menemui Kendala', $sentMessages[0]);
        $this->assertStringContainsString('const values = {', $sentMessages[1]);
        $this->assertStringContainsString('https://monev.maganghub.kemnaker.go.id/dashboard', $sentMessages[2]);
    }

    public function test_it_handles_preview_mode_successfully(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6281234567890',
            'is_active' => true,
        ]);

        $report = DailyReport::factory()->create([
            'sender_phone' => $user->phone_number,
            'activity_description' => 'Mengerjakan fitur auth',
            'lessons_learned' => 'Belajar JWT',
            'challenges' => 'Tidak ada',
            'status' => DailyReport::STATUS_AWAITING_APPROVAL,
        ]);

        $tempScreenshot = tempnam(sys_get_temp_dir(), 'test_sc_').'.png';
        file_put_contents($tempScreenshot, 'fake image content');

        $automatorMock = Mockery::mock(MagangHubAutomationService::class);
        $automatorMock->shouldReceive('submitReport')
            ->once()
            ->with($report, false)
            ->andReturn([
                'success' => true,
                'screenshot' => $tempScreenshot,
                'error' => null,
                'message' => 'Laporan berhasil diisi (draf).',
            ]);

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendImage')
            ->once()
            ->with($user->phone_number, $tempScreenshot, Mockery::type('string'))
            ->andReturn(true);

        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->with($user->phone_number, Mockery::on(fn ($msg) => str_contains($msg, 'Pratinjau Pengisian MagangHub Berhasil!')))
            ->andReturn(true);

        $job = new SubmitMaganghubReport($report, submitForm: false);
        $job->handle($automatorMock, $wahaMock, new ScriptBuilderService);

        $report->refresh();
        $this->assertEquals(DailyReport::STATUS_AWAITING_APPROVAL, $report->status);
        $this->assertEquals($tempScreenshot, $report->screenshot_path);

        @unlink($tempScreenshot);
    }
}
