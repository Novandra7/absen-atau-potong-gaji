<?php

namespace App\Services;

class ScriptBuilderService
{
    /**
     * Build the browser console JavaScript snippet with injected report values.
     */
    public function build(string $uraianAktivitas, string $pembelajaran, string $kendala): string
    {
        $escape = static function (string $text): string {
            // Escape backticks and template string interpolation syntax
            return str_replace(['`', '${'], ['\`', '\${'], trim($text));
        };

        $valAktivitas = $escape($uraianAktivitas);
        $valPembelajaran = $escape($pembelajaran);
        $valKendala = $escape($kendala);

        return <<<JS
const values = {
  uraianAktivitas: `{$valAktivitas}`,

  pembelajaran: `{$valPembelajaran}`,

  kendala: `{$valKendala}`
};

const textareas = document.querySelectorAll('.daily-form textarea');

const fields = [
  'uraianAktivitas',
  'pembelajaran',
  'kendala'
];

textareas.forEach((textarea, index) => {
  const key = fields[index];

  if (key && values[key]) {
    textarea.value = values[key];
    textarea.dispatchEvent(
      new Event('input', { bubbles: true })
    );
    textarea.dispatchEvent(
      new Event('change', { bubbles: true })
    );
  }
});

document.querySelector('.review-confirmation input')?.click();
JS;
    }
}
