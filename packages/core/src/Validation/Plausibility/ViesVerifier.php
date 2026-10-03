<?php

declare(strict_types=1);

namespace Quittio\Core\Validation\Plausibility;

use Closure;
use JsonException;

/**
 * VAT identifier check via the REST interface of the EU VIES service.
 *
 * Results are cached; unreachable services never block validation.
 */
final class ViesVerifier implements VatIdVerifier
{
    public const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

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
        $body = ($this->http)(sprintf(self::ENDPOINT, rawurlencode($country), rawurlencode($number)), $this->timeout);

        $result = $this->parse($vatId, $body);
        if ($result->valid !== null) {
            $this->cache[$normalized] = ['expires' => time() + $this->cacheTtl, 'result' => $result];
        }

        return $result;
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

        if (! is_array($data) || ! array_key_exists('isValid', $data)) {
            $error = is_array($data) && isset($data['userError']) && is_string($data['userError']) ? $data['userError'] : 'Unexpected response from VIES';

            return new VatIdVerification($vatId, null, error: $error);
        }

        $name = isset($data['name']) && is_string($data['name']) && $data['name'] !== '---' ? $data['name'] : null;
        $address = isset($data['address']) && is_string($data['address']) && $data['address'] !== '---' ? $data['address'] : null;

        return new VatIdVerification($vatId, $data['isValid'] === true, $name, $address);
    }
}
