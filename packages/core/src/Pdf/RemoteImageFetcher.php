<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf;

use Closure;
use Quittio\Core\Exceptions\ConfigurationException;

/**
 * Downloads remote logos without server-side request forgery: only http and https, only
 * hosts whose every resolved address (IPv4 and IPv6) is publicly routable, the connection
 * pinned to the checked address (no second DNS lookup) and every redirect checked again.
 */
final readonly class RemoteImageFetcher
{
    /** HTTP status codes of redirects that are followed (after checking the new address). */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private Closure $resolver;

    private Closure $transport;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host name => IP addresses
     * @param  (Closure(string, string, string): (array{status: int, location: ?string, body: string}|null))|null  $transport  URL, host, pinned IP => response, null on failure
     */
    public function __construct(
        private int $maxBytes = 10 * 1024 * 1024,
        private int $timeout = 10,
        private int $maxRedirects = 3,
        ?Closure $resolver = null,
        ?Closure $transport = null,
    ) {
        $this->resolver = $resolver ?? self::resolve(...);
        $this->transport = $transport ?? $this->request(...);
    }

    /**
     * Content of the image, or null if it cannot be downloaded.
     *
     * @throws ConfigurationException for URLs that are not allowed (scheme, non-public address)
     */
    public function fetch(string $url): ?string
    {
        for ($redirects = 0; $redirects <= $this->maxRedirects; $redirects++) {
            [$host, $ip] = $this->check($url);
            $response = ($this->transport)($url, $host, $ip);
            if ($response === null) {
                return null;
            }
            if (in_array($response['status'], self::REDIRECTS, true) && $response['location'] !== null) {
                $url = self::absolute($url, $response['location']);

                continue;
            }

            return $response['status'] >= 200 && $response['status'] < 300 && $response['body'] !== '' ? $response['body'] : null;
        }

        return null;
    }

    /**
     * Whether the address is publicly routable: no loopback, private, link-local, shared,
     * reserved, documentation or multicast range, also not embedded in an IPv6 address.
     */
    public static function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // NAT64 (64:ff9b::/96) carries an IPv4 address; multicast (ff00::/8).
            if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8))) {
                return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
            }
            // Multicast (ff00::/8) and deprecated site-local (fec0::/10, not caught by filter_var).
            if ($packed[0] === "\xff" || (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0xC0)) {
                return false;
            }
        } elseif ((ord($packed[0]) & 0xF0) === 0xE0) {
            // IPv4 multicast 224.0.0.0/4.
            return false;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }

    /**
     * @return array{string, string} host and the checked address to connect to
     */
    private function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ConfigurationException(sprintf('Remote logos must be http or https URLs, "%s" is not allowed.', $url));
        }

        $literal = trim($host, '[]');
        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : ($this->resolver)($host);
        if ($addresses === []) {
            throw new ConfigurationException(sprintf('The host of the logo URL "%s" cannot be resolved.', $url));
        }
        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new ConfigurationException(sprintf('The logo URL "%s" points to the non-public address %s; logos are only downloaded from public hosts.', $url, $address));
            }
        }

        return [$host, $addresses[0]];
    }

    /**
     * IPv4 and IPv6 addresses of a host name.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($records === false ? [] : $records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        $ipv4 = @gethostbynamel($host);
        foreach ($ipv4 === false ? [] : $ipv4 as $address) {
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * GET request to the pinned address, with the original host for the Host header and TLS.
     *
     * @return array{status: int, location: ?string, body: string}|null
     */
    private function request(string $url, string $host, string $ip): ?array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $target = sprintf('%s://%s%s%s%s', strtolower((string) ($parts['scheme'] ?? 'http')), str_contains($ip, ':') ? '['.$ip.']' : $ip, $port, $parts['path'] ?? '/', isset($parts['query']) ? '?'.$parts['query'] : '');
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => sprintf("Host: %s%s\r\nUser-Agent: Quittio\r\nAccept: image/*\r\n", $host, $port),
                'timeout' => $this->timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => ['peer_name' => trim($host, '[]'), 'verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $stream = @fopen($target, 'rb', false, $context);
        if ($stream === false) {
            return null;
        }
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $deadline = microtime(true) + $this->timeout;
        $body = '';
        while (! feof($stream) && strlen($body) <= $this->maxBytes && microtime(true) < $deadline) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
        }
        $complete = feof($stream);
        fclose($stream);
        if (! $complete || strlen($body) > $this->maxBytes) {
            return null;
        }

        $status = 0;
        $location = null;
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (! is_string($header)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            } elseif (stripos($header, 'location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => $body];
    }

    /**
     * Location header relative to the URL it was sent for.
     */
    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = sprintf('%s://%s%s', $parts['scheme'] ?? 'http', $parts['host'] ?? '', isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $path = $parts['path'] ?? '/';

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
    }
}
