<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * robots.txt sklepu: mapy stron i reguły dla wszystkich robotów (User-agent: *).
 */
class RobotsTxt
{
    /**
     * @param  list<array{0: bool, 1: string}>  $rules  [czy wolno, wzorzec ścieżki]
     * @param  list<string>  $sitemaps
     */
    public function __construct(public array $rules = [], public array $sitemaps = []) {}

    public static function for(string $baseUrl): self
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);

        return Cache::remember("products:robots:{$host}", now()->addDay(), function () use ($baseUrl) {
            try {
                $response = Http::withHeaders(['User-Agent' => config('scrapers.user_agent')])->timeout(20)->get(rtrim($baseUrl, '/').'/robots.txt');
            } catch (Throwable) {
                return new self;
            }

            return $response->successful() ? self::parse($response->body()) : new self;
        });
    }

    public static function parse(string $text): self
    {
        $rules = [];
        $sitemaps = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap' && $value !== '') {
                $sitemaps[] = $value;

                continue;
            }

            if ($field === 'user-agent') {
                // Nowa grupa zaczyna się od User-agent po regułach poprzedniej.
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }

                $agents[] = strtolower($value);

                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;

                if (in_array('*', $agents, true) && $value !== '') {
                    $rules[] = [$field === 'allow', $value];
                }
            }
        }

        return new self($rules, array_values(array_unique($sitemaps)));
    }

    /**
     * Czy ścieżka jest dozwolona: wygrywa najdłuższa pasująca reguła, przy remisie Allow.
     */
    public function allows(string $url): bool
    {
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($query = parse_url($url, PHP_URL_QUERY)) ? "?{$query}" : '');
        $best = null;

        foreach ($this->rules as [$allow, $pattern]) {
            $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';

            if (preg_match($regex, $path) === 1 && ($best === null || strlen($pattern) > strlen($best[1]) || (strlen($pattern) === strlen($best[1]) && $allow))) {
                $best = [$allow, $pattern];
            }
        }

        return $best === null || $best[0];
    }
}
