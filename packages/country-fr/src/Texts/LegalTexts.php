<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Texts;

use Quittio\Core\I18n\Translations;
use Quittio\Country\FR\CountryFR;

/**
 * Legal mentions of French invoices, from the pack's language files (resources/lang/{locale}/legal.php).
 *
 * Not reviewed by a tax adviser yet (see the README of the pack).
 */
final class LegalTexts
{
    /** Late payment penalties, the fixed indemnity of 40 EUR and the discount (art. L441-9 and L441-10 Code de commerce). */
    public const string LATE_PAYMENT = 'late_payment';

    /** The option to pay VAT on debits (art. 242 nonies A CGI, from 1 September 2026). */
    public const string VAT_ON_DEBITS = 'vat_on_debits';

    public const string SELF_BILLING = 'self_billing';

    /**
     * The text in the locale's language, French for other languages.
     */
    public static function text(string $key, ?string $locale = null): string
    {
        CountryFR::registerTranslations();
        $language = strtolower(explode('_', str_replace('-', '_', $locale ?? 'fr'))[0]);

        return Translations::translator()->get(CountryFR::TRANSLATION_NAMESPACE.'::legal.'.$key, [], in_array($language, CountryFR::locales(), true) ? $language : 'fr');
    }
}
