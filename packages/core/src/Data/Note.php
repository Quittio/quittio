<?php

declare(strict_types=1);

namespace Quittio\Core\Data;

use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Core\Mapping\Attributes as Q;
use Quittio\Core\Profiles\ProfileLevel;

/**
 * Invoice note (BG-1).
 */
final readonly class Note
{
    use Immutable;

    public function __construct(
        #[Q\Bt('BT-22'), Q\Cardinality('1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:IncludedNote/ram:Content'), Q\Ubl('cbc:Note')]
        public string $content,
        #[Q\Bt('BT-21'), Q\Cardinality('0..1'), Q\Level(ProfileLevel::BasicWl), Q\Cii('ram:IncludedNote/ram:SubjectCode'), Q\Ubl('cbc:Note (prefix #CODE#)')]
        public NoteSubjectCode|RawCode|null $subjectCode = null,
    ) {}
}
