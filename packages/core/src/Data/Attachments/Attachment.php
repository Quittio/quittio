<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Attachments;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\MimeType;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Additional supporting document (BG-24).
 */
final readonly class Attachment
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-122'), Q\Cardinality('1'), Q\Cii('ram:AdditionalReferencedDocument/ram:IssuerAssignedID'), Q\Ubl('cac:AdditionalDocumentReference/cbc:ID')]
        public string $id,
        #[Q\Bt('BT-123'), Q\Cardinality('0..1'), Q\Cii('ram:AdditionalReferencedDocument/ram:Name'), Q\Ubl('cac:AdditionalDocumentReference/cbc:DocumentDescription')]
        public ?string $description = null,
        #[Q\Bt('BT-124'), Q\Cardinality('0..1'), Q\Cii('ram:AdditionalReferencedDocument/ram:URIID'), Q\Ubl('cac:AdditionalDocumentReference/cac:Attachment/cac:ExternalReference/cbc:URI')]
        public ?string $externalUri = null,
        #[Q\Bt('BT-125'), Q\Cardinality('0..1'), Q\Binary, Q\Cii('ram:AdditionalReferencedDocument/ram:AttachmentBinaryObject'), Q\Ubl('cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject')]
        public ?string $content = null,
        #[Q\Bt('BT-125-1'), Q\Cardinality('0..1'), Q\Cii('ram:AttachmentBinaryObject/@mimeCode'), Q\Ubl('cbc:EmbeddedDocumentBinaryObject/@mimeCode')]
        public ?MimeType $mimeType = null,
        #[Q\Bt('BT-125-2'), Q\Cardinality('0..1'), Q\Cii('ram:AttachmentBinaryObject/@filename'), Q\Ubl('cbc:EmbeddedDocumentBinaryObject/@filename')]
        public ?string $filename = null,
    ) {
        if ($content !== null && ($mimeType === null || $filename === null || $filename === '')) {
            throw new InvalidArgumentException('An embedded attachment (BT-125) requires a MIME code (BT-125-1) and a file name (BT-125-2).');
        }
    }

    public function size(): int
    {
        return $this->content === null ? 0 : strlen($this->content);
    }
}
