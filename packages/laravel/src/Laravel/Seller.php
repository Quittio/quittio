<?php

declare(strict_types=1);

namespace Quittio\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PartyBuilder;
use Quittio\Core\Data\Meta;
use Quittio\Core\Data\Party\SellerParty;
use Quittio\Laravel\Support\Settings;

/**
 * Seller from config/quittio.php (when the Managed layer is not in use).
 */
final class Seller
{
    public static function fromConfig(): SellerParty
    {
        $seller = self::settings();
        $builder = (new PartyBuilder)
            ->name($seller->string('name', ''))
            ->vatId($seller->nullableString('vat_id'))
            ->taxNumber($seller->nullableString('tax_number'))
            ->address(
                $seller->nullableString('address.line1'),
                $seller->nullableString('address.postcode'),
                $seller->nullableString('address.city'),
                $seller->string('address.country', 'DE'),
                $seller->nullableString('address.line2'),
            );

        if ($seller->nullableString('legal_registration') !== null) {
            $builder->legalRegistration($seller->nullableString('legal_registration'));
        }
        foreach ($seller->array('identifiers') as $identifier) {
            // Seller identifier (BT-29), e.g. for a small business without VAT identifier (BR-CO-26).
            if (is_array($identifier) && is_string($identifier['value'] ?? null) && $identifier['value'] !== '') {
                $builder->identifier($identifier['value'], is_string($identifier['scheme'] ?? null) ? $identifier['scheme'] : null);
            } elseif (is_string($identifier) && $identifier !== '') {
                $builder->identifier($identifier);
            }
        }
        if ($seller->nullableString('additional_legal_info') !== null) {
            $builder->additionalLegalInfo($seller->nullableString('additional_legal_info'));
        }
        $contact = $seller->section('contact');
        if ($contact->nullableString('name') !== null || $contact->nullableString('phone') !== null || $contact->nullableString('email') !== null) {
            $builder->contact($contact->nullableString('name'), $contact->nullableString('phone'), $contact->nullableString('email'));
        }
        $electronic = $seller->nullableString('electronic_address.value');
        if ($electronic !== null) {
            $builder->electronicAddress($electronic, $seller->string('electronic_address.scheme', 'EM'));
        }

        return $builder->buildSeller();
    }

    /**
     * Sets seller and the first bank account with an IBAN on a builder. A small business seller
     * (quittio.seller.small_business) marks the invoice, so country packs reject VAT on it.
     */
    public static function apply(InvoiceBuilder $builder): InvoiceBuilder
    {
        $builder->seller(self::fromConfig());
        if (self::settings()->bool('small_business', false)) {
            $builder->flag(Meta::FLAG_SMALL_BUSINESS);
        }

        // The first account with an IBAN (the template ships an empty first entry).
        foreach (self::settings()->array('bank_accounts') as $account) {
            if (is_array($account) && is_string($account['iban'] ?? null) && trim($account['iban']) !== '') {
                $builder->creditTransfer(
                    $account['iban'],
                    is_string($account['bic'] ?? null) ? $account['bic'] : null,
                    is_string($account['name'] ?? null) ? $account['name'] : null,
                );
                break;
            }
        }

        return $builder;
    }

    private static function settings(): Settings
    {
        $value = Container::getInstance()->make(Repository::class)->get('quittio.seller', []);

        return new Settings(is_array($value) ? $value : []);
    }
}
