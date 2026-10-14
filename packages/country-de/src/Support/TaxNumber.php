<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Support;

/**
 * German tax number (Steuernummer, BT-32) as described in "Prüfung der Steuer- und
 * Steueridentifikationsnummer" of the Bayerisches Landesamt für Steuern (ELSTER, as of
 * 2 September 2026): the 13-digit ELSTER format FFFF0BBBUUUUP (NRW: FFFF0BBBBUUUP), the
 * 12-digit federal format without the 0, and the notation of the federal states on tax
 * notices (e.g. 181/815/08155 in Bavaria, 133/8150/8159 in NRW).
 *
 * A number is valid when it passes the formal checks and the check digit procedure of its
 * state: the modified 11 procedure (Rhineland-Palatinate), the 2 procedure (Baden-Württemberg,
 * Hesse, Schleswig-Holstein) or the 11 procedure with the factors of the state (all others,
 * Berlin with the variants A and B). A state notation without the state is checked against
 * every state that uses it; as several states share a notation (e.g. FF/BBB/UUUUP), parse()
 * then returns the first valid interpretation unless the state is given. Whether the tax office
 * exists (ELSTER list of tax offices) is not checked.
 */
final readonly class TaxNumber
{
    /** First digits of the ELSTER format (Bundesfinanzamtsnummer) => state (ISO 3166-2:DE). */
    public const array STATE_PREFIXES = [
        '10' => 'SL', '11' => 'BE', '21' => 'SH', '22' => 'HH', '23' => 'NI', '24' => 'HB',
        '26' => 'HE', '27' => 'RP', '28' => 'BW', '30' => 'BB', '31' => 'ST', '32' => 'SN',
        '40' => 'MV', '41' => 'TH', '5' => 'NW', '9' => 'BY',
    ];

    /** States with a two-digit tax office number in their notation (FF/BBB/UUUUP) => ELSTER prefix. */
    private const array TWO_DIGIT_STATES = ['BW' => '28', 'BE' => '11', 'HB' => '24', 'HH' => '22', 'NI' => '23', 'RP' => '27', 'SH' => '21', 'HE' => '26'];

    /** States of the Bavarian group: notation FFF/BBB/UUUUP, district at least 100. */
    private const array BAVARIAN_GROUP = ['BY', 'BB', 'MV', 'SL', 'SN', 'ST', 'TH'];

    /** Factors of the 11 procedure for the digits 1–12 of the ELSTER format. */
    private const array FACTORS_11 = [
        'BY' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'BB' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'MV' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'SL' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'SN' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'ST' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'TH' => [0, 5, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'HB' => [0, 0, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'HH' => [0, 0, 4, 3, 0, 2, 7, 6, 5, 4, 3, 2],
        'NI' => [0, 0, 2, 9, 0, 8, 7, 6, 5, 4, 3, 2],
        'NW' => [0, 3, 2, 1, 0, 7, 6, 5, 4, 3, 2, 1],
        'BE-A' => [0, 0, 0, 0, 0, 7, 6, 5, 8, 4, 3, 2],
        'BE-B' => [0, 0, 2, 9, 0, 8, 7, 6, 5, 4, 3, 2],
    ];

    private const array SUMMANDS_2 = [0, 0, 9, 8, 0, 7, 6, 5, 4, 3, 2, 1];

    private const array FACTORS_2 = [0, 0, 512, 256, 0, 128, 64, 32, 16, 8, 4, 2];

    private const array FACTORS_RP = [0, 0, 1, 2, 0, 1, 2, 1, 2, 1, 2, 1];

    /**
     * Berlin tax offices and their check digit procedure: B, or A with B for the listed districts.
     * Offices not listed (e.g. the test system 1197 of the ELSTER examples) use B.
     */
    private const array BERLIN = [
        '1113' => [[201, 693]], '1114' => [[201, 693]], '1116' => [[1, 29], [201, 693], [875, 899]],
        '1117' => [[201, 693]], '1119' => [[201, 639], [680, 680], [684, 684]], '1120' => [[201, 693]],
        '1121' => [[201, 693]], '1123' => [[201, 693]], '1124' => [[201, 693]], '1125' => [[201, 693]],
        '1127' => [], '1129' => [], '1130' => [],
        '1115' => 'B', '1118' => 'B', '1131' => 'B', '1132' => 'B', '1133' => 'B', '1134' => 'B', '1135' => 'B',
        '1136' => 'B', '1137' => 'B', '1138' => 'B', '1194' => 'B', '1195' => 'B', '1196' => 'B',
    ];

    private function __construct(
        /** 13 digits */
        public string $elster,
        /** ISO 3166-2:DE code of the state, e.g. BY */
        public string $state,
    ) {}

    /**
     * The valid tax number in any notation, or null when it is invalid or cannot be checked.
     *
     * @param  string|null  $state  ISO 3166-2:DE code (e.g. BY) to read a state notation
     */
    public static function parse(string $value, ?string $state = null): ?self
    {
        foreach (self::candidates($value, $state) as $elster) {
            $candidateState = self::stateOf($elster);
            if ($candidateState !== null && ($state === null || $state === $candidateState) && self::isValidElster($elster, $candidateState)) {
                return new self($elster, $candidateState);
            }
        }

        return null;
    }

    /**
     * true = valid, false = invalid, null = plausible but not checkable (a Bavarian notation
     * without the tax office number, BBB/UUUUP).
     */
    public static function validate(string $value, ?string $state = null): ?bool
    {
        if (self::parse($value, $state) !== null) {
            return true;
        }
        $digits = preg_replace('/[\s\/.-]/', '', $value) ?? '';

        return preg_match('/^\d{3}\/?\d{5}$/', preg_replace('/\s+/', '', trim($value)) ?? '') === 1 && strlen($digits) === 8 && ($state === null || $state === 'BY')
            ? null
            : false;
    }

    /**
     * A VAT identification number (DE + 9 digits) given as tax number.
     */
    public static function looksLikeVatId(string $value): bool
    {
        return preg_match('/^DE\s?\d{9}$/i', trim($value)) === 1;
    }

    /**
     * Notation on tax notices, e.g. 181/815/08155 or 133/8150/8159.
     */
    public function formatted(): string
    {
        $f = substr($this->elster, 0, 4);
        if ($this->state === 'NW') {
            return sprintf('%s/%s/%s', substr($f, 1), substr($this->elster, 5, 4), substr($this->elster, 9));
        }
        $district = substr($this->elster, 5, 3);
        $rest = substr($this->elster, 8);

        return match (true) {
            $this->state === 'HE' => sprintf('0%s %s %s', substr($f, 2), $district, $rest),
            $this->state === 'HB' => sprintf('%s %s %s', substr($f, 2), $district, $rest),
            isset(self::TWO_DIGIT_STATES[$this->state]) => sprintf('%s/%s/%s', substr($f, 2), $district, $rest),
            default => sprintf('%s/%s/%s', substr($f, 1), $district, $rest),
        };
    }

    /**
     * ELSTER interpretations of a value in any notation.
     *
     * @return list<string>
     */
    private static function candidates(string $value, ?string $state): array
    {
        $value = trim($value);
        if (preg_match('/^[\d\s\/.-]+$/', $value) !== 1) {
            return [];
        }
        $groups = preg_split('/[\s\/.-]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $groups = $groups === false ? [] : $groups;
        $digits = implode('', $groups);
        $layout = implode('/', array_map('strlen', $groups));

        if (count($groups) === 1 && strlen($digits) === 13) {
            return [$digits];
        }
        if (count($groups) === 1 && strlen($digits) === 12) {
            return [substr($digits, 0, 4).'0'.substr($digits, 4)];
        }

        $candidates = [];
        // Two-digit tax office: FF/BBB/UUUUP, FF BBB UUUUP, FFBBB/UUUUP, old Rhineland-Palatinate FF/BBB/UUUU/P.
        if (strlen($digits) === 10 && in_array($layout, ['10', '2/3/5', '5/5', '2/3/4/1'], true)) {
            foreach (self::TWO_DIGIT_STATES as $code => $prefix) {
                $candidates[] = $prefix.substr($digits, 0, 2).'0'.substr($digits, 2);
            }
        }
        // Three-digit tax office: FFF/BBB/UUUUP (Bavarian group, Hesse with leading 0).
        if (strlen($digits) === 11 && in_array($layout, ['11', '3/3/5'], true)) {
            $fff = substr($digits, 0, 3);
            $rest = substr($digits, 3);
            $candidates[] = '9'.$fff.'0'.$rest;
            $candidates[] = match ($fff[0]) {
                '0' => '3'.$fff.'0'.$rest,
                '1' => '3'.$fff.'0'.$rest,
                '2' => '3'.$fff.'0'.$rest,
                default => null,
            };
            if ($fff[0] === '0' || $fff[0] === '1') {
                $candidates[] = '4'.$fff.'0'.$rest;
            }
            if ($fff[0] === '0') {
                $candidates[] = '1'.$fff.'0'.$rest;
                $candidates[] = '26'.substr($fff, 1).'0'.$rest;
            }
        }
        // NRW: FFF/BBBB/UUUP.
        if (strlen($digits) === 11 && in_array($layout, ['11', '3/4/4'], true)) {
            $candidates[] = '5'.substr($digits, 0, 3).'0'.substr($digits, 3);
        }

        $candidates = array_values(array_unique(array_filter($candidates, is_string(...))));

        return $state === null ? $candidates : array_values(array_filter($candidates, static fn (string $c): bool => self::stateOf($c) === $state));
    }

    private static function stateOf(string $elster): ?string
    {
        return self::STATE_PREFIXES[substr($elster, 0, 2)] ?? self::STATE_PREFIXES[$elster[0]] ?? null;
    }

    private static function isValidElster(string $elster, string $state): bool
    {
        if (preg_match('/^\d{13}$/', $elster) !== 1 || $elster[4] !== '0') {
            return false;
        }
        if ($state === 'NW') {
            $district = substr($elster, 5, 4);
            if (in_array($district, ['0000', '0998', '0999'], true) || (int) substr($elster, 9, 4) <= 9) {
                return false;
            }
        } else {
            $district = substr($elster, 5, 3);
            if (in_array($district, ['000', '998', '999'], true)) {
                return false;
            }
            if (in_array($state, self::BAVARIAN_GROUP, true) && (int) $district < 100) {
                return false;
            }
            if ($state === 'BY' && substr($elster, 5) === '99999999') {
                return false;
            }
        }

        $digits = array_map('intval', str_split(substr($elster, 0, 12)));
        $check = (int) $elster[12];

        return match ($state) {
            'RP' => self::modified11($digits) === $check,
            'BW', 'HE', 'SH' => self::procedure2($digits) === $check,
            'NW' => self::procedure11($digits, self::FACTORS_11['NW'], remainder: true) === $check,
            'BE' => in_array($check, self::berlinCheckDigits($elster, $digits), true),
            default => self::procedure11($digits, self::FACTORS_11[$state], remainder: false) === $check,
        };
    }

    /**
     * @param  list<int>  $digits
     * @param  list<int>  $factors
     */
    private static function procedure11(array $digits, array $factors, bool $remainder): ?int
    {
        $sum = 0;
        foreach ($digits as $i => $digit) {
            $sum += $digit * $factors[$i];
        }
        $rest = $sum % 11;
        $check = $remainder ? $rest : ($rest === 0 ? 0 : 11 - $rest);

        // A two-digit check digit is never issued.
        return $check === 10 ? null : $check;
    }

    /**
     * @param  list<int>  $digits
     */
    private static function procedure2(array $digits): int
    {
        $sum = 0;
        foreach ($digits as $i => $digit) {
            $product = (($digit + self::SUMMANDS_2[$i]) % 10) * self::FACTORS_2[$i];
            while ($product > 9) {
                $product = array_sum(array_map('intval', str_split((string) $product)));
            }
            $sum += $product;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @param  list<int>  $digits
     */
    private static function modified11(array $digits): int
    {
        $sum = 0;
        foreach ($digits as $i => $digit) {
            $product = $digit * self::FACTORS_RP[$i];
            $sum += $product > 9 ? $product % 10 + 1 : $product;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @param  list<int>  $digits
     * @return list<int>
     */
    private static function berlinCheckDigits(string $elster, array $digits): array
    {
        $rule = self::BERLIN[substr($elster, 0, 4)] ?? null;
        $district = (int) substr($elster, 5, 3);
        $variants = match (true) {
            $rule === 'B' => ['BE-B'],
            is_array($rule) => [self::inRanges($district, $rule) ? 'BE-B' : 'BE-A'],
            default => ['BE-B'],
        };

        return array_values(array_filter(array_map(static fn (string $v): ?int => self::procedure11($digits, self::FACTORS_11[$v], remainder: false), $variants), is_int(...)));
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private static function inRanges(int $value, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($value >= $from && $value <= $to) {
                return true;
            }
        }

        return false;
    }
}
