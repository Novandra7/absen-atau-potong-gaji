<?php

namespace Tests\Feature;

use App\Models\InternUser;
use App\Services\WahaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class LogoutFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_clear_magang_session_removes_session_file_and_profile_directory(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6285163235033',
            'maganghub_email' => 'novan@example.com',
            'maganghub_password' => 'secret123',
            'is_active' => true,
        ]);

        $sessionFile = $user->getSessionFilePath();
        $profileDir = $user->getProfilePath();

        File::ensureDirectoryExists(dirname($sessionFile));
        File::ensureDirectoryExists($profileDir);
        file_put_contents($sessionFile, '{"cookies":[]}');
        file_put_contents($profileDir.'/test.txt', 'profile data');

        $this->assertFileExists($sessionFile);
        $this->assertDirectoryExists($profileDir);

        $result = $user->clearMagangSession();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($sessionFile);
        $this->assertDirectoryDoesNotExist($profileDir);

        $user->refresh();
        $this->assertNull($user->maganghub_email);
        $this->assertNull($user->maganghub_password);
    }

    public function test_webhook_handles_logout_command_successfully(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6285163235033',
            'maganghub_email' => 'novan@example.com',
            'is_active' => true,
        ]);

        $sessionFile = $user->getSessionFilePath();
        File::ensureDirectoryExists(dirname($sessionFile));
        file_put_contents($sessionFile, '{"cookies":[]}');

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->with('6285163235033', Mockery::on(fn ($msg) => str_contains($msg, 'Berhasil Logout dari MagangHub!')))
            ->andReturn(true);
        $this->app->instance(WahaService::class, $wahaMock);

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6285163235033@c.us',
                    'fromMe' => false,
                    'body' => 'LOGOUT',
                    'id' => 'msg_logout_001',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'logged_out']);

        $this->assertFileDoesNotExist($sessionFile);
    }

    public function test_webhook_handles_logout_when_no_active_session(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6285163235033',
            'maganghub_email' => null,
            'maganghub_password' => null,
            'is_active' => true,
        ]);

        $wahaMock = Mockery::mock(WahaService::class);
        $wahaMock->shouldReceive('sendMessage')
            ->once()
            ->with('6285163235033', Mockery::on(fn ($msg) => str_contains($msg, 'Tidak Ada Sesi Aktif')))
            ->andReturn(true);
        $this->app->instance(WahaService::class, $wahaMock);

        $response = $this->withHeaders(['X-Api-Key' => 'test_secret'])
            ->postJson('/api/webhooks/waha', [
                'event' => 'message',
                'payload' => [
                    'from' => '6285163235033@c.us',
                    'fromMe' => false,
                    'body' => 'LOGOUT',
                    'id' => 'msg_logout_002',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'already_logged_out']);
    }

    public function test_artisan_magang_logout_command_clears_session(): void
    {
        $user = InternUser::factory()->create([
            'phone_number' => '6285163235033',
            'name' => 'Novan',
            'is_active' => true,
        ]);

        $sessionFile = $user->getSessionFilePath();
        File::ensureDirectoryExists(dirname($sessionFile));
        file_put_contents($sessionFile, '{"cookies":[]}');

        $this->artisan('magang:logout', ['phone' => '6285163235033'])
            ->expectsOutputToContain('Berhasil logout!')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($sessionFile);
    }
}
