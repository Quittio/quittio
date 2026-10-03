<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Rules;

use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;

/**
 * Error classes of the BMF letter of 15 October 2025 (specification 12.2).
 */
enum BmfErrorClass: string
{
    /** Format errors (marginal no. 6a): the document is not an e-invoice. */
    case Format = 'format';

    /** Business rule errors (marginal no. 6b). */
    case BusinessRule = 'business_rule';

    /** Content errors (marginal no. 35a): mandatory information is wrong or missing. */
    case Content = 'content';

    public static function of(ValidationMessage $message): self
    {
        if (str_starts_with($message->code, 'DE-USTG-')) {
            return self::Content;
        }

        return match ($message->level) {
            ValidationLevel::L1, ValidationLevel::L2, ValidationLevel::L4, ValidationLevel::L6 => self::Format,
            ValidationLevel::L3, ValidationLevel::L5 => self::BusinessRule,
            ValidationLevel::L7 => self::Content,
        };
    }

    public function label(string $locale = 'de'): string
    {
        return match ($this) {
            self::Format => $locale === 'de' ? 'Formatfehler (Rz. 6a)' : 'Format error (marginal no. 6a)',
            self::BusinessRule => $locale === 'de' ? 'Geschäftsregelfehler (Rz. 6b)' : 'Business rule error (marginal no. 6b)',
            self::Content => $locale === 'de' ? 'Inhaltsfehler (Rz. 35a)' : 'Content error (marginal no. 35a)',
        };
    }

    /**
     * Whether the error turns an e-invoice into an "other invoice" in the sense of the UStG.
     */
    public function voidsEInvoice(): bool
    {
        return $this === self::Format;
    }
}
