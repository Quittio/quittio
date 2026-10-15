<?php

declare(strict_types=1);

use Quittio\Country\DE\Texts\LegalTexts;

it('lets the application override the legal texts in lang/vendor/quittio-de', function () {
    $directory = app()->langPath('vendor/quittio-de/de');
    $file = $directory.'/legal.php';
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    file_put_contents($file, "<?php return ['consumer_default' => 'Eigener Hinweis zum Verzug.'];");

    try {
        expect(LegalTexts::text(LegalTexts::CONSUMER_DEFAULT))->toBe('Eigener Hinweis zum Verzug.')
            // Keys the application does not override keep the shipped text.
            ->and(LegalTexts::text(LegalTexts::SELF_BILLING))->toBe('Gutschrift');
    } finally {
        unlink($file);
    }
});
