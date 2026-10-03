<?php

declare(strict_types=1);

namespace Quittio\Laravel\Support;

use InvalidArgumentException;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Testing\Scenarios;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Rendering\RenderedInvoice;

/**
 * Renders the sample scenarios of specification 11.12 with a "PREVIEW" watermark.
 */
final readonly class Preview
{
    public function __construct(
        private QuittioManager $quittio,
    ) {}

    /**
     * @return list<string>
     */
    public function scenarios(): array
    {
        return Scenarios::names();
    }

    public function render(string $scenario, string $format = 'pdf', ?string $template = null, ?string $locale = null): RenderedInvoice
    {
        if (! in_array($scenario, Scenarios::names(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown scenario "%s". Known scenarios: %s.', $scenario, implode(', ', Scenarios::names())));
        }
        if (! in_array($format, ['pdf', 'html'], true)) {
            throw new InvalidArgumentException('The preview supports the formats pdf and html.');
        }

        $invoice = Scenarios::make($scenario)->build();
        $locale ??= $invoice->meta->locale ?? 'en';
        $pending = $this->quittio->make($invoice)
            ->format($format)
            ->locale($locale)
            ->validate(throwOnError: false)
            ->presentation(new Presentation(watermark: Translations::translator()->get('quittio::labels.preview', [], $locale)));

        if ($template !== null) {
            $pending->template($template);
        }

        return $pending->render();
    }
}
