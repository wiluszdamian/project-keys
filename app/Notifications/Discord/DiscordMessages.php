<?php

namespace App\Notifications\Discord;

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Services\ListingScorer;
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
            'fields' => [...$this->analysisFields($analysis), ...$this->fields($listing, $analysis)],
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
    public function digest(Search $search, Collection $listings, int $total, bool $firstRun, ?int $qualifying = null, ?string $period = null): array
    {
        $lines = $listings->map(function (Listing $listing) {
            $score = $listing->getAttribute('score');
            $monthly = $listing->getAttribute('monthly_cost');
            $prefix = $score !== null ? "`{$score}/10` " : '';
            $title = Str::limit(str_replace(['[', ']'], '', $listing->title), 70);

            return "{$prefix}**[{$title}]({$listing->url})**\n".implode(' · ', array_filter([
                $monthly !== null ? $this->money((int) $monthly).'/mies. łącznie' : $this->money($listing->price),
                $listing->area ? $this->area($listing->area) : null,
                $listing->rooms ? "{$listing->rooms} pok." : null,
                $listing->district ?? $listing->city,
                $this->portalName($listing->portal),
            ]));
        });

        $heading = match (true) {
            $period !== null => "{$period}: {$total} nowych ".$this->offersWord($total, genitive: true)." z oceną od {$search->min_score_to_notify}",
            $firstRun => "Pierwszy przebieg: {$total} ".$this->offersWord($total).' pasuje do filtrów',
            default => "{$total} nowych ".$this->offersWord($total, genitive: true).' w jednym przebiegu',
        };

        $shown = $qualifying ?? $total;

        if ($qualifying !== null) {
            $heading .= ", {$qualifying} z oceną od {$search->min_score_to_notify}";
        }

        $more = $shown > $listings->count()
            ? "\n\nPokazuję {$listings->count()} najlepszych z {$shown}. Wszystkie w panelu: ".rtrim(config('app.url'), '/')."/admin/listings?filters[search][value]={$search->id}"
            : '';

        if ($listings->isEmpty()) {
            $lines = collect(["Żadna oferta nie dostała oceny od {$search->min_score_to_notify}.".($period === null ? ' Nowe dobre oferty przyjdą osobno.' : '')]);
        }

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
        $text = $listing->description ? Str::limit(Str::squish($listing->description), 350) : '';

        if (ListingScorer::isEnabled()) {
            $text = trim($text."\n\n_Brak oceny AI (błąd modelu)._");
        }

        return $text !== '' ? $text : null;
    }

    /**
     * Koszt miesięczny i oceny cząstkowe z AI, na górze wiadomości.
     */
    private function analysisFields(?ListingAnalysis $analysis): array
    {
        if (! $analysis) {
            return [];
        }

        $fields = [];
        $breakdown = $analysis->cost_breakdown ?? [];
        $parts = array_filter([
            isset($breakdown['rent']) ? 'najem '.$this->money($breakdown['rent']) : null,
            isset($breakdown['admin_fee']) ? 'czynsz '.$this->money($breakdown['admin_fee']) : null,
            isset($breakdown['other_fees']) ? 'opłaty '.$this->money($breakdown['other_fees']) : null,
        ]);

        if ($analysis->monthly_cost !== null || $parts !== []) {
            $value = $analysis->monthly_cost !== null ? '**'.$this->money($analysis->monthly_cost).'** / mies.' : 'nie wiadomo';
            $value .= $parts !== [] ? "\n".implode(' + ', $parts) : '';
            $value .= ! empty($breakdown['note']) ? "\n_{$breakdown['note']}_" : '';

            $fields[] = ['name' => '💸 Koszt miesięczny', 'value' => Str::limit($value, 1000), 'inline' => false];
        }

        if (! empty($analysis->sub_scores)) {
            $labels = ['cost' => 'Koszt', 'location' => 'Lokalizacja', 'standard' => 'Standard', 'fit' => 'Dopasowanie'];
            $scores = collect($labels)
                ->filter(fn ($label, $key) => isset($analysis->sub_scores[$key]))
                ->map(fn ($label, $key) => "{$label} **{$analysis->sub_scores[$key]}**")
                ->implode(' · ');

            $fields[] = ['name' => "📊 Ocena {$analysis->score}/10", 'value' => $scores, 'inline' => false];
        }

        return $fields;
    }

    private function fields(Listing $listing, ?ListingAnalysis $analysis = null): array
    {
        $rent = $listing->deal_type === DealType::Rent;
        $price = $this->money($listing->price) ?? 'brak';
        // Czynsz z portalu, a gdy go nie ma, odczytany przez AI z opisu.
        $adminFee = $listing->rent_extra ?? ($analysis->cost_breakdown['admin_fee'] ?? null);

        if ($adminFee) {
            $price .= ' + '.$this->money((int) $adminFee).' czynsz';
        } elseif ($rent) {
            $price .= ' + czynsz nieznany';
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
            ['name' => $rent ? '💰 Najem' : '💰 Cena', 'value' => $price, 'inline' => true],
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
