<?php

declare(strict_types=1);

namespace Quittio\Core\Builder\Concerns;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Attachments\Attachment;
use Quittio\Core\Data\Note;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Enums\NoteSubjectCode;

/**
 * Invoice notes (BG-1) and supporting documents (BG-24).
 *
 * Part of {@see InvoiceBuilder}, which holds the state; use the builder, not the trait.
 */
trait BuildsNotesAndAttachments
{
    /**
     * Adds an invoice note (BG-1): the text (BT-22) and an optional subject code (BT-21,
     * UNTDID 4451, e.g. "REG" for regulatory information). Unknown codes are kept as RawCode.
     */
    public function note(string $content, NoteSubjectCode|RawCode|string|null $subject = null): static
    {
        $this->notes[] = new Note(
            $content,
            is_string($subject) ? (NoteSubjectCode::tryFrom($subject) ?? new RawCode($subject)) : $subject,
        );

        return $this;
    }

    /**
     * Supporting document (BG-24), either embedded ($content) or as external link.
     */
    public function attachment(
        string $id,
        ?string $content = null,
        MimeType|string|null $mimeType = null,
        ?string $filename = null,
        ?string $description = null,
        ?string $externalUri = null,
    ): static {
        $this->attachments[] = new Attachment(
            id: $id,
            description: $description,
            externalUri: $externalUri,
            content: $content,
            mimeType: is_string($mimeType) ? MimeType::from($mimeType) : $mimeType,
            filename: $filename,
        );

        return $this;
    }
}
