<?php

declare(strict_types=1);

namespace Quittio\Core\Documents;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Document specific line details, keyed by line identifier in {@see DocumentData}.
 */
final readonly class DocumentLineOptions
{
    use Immutable;

    /**
     * @param  bool  $optional  optional quote line, not included in the totals
     * @param  string|null  $alternativeTo  line identifier this line is an alternative for, not included in the totals
     * @param  list<string>  $serialNumbers  delivery note
     */
    public function __construct(
        public bool $optional = false,
        public ?string $alternativeTo = null,
        #[Q\ListOf('string')]
        public array $serialNumbers = [],
    ) {}

    /**
     * Whether the line counts towards the totals, i.e. it is neither optional nor an alternative.
     */
    public function isBillable(): bool
    {
        return ! $this->optional && $this->alternativeTo === null;
    }
}
