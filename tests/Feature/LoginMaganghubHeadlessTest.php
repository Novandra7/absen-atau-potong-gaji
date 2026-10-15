<?php

namespace Tests\Feature;

use App\Jobs\LoginMaganghubHeadless;
use App\Models\InternUser;
use App\Services\MagangHubAutomationService;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class LoginMaganghubHeadlessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_executes_login_headless_successfully_and_sends_notification(): void
    {
        $user = InternUser::factory()->create([
            'name' => 'Novan',
            'phone_number' => '6285163235033',
            'is_active' => true,
        ]);

        $tempScreenshot = tempnam(sys_get_temp_dir(), 'test_login_').'.png';
        file_put_contents($tempScreenshot, 'fake image content');

        $automatorMock = Mockery::mock(MagangHubAutomationService::class);
        $automatorMock->shouldReceive('loginHeadless')
            ->once()
            ->with($user, 'novan@example.com', 'SandiRahasia123!')
            ->andReturn([
                'success' => true,
                'screenshot' => $tempScreenshot,
                'error' => null,
                'message' => 'Login otomatis berhasil!',
                'output' => null,
            ]);

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendImage')
            ->once()
            ->with($user->phone_number, $tempScreenshot, Mockery::type('string'))
            ->andReturn(true);

        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->with($user->phone_number, Mockery::on(fn ($msg) => str_contains($msg, 'Akun MagangHub Berhasil Terhubung!')))
            ->andReturn(true);

        $job = new LoginMaganghubHeadless($user, 'novan@example.com', 'SandiRahasia123!');
        $job->handle($automatorMock, $wahaMock);

        if (file_exists($tempScreenshot)) {
            @unlink($tempScreenshot);
        }
    }

    public function test_it_handles_login_headless_failure(): void
    {
        $user = InternUser::factory()->create([
            'name' => 'Novan',
            'phone_number' => '6285163235033',
            'is_active' => true,
        ]);

        $automatorMock = Mockery::mock(MagangHubAutomationService::class);
        $automatorMock->shouldReceive('loginHeadless')
            ->once()
            ->with($user, 'novan@example.com', 'WrongPassword!')
            ->andReturn([
                'success' => false,
                'screenshot' => null,
                'error' => 'No. HP / Email / NIK atau password tidak benar.',
                'message' => null,
                'output' => null,
            ]);

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->with($user->phone_number, Mockery::on(fn ($msg) => str_contains($msg, 'Gagal Menghubungkan Akun MagangHub') && str_contains($msg, 'tidak benar')))
            ->andReturn(true);

        $job = new LoginMaganghubHeadless($user, 'novan@example.com', 'WrongPassword!');
        $job->handle($automatorMock, $wahaMock);
    }

    public function test_it_skips_execution_when_another_job_is_already_running_for_same_email(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6285163235033',
            'is_active' => true,
        ]);

        Cache::put('login_maganghub_running_novan@example.com', true, 60);

        $automatorMock = Mockery::mock(MagangHubAutomationService::class);
        $automatorMock->shouldNotReceive('loginHeadless');

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldNotReceive('sendMessage');
        $wahaMock->shouldNotReceive('sendImage');

        $job = new LoginMaganghubHeadless($user, 'novan@example.com', 'Password123!');
        $job->handle($automatorMock, $wahaMock);
    }
}
