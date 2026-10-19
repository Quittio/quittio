<?php

declare(strict_types=1);

namespace Quittio\Country\CH\QrBill;

use Brick\Math\BigDecimal;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Payment\CreditTransfer;
use Quittio\Core\I18n\Translations;
use Quittio\Core\Pdf\Qr\PaymentSlip;
use Quittio\Core\Pdf\Qr\ProvidesPaymentSlip;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Validation\Plausibility\Iban;
use Quittio\Country\CH\CountryCH;

/**
 * The Swiss QR bill: the Swiss QR Code (Swiss Payments Code "SPC", version 0200) and its payment
 * part with receipt, after the Swiss Implementation Guidelines QR-bill 2.3 of SIX.
 *
 * - Account: the first credit transfer account (BT-84) with an IBAN of Switzerland or
 *   Liechtenstein; a QR-IBAN (institution identification 30000 to 31999) needs a QR reference.
 * - Reference: the remittance information (BT-83) if it is a QR reference (with a QR-IBAN) or a
 *   Creditor Reference (with an IBAN); with a QR-IBAN otherwise the QR reference of the digits of
 *   the invoice number. Without one the code has no reference (NON) and the remittance information
 *   or the invoice number as unstructured message.
 * - Creditor: the account holder (or the seller) with the seller's address; debtor: the buyer.
 *   Addresses are structured (type "S"): a building number at the end of the street is
 *   written as building number.
 * - Amount: the amount due (BT-115), in CHF or EUR.
 */
final class SwissQrBillGenerator implements ProvidesPaymentSlip
{
    public const string KEY = 'swiss-qr-bill';

    /** Characters allowed in the Swiss QR Code (guidelines 4.1.1). */
    private const string NOT_ALLOWED = '/[^\x{20}-\x{7E}\x{A0}-\x{17F}\x{218}-\x{21B}\x{20AC}]/u';

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(InvoiceData $invoice): bool
    {
        return in_array($invoice->currency, ['CHF', 'EUR'], true)
            && self::account($invoice) !== null
            && self::complete($invoice->seller->address);
    }

    /**
     * @param  array<string, mixed>  $options  "value": the amount instead of the amount due; "remittance": the
     *         remittance information instead of BT-83
     */
    public function payload(InvoiceData $invoice, array $options = []): ?string
    {
        $data = $this->data($invoice, $options);

        return $data === null ? null : implode("\n", $data['lines']);
    }

    public function slip(InvoiceData $invoice, string $payload, string $locale): ?PaymentSlip
    {
        $data = $this->data($invoice, []);
        if ($data === null || implode("\n", $data['lines']) !== $payload) {
            return null;
        }

        CountryCH::registerTranslations();
        $translator = Translations::translator();
        $language = in_array($l = strtolower(substr($locale, 0, 2)), ['de', 'fr', 'it', 'en'], true) ? $l : 'de';
        $label = static fn (string $key): string => $translator->get(CountryCH::TRANSLATION_NAMESPACE.'::qrbill.'.$key, [], $language);

        $account = ['heading' => $label('account'), 'lines' => [self::formatIban($data['iban']), ...self::addressLines($data['creditor'])]];
        $reference = $data['reference'] === null ? [] : [['heading' => $label('reference'), 'lines' => [$data['referenceType'] === 'QRR' ? QrReference::format($data['reference']) : CreditorReference::format($data['reference'])]]];
        $debtor = $data['debtor'] === null
            ? ['heading' => $label('payable_by_blank'), 'lines' => []]
            : ['heading' => $label('payable_by'), 'lines' => self::addressLines($data['debtor'])];
        $additional = $data['message'] === '' ? [] : [['heading' => $label('additional_information'), 'lines' => [$data['message']]]];

        return new PaymentSlip(
            title: $label('payment_part'),
            receiptTitle: $label('receipt'),
            sections: [$account, ...$reference, ...$additional, $debtor],
            receiptSections: [$account, ...$reference, $debtor],
            currencyHeading: $label('currency'),
            amountHeading: $label('amount'),
            currency: $invoice->currency,
            amount: $data['amount'] === null ? null : self::formatAmount($data['amount']),
            acceptancePoint: $label('acceptance_point'),
            separationNote: $label('separate'),
            mark: SwissCross::dataUri(),
        );
    }

    /**
     * Whether the IBAN is a QR-IBAN (institution identification 30000 to 31999).
     */
    public static function isQrIban(string $iban): bool
    {
        $iban = Iban::normalize($iban);
        $iid = (int) substr($iban, 4, 5);

        return in_array(substr($iban, 0, 2), ['CH', 'LI'], true) && $iid >= 30000 && $iid <= 31999;
    }

    /**
     * The fields of the code: the lines of the payload and the values printed on the slip.
     *
     * @param  array<string, mixed>  $options
     * @return array{lines: list<string>, iban: string, creditor: array{name: string, street: string, number: string, postcode: string, city: string, country: string}, debtor: array{name: string, street: string, number: string, postcode: string, city: string, country: string}|null, amount: ?BigDecimal, referenceType: string, reference: ?string, message: string}|null
     */
    private function data(InvoiceData $invoice, array $options): ?array
    {
        $account = self::account($invoice);
        if ($account === null || ! in_array($invoice->currency, ['CHF', 'EUR'], true) || ! self::complete($invoice->seller->address)) {
            return null;
        }

        $value = $options['value'] ?? null;
        $amount = $value instanceof BigDecimal ? $value : $invoice->totals?->amountDue->getAmount();
        if ($amount !== null && (! $amount->isPositive() || $amount->isGreaterThan('999999999.99'))) {
            return null;
        }

        $iban = Iban::normalize($account->accountId);
        $remittance = trim((string) (is_string($options['remittance'] ?? null) ? $options['remittance'] : $invoice->paymentInstructions?->remittanceInformation));
        [$type, $reference, $message] = self::reference($iban, $remittance, $invoice->number);
        if ($type === null) {
            return null;
        }

        $creditor = self::structured($account->accountName ?? $invoice->seller->name, $invoice->seller->address);
        $debtor = self::complete($invoice->buyer->address) ? self::structured($invoice->buyer->name, $invoice->buyer->address) : null;

        $lines = [
            'SPC', '0200', '1',
            $iban,
            ...self::partyFields($creditor),
            // Ultimate creditor: for future use, must stay empty.
            '', '', '', '', '', '', '',
            $amount === null ? '' : Decimal::round($amount, 2)->toString(),
            $invoice->currency,
            ...self::partyFields($debtor),
            $type,
            (string) $reference,
            $message,
            'EPD',
        ];

        return [
            'lines' => $lines,
            'iban' => $iban,
            'creditor' => $creditor,
            'debtor' => $debtor,
            'amount' => $amount,
            'referenceType' => $type,
            'reference' => $reference,
            'message' => $message,
        ];
    }

    /**
     * The seven fields of a party in the code: address type "S", name, street, building number,
     * post code, town and country; empty for no party.
     *
     * @param  array{name: string, street: string, number: string, postcode: string, city: string, country: string}|null  $party
     * @return list<string>
     */
    private static function partyFields(?array $party): array
    {
        return $party === null ? ['', '', '', '', '', '', ''] : ['S', $party['name'], $party['street'], $party['number'], $party['postcode'], $party['city'], $party['country']];
    }

    /**
     * The first credit transfer account with an IBAN of Switzerland or Liechtenstein.
     */
    private static function account(InvoiceData $invoice): ?CreditTransfer
    {
        foreach ($invoice->paymentInstructions->creditTransfers ?? [] as $account) {
            $iban = Iban::normalize($account->accountId);
            if (strlen($iban) === 21 && in_array(substr($iban, 0, 2), ['CH', 'LI'], true) && Iban::isValid($iban)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Reference type, reference and unstructured message; a null type when a QR-IBAN has no QR
     * reference (an invoice number without digits).
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private static function reference(string $iban, string $remittance, string $number): array
    {
        $compact = str_replace(' ', '', $remittance);
        if (self::isQrIban($iban)) {
            if (QrReference::isValid($compact)) {
                return ['QRR', $compact, self::text($number, 140)];
            }
            $reference = QrReference::fromDigitsOf($number);

            return [$reference === null ? null : 'QRR', $reference, self::text($remittance !== '' ? $remittance : $number, 140)];
        }
        if (CreditorReference::isValid($compact)) {
            return ['SCOR', strtoupper($compact), self::text($number, 140)];
        }

        return ['NON', null, self::text($remittance !== '' ? $remittance : $number, 140)];
    }

    private static function complete(Address $address): bool
    {
        return trim((string) $address->postcode) !== '' && trim((string) $address->city) !== '' && preg_match('/^[A-Z]{2}$/', $address->countryCode) === 1;
    }

    /**
     * A structured address: a building number at the end of the street becomes the building number.
     *
     * @return array{name: string, street: string, number: string, postcode: string, city: string, country: string}
     */
    private static function structured(string $name, Address $address): array
    {
        $street = trim((string) $address->line1);
        $number = '';
        if (preg_match('/^(.*\S)\s+(\d+\s?[a-zA-Z]?(?:\s?[-\/]\s?\d+\s?[a-zA-Z]?)?)$/u', $street, $match) === 1 && mb_strlen($match[2]) <= 16) {
            [$street, $number] = [$match[1], $match[2]];
        }

        return [
            'name' => self::text($name, 70),
            'street' => self::text($street, 70),
            'number' => self::text($number, 16),
            'postcode' => self::text((string) $address->postcode, 16),
            'city' => self::text((string) $address->city, 35),
            'country' => $address->countryCode,
        ];
    }

    /**
     * The lines of an address on the slip: name, street with number, post code and town; the
     * country code before the post code outside Switzerland.
     *
     * @param  array{name: string, street: string, number: string, postcode: string, city: string, country: string}  $party
     * @return list<string>
     */
    private static function addressLines(array $party): array
    {
        $street = trim($party['street'].' '.$party['number']);
        $place = trim(($party['country'] === 'CH' ? '' : $party['country'].'-').$party['postcode'].' '.$party['city']);

        return array_values(array_filter([$party['name'], $street, $place], static fn (string $line): bool => $line !== ''));
    }

    /**
     * Text with the characters of the Swiss QR Code only, without line breaks, at most $length characters.
     */
    private static function text(string $value, int $length): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr((string) preg_replace(self::NOT_ALLOWED, '', $value), 0, $length);
    }

    private static function formatIban(string $iban): string
    {
        return implode(' ', str_split($iban, 4));
    }

    /**
     * The amount with a space as thousands separator and two decimals, e.g. "1 590.00".
     */
    private static function formatAmount(BigDecimal $amount): string
    {
        [$integer, $fraction] = explode('.', Decimal::round($amount, 2)->toString());

        return strrev(implode(' ', str_split(strrev($integer), 3))).'.'.$fraction;
    }
}
