<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

use Closure;
use JsonException;

/**
 * VAT identifier check via the REST interface of the EU VIES service.
 *
 * Only identifiers of the EU member states (Greece: "EL") and of Northern Ireland ("XI") are sent
 * to VIES; others are reported as not supported. Only the answers VALID and INVALID count as a
 * result and are cached. Errors (INVALID_INPUT, MS_UNAVAILABLE, TIMEOUT, …) and unreachable
 * services give $valid = null, are not cached and never block validation.
 */
final class ViesVerifier implements VatIdVerifier
{
    public const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    /** @var list<string> VAT identifier prefixes VIES can check */
    public const array PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /** @var array<string, array{expires: int, result: VatIdVerification}> */
    private array $cache = [];

    /** @var Closure(string, int): ?string */
    private Closure $http;

    /**
     * @param  (Closure(string, int): ?string)|null  $http  returns the response body or null on failure
     */
    public function __construct(
        private readonly int $timeout = 5,
        private readonly int $cacheTtl = 86400,
        ?Closure $http = null,
    ) {
        $this->http = $http ?? static function (string $url, int $timeout): ?string {
            $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
            $body = @file_get_contents($url, false, $context);

            return $body === false ? null : $body;
        };
    }

    public function verify(string $vatId): VatIdVerification
    {
        $normalized = strtoupper(preg_replace('/[\s.\-]/', '', $vatId) ?? $vatId);
        $cached = $this->cache[$normalized] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['result'];
        }

        $country = substr($normalized, 0, 2);
        $number = substr($normalized, 2);
        if (! self::supports($normalized)) {
            return VatIdVerification::notSupported($vatId, sprintf('VIES does not check VAT identifiers with the prefix "%s".', $country));
        }

        $body = ($this->http)(sprintf(self::ENDPOINT, rawurlencode($country), rawurlencode($number)), $this->timeout);

        $result = $this->parse($vatId, $body);
        if ($result->valid !== null) {
            $this->cache[$normalized] = ['expires' => time() + $this->cacheTtl, 'result' => $result];
        }

        return $result;
    }

    /**
     * Whether VIES can check the VAT identifier (EU member state prefix incl. "EL", or "XI").
     */
    public static function supports(string $vatId): bool
    {
        return in_array(strtoupper(substr(trim($vatId), 0, 2)), self::PREFIXES, true);
    }

    private function parse(string $vatId, ?string $body): VatIdVerification
    {
        if ($body === null) {
            return new VatIdVerification($vatId, null, error: 'VIES service not reachable');
        }

        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new VatIdVerification($vatId, null, error: 'Invalid response from VIES');
        }

        if (! is_array($data)) {
            return new VatIdVerification($vatId, null, error: 'Unexpected response from VIES');
        }

        // VIES answers errors with isValid = false and the reason in userError (e.g. INVALID_INPUT,
        // MS_UNAVAILABLE, TIMEOUT); only VALID and INVALID are results.
        $userError = isset($data['userError']) && is_string($data['userError']) ? strtoupper($data['userError']) : null;
        $valid = match (true) {
            $userError === 'VALID' => true,
            $userError === 'INVALID' => false,
            $userError !== null => null,
            ($data['isValid'] ?? null) === true => true,
            ($data['isValid'] ?? null) === false => false,
            default => null,
        };
        if ($valid === null) {
            return new VatIdVerification($vatId, null, error: $userError ?? 'Unexpected response from VIES');
        }

        $name = isset($data['name']) && is_string($data['name']) && $data['name'] !== '---' ? $data['name'] : null;
        $address = isset($data['address']) && is_string($data['address']) && $data['address'] !== '---' ? $data['address'] : null;

        return new VatIdVerification($vatId, $valid, $name, $address);
    }
}
