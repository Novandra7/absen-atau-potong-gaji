<?php

namespace Tests\Unit;

use App\Services\ScriptBuilderService;
use PHPUnit\Framework\TestCase;

class ScriptBuilderTest extends TestCase
{
    public function test_it_builds_script_with_given_values(): void
    {
        $builder = new ScriptBuilderService;

        $script = $builder->build(
            uraianAktivitas: 'Menyelesaikan modul auth API',
            pembelajaran: 'Memahami event-driven architecture',
            kendala: 'Tidak ada kendala'
        );

        $this->assertStringContainsString('const values = {', $script);
        $this->assertStringContainsString('uraianAktivitas: `Menyelesaikan modul auth API`', $script);
        $this->assertStringContainsString('pembelajaran: `Memahami event-driven architecture`', $script);
        $this->assertStringContainsString('kendala: `Tidak ada kendala`', $script);
        $this->assertStringContainsString("document.querySelectorAll('.daily-form textarea')", $script);
        $this->assertStringContainsString("document.querySelector('.review-confirmation input')?.click()", $script);
    }

    public function test_it_escapes_backticks_and_template_literals(): void
    {
        $builder = new ScriptBuilderService;

        $script = $builder->build(
            uraianAktivitas: 'Mengerjakan file `routes/api.php` dan ${ENV_VAR}',
            pembelajaran: 'Belajar syntax `template literal`',
            kendala: 'Menemukan issue pada `${error}`'
        );

        $this->assertStringContainsString('\`routes/api.php\`', $script);
        $this->assertStringContainsString('\${ENV_VAR}', $script);
        $this->assertStringContainsString('\`template literal\`', $script);
        $this->assertStringContainsString('\${error}', $script);
    }
}
