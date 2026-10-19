<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\View\Internal\RunScope;
use Quittio\Core\View\MandatoryFieldGuard;
use Quittio\Core\View\Markdown;
use Quittio\Core\View\ViewModelFactory;

/**
 * Legal notes, other notes, attachments and the texts of the presentation.
 *
 * @internal
 */
final readonly class NotesSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @return array<string, mixed> legalNotes, notes, attachments, texts
     */
    public function build(): array
    {
        $presentation = $this->scope->presentation;

        $legalNotes = ViewModelFactory::legalNoteTexts($this->scope->invoice);
        // A PDF next to an XML-only e-invoice (run option visualization_of): its readable copy.
        $visualizes = $this->scope->context->options['visualization_of'] ?? null;
        if (is_string($visualizes) && $visualizes !== '') {
            array_unshift($legalNotes, strtr($this->scope->labels['einvoice_visualization'] ?? '', [':format' => $visualizes, ':number' => $this->scope->invoice->number]));
        }

        return [
            // Exemption reasons and regulatory notes that must appear on the invoice.
            'legalNotes' => $legalNotes,
            'notes' => $this->notes(),
            'attachments' => array_map(static fn ($a): array => [
                'id' => $a->id,
                'description' => $a->description,
                'filename' => $a->filename,
                'uri' => $a->externalUri,
            ], $this->scope->invoice->attachments),
            'texts' => [
                'salutation' => $presentation->salutation,
                'intro' => Markdown::toHtml($presentation->introText),
                'closing' => Markdown::toHtml($presentation->closingText),
            ],
        ];
    }

    /**
     * @return list<array{subject: ?string, content: string, html: string}>
     */
    private function notes(): array
    {
        $result = [];
        foreach ($this->scope->invoice->notes as $note) {
            if (in_array($note->subjectCode, MandatoryFieldGuard::LEGAL_NOTE_SUBJECTS, true)) {
                continue;
            }
            $result[] = ['subject' => $note->subjectCode?->value, 'content' => $note->content, 'html' => $this->scope->textHtml($note->content)];
        }

        return $result;
    }
}
