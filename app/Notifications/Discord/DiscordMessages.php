<?php

namespace App\Notifications\Discord;

use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Treść wiadomości na Discorda (embedy).
 */
class DiscordMessages
{
    private const PORTALS = [
        'otodom' => ['name' => 'Otodom', 'color' => 0x4A5BD9],
        'olx' => ['name' => 'OLX', 'color' => 0x23E5DB],
    ];

    private const COLOR_GOOD = 0x2ECC71;

    private const COLOR_OK = 0xF1C40F;

    private const COLOR_WEAK = 0x95A5A6;

    private const COLOR_INFO = 0x5865F2;

    private const COLOR_ALERT = 0xE74C3C;

    public function listing(Listing $listing, Search $search, ?ListingAnalysis $analysis = null): array
    {
        $title = $analysis ? "{$analysis->score}/10 · {$listing->title}" : $listing->title;

        $embed = [
            'author' => ['name' => '🏠 Nowa oferta · '.$this->portalName($listing->portal)],
            'title' => Str::limit($title, 250),
            'url' => $listing->url,
            'color' => $this->color($listing, $analysis),
            'description' => $this->description($listing, $analysis),
            'fields' => $this->fields($listing),
            'footer' => ['text' => "Wyszukiwanie: {$search->name}"],
            'timestamp' => $listing->first_seen_at?->toIso8601String(),
        ];

        if ($listing->image_url) {
            $embed['image'] = ['url' => $listing->image_url];
        }

        return ['embeds' => [array_filter($embed, fn ($value) => $value !== null && $value !== '' && $value !== [])]];
    }

    /**
     * Jedno podsumowanie zamiast wielu wiadomości.
     *
     * @param  Collection<int, Listing>  $listings  oferty do pokazania (najwyżej 10)
     */
    public function digest(Search $search, Collection $listings, int $total, bool $firstRun): array
    {
        $lines = $listings->map(function (Listing $listing) {
            $score = $listing->getAttribute('score');
            $prefix = $score !== null ? "`{$score}/10` " : '';
            $title = Str::limit(str_replace(['[', ']'], '', $listing->title), 70);

            return "{$prefix}**[{$title}]({$listing->url})**\n".implode(' · ', array_filter([
                $this->money($listing->price),
                $listing->area ? $this->area($listing->area) : null,
                $listing->rooms ? "{$listing->rooms} pok." : null,
                $listing->district ?? $listing->city,
                $this->portalName($listing->portal),
            ]));
        });

        $heading = $firstRun
            ? "Pierwszy przebieg: {$total} ".$this->offersWord($total).' pasuje do filtrów'
            : "{$total} nowych ".$this->offersWord($total, genitive: true).' w jednym przebiegu';

        $more = $total > $listings->count()
            ? "\n\nPokazuję {$listings->count()} z {$total}. Reszta: `GET /api/searches/{$search->id}/listings`"
            : '';

        return ['embeds' => [[
            'author' => ['name' => '📋 Podsumowanie'],
            'title' => Str::limit($heading, 250),
            'description' => Str::limit($lines->implode("\n\n").$more, 4000),
            'color' => self::COLOR_INFO,
            'footer' => ['text' => "Wyszukiwanie: {$search->name}"],
            'timestamp' => now()->toIso8601String(),
        ]]];
    }

    public function priceDrop(Listing $listing, Search $search, int $oldPrice, int $newPrice): array
    {
        $percent = $oldPrice > 0 ? round(($oldPrice - $newPrice) / $oldPrice * 100) : 0;

        return ['embeds' => [[
            'author' => ['name' => '📉 Obniżka ceny · '.$this->portalName($listing->portal)],
            'title' => Str::limit($listing->title, 250),
            'url' => $listing->url,
            'description' => "~~{$this->money($oldPrice)}~~ → **{$this->money($newPrice)}** (−{$percent}%)",
            'color' => self::COLOR_GOOD,
            'footer' => ['text' => "Wyszukiwanie: {$search->name}"],
            'timestamp' => now()->toIso8601String(),
        ]]];
    }

    public function alert(string $title, string $body): array
    {
        return ['embeds' => [[
            'author' => ['name' => '⚠️ Alert systemu'],
            'title' => Str::limit($title, 250),
            'description' => Str::limit($body, 4000),
            'color' => self::COLOR_ALERT,
            'timestamp' => now()->toIso8601String(),
        ]]];
    }

    public function test(): array
    {
        return ['embeds' => [[
            'author' => ['name' => '✅ Test powiadomień'],
            'title' => 'Webhook działa',
            'description' => 'Tak będą wyglądać wiadomości o nowych ofertach: ocena AI w tytule, kolor od oceny, zdjęcie i link do ogłoszenia.',
            'color' => self::COLOR_GOOD,
            'fields' => [
                ['name' => '💰 Cena', 'value' => "2 500 zł\n+ 400 zł czynsz", 'inline' => true],
                ['name' => '📐 Metraż', 'value' => "56 m²\n45 zł/m²", 'inline' => true],
                ['name' => '🚪 Pokoje', 'value' => "3\npiętro 2", 'inline' => true],
            ],
            'timestamp' => now()->toIso8601String(),
        ]]];
    }

    private function description(Listing $listing, ?ListingAnalysis $analysis): ?string
    {
        if ($analysis) {
            $parts = [$analysis->summary];

            if (! empty($analysis->pros)) {
                $parts[] = '**Plusy:** '.implode(', ', $analysis->pros);
            }

            if (! empty($analysis->red_flags)) {
                $parts[] = '**Uwaga:** '.implode(', ', $analysis->red_flags);
            }

            return Str::limit(implode("\n\n", $parts), 4000);
        }

        // Bez oceny AI: początek opisu z ogłoszenia, jeśli portal go podaje.
        return $listing->description ? Str::limit(Str::squish($listing->description), 350) : null;
    }

    private function fields(Listing $listing): array
    {
        $price = $this->money($listing->price) ?? 'brak';
        if ($listing->rent_extra) {
            $price .= "\n+ ".$this->money($listing->rent_extra).' czynsz';
        }

        $area = $listing->area ? $this->area($listing->area) : 'brak';
        if ($listing->price_per_m2) {
            $area .= "\n".number_format($listing->price_per_m2, 0, ',', ' ').' zł/m²';
        }

        $rooms = ($listing->rooms ?? '?').($listing->floor !== null ? "\npiętro {$listing->floor}" : '');

        $location = implode(', ', array_filter([$listing->district, $listing->city]))
            .($listing->street ? "\n{$listing->street}" : '');

        $owner = match ($listing->is_private_owner) {
            true => 'prywatne',
            false => 'agencja / firma',
            null => 'nie wiadomo',
        };

        return array_values(array_filter([
            ['name' => '💰 Cena', 'value' => $price, 'inline' => true],
            ['name' => '📐 Metraż', 'value' => $area, 'inline' => true],
            ['name' => '🚪 Pokoje', 'value' => $rooms, 'inline' => true],
            $location !== '' ? ['name' => '📍 Lokalizacja', 'value' => Str::limit($location, 1000), 'inline' => true] : null,
            ['name' => '👤 Ogłoszeniodawca', 'value' => $owner, 'inline' => true],
            ['name' => '🔗 Portal', 'value' => $this->portalName($listing->portal), 'inline' => true],
        ]));
    }

    private function color(Listing $listing, ?ListingAnalysis $analysis): int
    {
        if ($analysis) {
            return match (true) {
                $analysis->score >= 8 => self::COLOR_GOOD,
                $analysis->score >= 6 => self::COLOR_OK,
                default => self::COLOR_WEAK,
            };
        }

        return self::PORTALS[$listing->portal]['color'] ?? self::COLOR_INFO;
    }

    private function portalName(string $portal): string
    {
        return self::PORTALS[$portal]['name'] ?? Str::ucfirst($portal);
    }

    private function money(?int $value): ?string
    {
        return $value === null ? null : number_format($value, 0, ',', ' ').' zł';
    }

    private function area(float|string $area): string
    {
        $area = (float) $area;

        return (floor($area) == $area ? number_format($area, 0) : number_format($area, 1, ',', '')).' m²';
    }

    private function offersWord(int $count, bool $genitive = false): string
    {
        if ($genitive) {
            return 'ofert';
        }

        return match (true) {
            $count === 1 => 'oferta',
            $count % 10 >= 2 && $count % 10 <= 4 && ($count % 100 < 10 || $count % 100 >= 20) => 'oferty',
            default => 'ofert',
        };
    }
}
