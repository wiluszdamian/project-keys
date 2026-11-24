<?php

namespace App\Services\Products;

use InvalidArgumentException;

/**
 * Adres produktu: bez parametrów śledzących i kotwicy, klucz do porównań i nazwa sklepu.
 */
class ProductUrl
{
    private const TRACKING = [
        'gclid', 'fbclid', 'gad_source', 'gad_campaignid', 'gbraid', 'wbraid', 'srsltid', '_gl', 'mc_cid', 'mc_eid',
        'yclid', 'msclkid', 'dclid', 'igshid', 'ref_src', 'spm',
    ];

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            throw new InvalidArgumentException('To nie jest poprawny link do strony (http albo https).');
        }

        parse_str($parts['query'] ?? '', $query);
        $query = array_filter(
            $query,
            fn ($key) => ! str_starts_with(strtolower((string) $key), 'utm_') && ! in_array(strtolower((string) $key), self::TRACKING, true),
            ARRAY_FILTER_USE_KEY,
        );

        return strtolower($parts['scheme']).'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/')
            .($query ? '?'.http_build_query($query) : '');
    }

    public static function key(string $url): string
    {
        return sha1(self::normalize($url));
    }

    /**
     * Nazwa sklepu z adresu, np. babyhit.pl.
     */
    public static function shop(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^(www\d?|m)\./', '', $host) ?: $host;
    }

    /**
     * Czy adres prowadzi do publicznego serwera (nie do sieci lokalnej serwera aplikacji).
     */
    public static function isPublic(string $url): bool
    {
        if (! config('products.block_private_hosts', true)) {
            return true;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || in_array(strtolower($host), ['localhost'], true) || str_ends_with(strtolower($host), '.internal') || str_ends_with(strtolower($host), '.local')) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Czy dwa adresy wskazują tę samą stronę (bez parametrów i ukośnika na końcu).
     */
    public static function samePage(string $a, string $b): bool
    {
        $path = fn (string $url) => strtolower((string) parse_url($url, PHP_URL_HOST)).rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path($a) === $path($b);
    }
}
