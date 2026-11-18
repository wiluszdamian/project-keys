<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * dHash głównego zdjęcia: 64 bity jako 16 znaków hex. Podobne zdjęcia (to samo po przeskalowaniu
 * albo kompresji) mają mało różnych bitów.
 */
class ImageHasher
{
    public function hashUrl(?string $url): ?string
    {
        if (! $url || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('scrapers.user_agent')])->timeout(15)->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? $this->hash($response->body()) : null;
    }

    public function hash(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));

        $bits = '';

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $this->gray($small, $x, $y) > $this->gray($small, $x + 1, $y) ? '1' : '0';
            }
        }

        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT)
            .str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    public static function distance(string $a, string $b): int
    {
        $distance = 0;

        foreach (str_split($a, 8) as $i => $chunk) {
            $distance += substr_count(decbin(hexdec($chunk) ^ hexdec(str_split($b, 8)[$i])), '1');
        }

        return $distance;
    }

    private function gray(\GdImage $image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);

        return (int) (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));
    }
}
