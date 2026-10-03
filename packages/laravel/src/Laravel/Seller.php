<?php

declare(strict_types=1);

namespace Quittio\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Builder\PartyBuilder;
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
     * Sets seller and first bank account on a builder.
     */
    public static function apply(InvoiceBuilder $builder): InvoiceBuilder
    {
        $builder->seller(self::fromConfig());

        $accounts = self::settings()->array('bank_accounts');
        $first = $accounts[array_key_first($accounts) ?? ''] ?? null;
        if (is_array($first) && is_string($first['iban'] ?? null) && $first['iban'] !== '') {
            $builder->creditTransfer(
                $first['iban'],
                is_string($first['bic'] ?? null) ? $first['bic'] : null,
                is_string($first['name'] ?? null) ? $first['name'] : null,
            );
        }

        return $builder;
    }

    private static function settings(): Settings
    {
        $value = Container::getInstance()->make(Repository::class)->get('quittio.seller', []);

        return new Settings(is_array($value) ? $value : []);
    }
}
