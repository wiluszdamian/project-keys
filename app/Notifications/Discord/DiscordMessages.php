<?php

namespace App\Notifications\Discord;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Filament\Support\Format;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\MarketReport;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Search;
use App\Services\DealFinder;
use App\Services\Geo\CommuteCalculator;
use App\Services\ListingScorer;
use App\Services\Products\ProductAdvisor;
use App\Services\Rcn\TransactionComparator;
use App\Services\TimeOnMarket;
use App\Support\Portals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Treść wiadomości na Discorda (embedy).
 */
class DiscordMessages
{
    private const PORTAL_COLORS = [
        'otodom' => 0x4A5BD9,
        'olx' => 0x23E5DB,
        'rzeszowiak' => 0xE67E22,
        'morizon' => 0xE4202E,
        'gratka' => 0x0069B4,
        'nieruchomosci_online' => 0x1E8449,
        'domiporta' => 0x8E44AD,
        'adresowo' => 0xF39C12,
        'stoprocent' => 0xC0392B,
        'rzeszowskie' => 0x2C3E50,
        'pierwsze_pietro' => 0x16A085,
        'jokerhouse' => 0xD4AC0D,
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
        $lines = $listings->map(fn (Listing $listing) => $this->digestLine($listing));

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

    /**
     * Podsumowanie kanału: wszystkie wyszukiwania na wynajem (albo sprzedaż) w jednej wiadomości.
     *
     * @param  Collection<int, Listing>  $listings  z atrybutami score, monthly_cost i search_names
     * @param  list<array{listing: Listing, old_price: int, new_price: int}>  $priceDrops
     */
    public function groupDigest(DealType $dealType, Collection $listings, int $total, array $priceDrops, string $period): array
    {
        $deal = $dealType === DealType::Rent ? 'Wynajem' : 'Sprzedaż';
        $sections = [];

        if ($listings->isNotEmpty()) {
            $sections[] = $listings->map(fn (Listing $listing) => $this->digestLine($listing, $listing->getAttribute('search_names')))->implode("\n\n");

            if ($total > $listings->count()) {
                $sections[] = "Pokazuję {$listings->count()} najlepszych z {$total}. Wszystkie w panelu: ".rtrim(config('app.url'), '/').'/admin/listings';
            }
        } else {
            $sections[] = 'Brak nowych ofert z oceną od progu wyszukiwania.';
        }

        if ($priceDrops !== []) {
            $sections[] = "**📉 Obniżki cen**\n".collect($priceDrops)->map(fn (array $drop) => '['.Str::limit(str_replace(['[', ']'], '', $drop['listing']->title), 60).']('.$drop['listing']->url.'): '
                .$this->money($drop['old_price']).' → '.$this->money($drop['new_price']))->implode("\n");
        }

        return ['embeds' => [[
            'author' => ['name' => "📋 {$period} · {$deal}"],
            'title' => "{$deal}: {$total} nowych ".$this->offersWord($total, genitive: true).' z oceną od progu',
            'description' => Str::limit(implode("\n\n", $sections), 4000),
            'color' => self::COLOR_INFO,
            'footer' => ['text' => 'Zbiorcze powiadomienie kanału. Zmienisz je w panelu: Ustawienia → Powiadomienia.'],
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

    /**
     * Oferta, która stała się okazją (tańsza niż rynek albo po dużej obniżce).
     */
    public function deal(Listing $listing, ?Search $search, ?ListingAnalysis $analysis = null): array
    {
        $embed = [
            'author' => ['name' => '🔥 Okazja · '.$this->portalName($listing->portal)],
            'title' => Str::limit(($analysis ? "{$analysis->score}/10 · " : '').$listing->title, 250),
            'url' => $listing->url,
            'description' => Str::limit(ucfirst((string) DealFinder::summary($listing->deal_reasons)), 1000),
            'color' => self::COLOR_GOOD,
            'fields' => $this->fields($listing, $analysis),
            'footer' => $search ? ['text' => "Wyszukiwanie: {$search->name}"] : null,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($listing->image_url) {
            $embed['thumbnail'] = ['url' => $listing->image_url];
        }

        return ['embeds' => [array_filter($embed, fn ($value) => $value !== null && $value !== '' && $value !== [])]];
    }

    /**
     * Kilka okazji naraz: jedna wiadomość z listą.
     *
     * @param  Collection<int, Listing>  $listings
     */
    public function deals(DealType $dealType, Collection $listings): array
    {
        $lines = $listings->take(10)->map(fn (Listing $listing) => $this->digestLine($listing).'
🔥 '.DealFinder::summary($listing->deal_reasons));
        $more = $listings->count() > 10 ? '

…i jeszcze '.($listings->count() - 10).' w panelu (Oferty → Okazje).' : '';

        return ['embeds' => [[
            'author' => ['name' => '🔥 Okazje · '.($dealType === DealType::Rent ? 'wynajem' : 'sprzedaż')],
            'title' => $listings->count().' '.($listings->count() === 1 ? 'oferta tańsza niż rynek' : 'ofert tańszych niż rynek albo po obniżce'),
            'description' => Str::limit($lines->implode('

').$more, 4000),
            'color' => self::COLOR_GOOD,
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

    /**
     * Tygodniowy raport rynku: liczby z naszej bazy, osobno wynajem i sprzedaż.
     */
    public function marketReport(MarketReport $report): array
    {
        $data = $report->data;
        $totals = $data['totals'];
        $deal = $report->deal_type->getLabel();
        $fields = [];

        foreach ($data['searches'] as $search) {
            $fields[] = [
                'name' => '🔎 '.Str::limit($search['name'], 240),
                'value' => $this->reportLine($search),
                'inline' => false,
            ];
        }

        if ($supply = $data['supply'] ?? null) {
            $fields[] = [
                'name' => '📦 Podaż',
                'value' => "nowe **{$supply['new']}**, zniknęły **{$supply['gone']}**, bilans **".($supply['net'] > 0 ? '+' : '')."{$supply['net']}**"
                    .($supply['active_change'] !== null ? "\nliczba ofert".$this->changeText($supply['active_change']) : ''),
                'inline' => true,
            ];
        }

        if ($report->deal_type === DealType::Rent && ($totals['median_total'] ?? null) !== null) {
            $fields[] = [
                'name' => '🧾 Najem z czynszem adm.',
                'value' => 'mediana **'.$this->money($totals['median_total']).'**'.$this->changeText($totals['median_total_change'] ?? null)."\n({$totals['total_known']} ofert podaje czynsz)",
                'inline' => true,
            ];
        }

        if (count($trend = $data['trend'] ?? []) >= 2) {
            $key = $report->deal_type === DealType::Rent ? 'median_price' : 'median_price_per_m2';
            $fields[] = [
                'name' => '📈 Trend '.count($trend).' tyg. ('.($report->deal_type === DealType::Rent ? 'mediana najmu' : 'mediana za m²').')',
                'value' => Str::limit(collect($trend)->map(fn (array $week) => "{$week['label']}: ".($this->money($week[$key]) ?? '-'))->implode(' → '), 1000),
                'inline' => false,
            ];
        }

        if (($yourList = $data['your_list'] ?? []) !== []) {
            $fields[] = [
                'name' => '👀 Twoja lista',
                'value' => Str::limit(collect($yourList)->take(6)->map(fn (array $item) => '**'.ListingStatus::from($item['status'])->getLabel().'** ['
                    .Str::limit(str_replace(['[', ']'], '', $item['title']), 45)."]({$item['url']}) · ".$this->money($item['price'])
                    .($item['old_price'] ? ' (było '.$this->money($item['old_price']).')' : '')
                    .($item['gone'] ? ' · zdjęta' : '')
                    .($item['viewing_at'] ? ' · oglądanie '.Carbon::parse($item['viewing_at'])->timezone(config('app.timezone'))->format('d.m H:i') : ''))
                    ->implode("\n"), 1000),
                'inline' => false,
            ];
        }

        if (($deals = $data['deals'] ?? null) && $deals['count'] > 0) {
            $fields[] = [
                'name' => "🔥 Okazje tygodnia: {$deals['count']}",
                'value' => Str::limit(collect($deals['top'])->map(fn (array $deal) => ($deal['score'] !== null ? "`{$deal['score']}/10` " : '').'['
                    .Str::limit(str_replace(['[', ']'], '', $deal['title']), 45)."]({$deal['url']}) · ".$this->money($deal['price']).($deal['reasons'] ? "\n{$deal['reasons']}" : ''))
                    ->implode("\n"), 1000),
                'inline' => false,
            ];
        }

        if ($data['districts'] !== []) {
            $fields[] = [
                'name' => '🏘️ Dzielnice (mediana za m²)',
                'value' => Str::limit(collect($data['districts'])->take(8)
                    ->map(fn (array $district) => "{$district['name']}: **".$this->money($district['median_price_per_m2'])."** ({$district['count']})")
                    ->implode("\n"), 1000),
                'inline' => true,
            ];
        }

        if ($data['rooms'] !== []) {
            $fields[] = [
                'name' => '🚪 Pokoje (mediana ceny)',
                'value' => collect($data['rooms'])
                    ->map(fn (array $row) => "{$row['rooms']} pok.: **".$this->money($row['median_price'])."** ({$row['count']})")
                    ->implode("\n"),
                'inline' => true,
            ];
        }

        if ($transactions = $data['transactions'] ?? null) {
            $lines = [];

            if ($transactions['compared'] > 0) {
                $lines[] = "Oferty z tygodnia na tle transakcji w okolicy: **{$transactions['below']}** taniej, **{$transactions['market']}** w granicach rynku, **{$transactions['above']}** drożej"
                    .' (mediana różnicy '.($transactions['median_diff_percent'] > 0 ? '+' : '').$this->percent($transactions['median_diff_percent']).').';
            }

            foreach (['primary' => 'pierwotny', 'secondary' => 'wtórny'] as $market => $label) {
                if ($row = $transactions['last_3_months'][$market] ?? null) {
                    $lines[] = "Rynek {$label}, ostatnie 3 mies.: mediana **".$this->money($row['median_price_per_m2'])."/m²** ({$row['count']} transakcji).";
                }
            }

            if ($lines !== []) {
                $fields[] = ['name' => '📑 Ceny transakcyjne (RCN)', 'value' => Str::limit(implode("\n", $lines), 1000), 'inline' => false];
            }
        }

        $drops = $data['price_drops'];

        if ($drops['count'] > 0) {
            $fields[] = [
                'name' => "📉 Obniżki cen: {$drops['count']}, mediana −".$this->percent($drops['median_percent']),
                'value' => Str::limit(collect($drops['top'])->take(3)
                    ->map(fn (array $drop) => '['.Str::limit(str_replace(['[', ']'], '', $drop['title']), 50)."]({$drop['url']}): "
                        .$this->money($drop['old_price']).' → '.$this->money($drop['new_price']).' (−'.$this->percent($drop['percent']).')')
                    ->implode("\n"), 1000),
                'inline' => false,
            ];
        }

        $timing = $data['time_on_market'] ?? null;

        if ($timing && ($timing['gone'] > 0 || $timing['longest'] !== [])) {
            $fields[] = [
                'name' => "⏱️ Zdjęte z portali: {$timing['gone']}".($timing['median_days'] !== null ? ', mediana '.TimeOnMarket::days($timing['median_days']) : ''),
                'value' => Str::limit(implode("\n", array_filter([
                    $timing['by_rooms'] !== [] ? collect($timing['by_rooms'])->map(fn (array $row) => "{$row['rooms']} pok.: {$row['median_days']} dni")->implode(' · ') : null,
                    ...collect($timing['longest'])->take(3)->map(fn (array $row) => 'Wisi '.($row['known'] ? '' : 'min. ').TimeOnMarket::days($row['days']).': ['
                        .Str::limit(str_replace(['[', ']'], '', $row['title']), 50)."]({$row['url']}) · ".$this->money($row['price']))->all(),
                ])) ?: 'Brak danych.', 1000),
                'inline' => false,
            ];
        }

        if ($data['best_new'] !== []) {
            $fields[] = [
                'name' => '⭐ Najlepsze nowe oferty',
                'value' => Str::limit(collect($data['best_new'])
                    ->map(fn (array $listing) => "`{$listing['score']}/10` [".Str::limit(str_replace(['[', ']'], '', $listing['title']), 50)."]({$listing['url']}) · "
                        .implode(' · ', array_filter([$this->money($listing['price']), $listing['area'] ? $this->area($listing['area']) : null, $listing['district']])))
                    ->implode("\n"), 1000),
                'inline' => false,
            ];
        }

        $median = array_filter([
            $totals['median_price'] !== null ? '**'.$this->money($totals['median_price']).'**'.$this->changeText($totals['median_price_change']) : null,
            $totals['median_price_per_m2'] !== null ? '**'.$this->money($totals['median_price_per_m2']).'/m²**'.$this->changeText($totals['median_price_per_m2_change']) : null,
        ]);

        return ['embeds' => [$this->fit([
            'author' => ['name' => "📊 Raport tygodnia · {$deal}"],
            'title' => "{$deal}: rynek {$report->periodLabel()}",
            'url' => rtrim(config('app.url'), '/')."/admin/market-report?report={$report->id}",
            'description' => Str::limit((($summary = $data['ai_summary'] ?? null) ? "🤖 {$summary['text']}".collect($summary['points'])->map(fn (string $point) => "\n• {$point}")->implode('')."\n\n" : '')
                ."**{$totals['active']}** ".$this->offersWord($totals['active'])." w tym tygodniu, z tego **{$totals['new']}** ".($totals['new'] === 1 ? 'nowa' : 'nowych').'.'
                .($median !== [] ? "\nMediana: ".implode(', ', $median).'.' : '')
                .($data['has_previous'] ? '' : "\nPierwszy raport, porównanie z poprzednim tygodniem będzie od następnego."), 4000),
            'fields' => array_slice($fields, 0, 25),
            'color' => self::COLOR_INFO,
            'footer' => ['text' => 'Oferty widziane przez nasze wyszukiwania w ostatnich 7 dniach, bez duplikatów.'],
            'timestamp' => now()->toIso8601String(),
        ])]];
    }

    /**
     * Monitor cen: zmiany ceny lub dostępności jednego produktu.
     *
     * @param  list<array{type: string, text: string}>  $events
     */
    public function productEvents(Product $product, array $events): array
    {
        $types = array_column($events, 'type');
        [$icon, $color] = match (true) {
            in_array('target', $types, true) => ['🎯 Cena docelowa', self::COLOR_GOOD],
            in_array('drop', $types, true), in_array('cheaper_elsewhere', $types, true) => ['📉 Taniej', self::COLOR_GOOD],
            in_array('back_in_stock', $types, true) => ['✅ Znowu dostępny', self::COLOR_GOOD],
            in_array('rise', $types, true) => ['📈 Drożej', self::COLOR_OK],
            default => ['📦 Zmiana', self::COLOR_WEAK],
        };
        $best = $product->offers->firstWhere('id', $product->best_offer_id);
        $advice = $product->ai_advice;
        $fields = array_values(array_filter([
            ['name' => '💰 Najlepsza cena', 'value' => $product->best_price !== null ? Format::pln($product->best_price).($best ? "\n{$best->shop}" : '') : 'niedostępny', 'inline' => true],
            $product->lowest_price !== null ? ['name' => '⬇️ Najniższa', 'value' => Format::pln($product->lowest_price).($product->lowest_price_at ? "\n".$product->lowest_price_at->format('d.m.Y') : ''), 'inline' => true] : null,
            $product->target_price !== null ? ['name' => '🎯 Cel', 'value' => Format::pln($product->target_price), 'inline' => true] : null,
            $advice ? ['name' => '🤖 AI: '.(ProductAdvisor::VERDICTS[$advice['verdict']] ?? $advice['verdict'])." ({$advice['score']}/10)", 'value' => Str::limit((string) $advice['summary'], 1000) ?: '-', 'inline' => false] : null,
            $product->offers->where('is_active', true)->count() > 1 ? ['name' => '🏪 Sklepy', 'value' => Str::limit($product->offers->where('is_active', true)->sortBy(fn (ProductOffer $offer) => $offer->isBuyable() ? $offer->price : PHP_INT_MAX)->take(6)
                ->map(fn (ProductOffer $offer) => "[{$offer->shop}]({$offer->url}): ".($offer->available === false ? 'niedostępny' : (Format::pln($offer->price) ?? '?')))->implode("\n"), 1000), 'inline' => false] : null,
        ]));

        $embed = [
            'author' => ['name' => "{$icon} · Monitor cen"],
            'title' => Str::limit($product->name, 250),
            'url' => $best?->url ?? $product->primaryOffer()?->url,
            'description' => Str::limit(collect($events)->pluck('text')->implode("\n"), 4000),
            'color' => $color,
            'fields' => $fields,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($product->image_url) {
            $embed['thumbnail'] = ['url' => $product->image_url];
        }

        return ['embeds' => [$embed]];
    }

    /**
     * Monitor cen w raporcie tygodnia: najlepsza cena teraz i tydzień temu.
     *
     * @param  list<array<string, mixed>>  $products
     */
    public function productsWeekly(array $products): array
    {
        $lines = collect($products)->map(fn (array $product) => '['.Str::limit(str_replace(['[', ']'], '', $product['name']), 60).']('
            .rtrim(config('app.url'), '/')."/admin/products/{$product['id']}): **".(Format::pln($product['best_price']) ?? 'niedostępny').'**'
            .$this->changeText($product['change_percent'] !== null && abs($product['change_percent']) >= 0.1 ? $product['change_percent'] : null)
            .($product['shop'] ? " · {$product['shop']}" : '')
            .($product['target_reached'] ? ' · 🎯 cena docelowa' : '')
            .($product['verdict'] ? ' · AI: '.(ProductAdvisor::VERDICTS[$product['verdict']] ?? $product['verdict'])." {$product['score']}/10" : ''));

        return ['embeds' => [[
            'author' => ['name' => '📊 Raport tygodnia · Monitor cen'],
            'title' => 'Ceny produktów: tydzień',
            'url' => rtrim(config('app.url'), '/').'/admin/products',
            'description' => Str::limit($lines->implode("\n"), 4000),
            'color' => self::COLOR_INFO,
            'footer' => ['text' => 'Zmiana: najlepsza cena teraz wobec ceny sprzed 7 dni.'],
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
            $listing->deal_reasons ? ['name' => '🔥 Okazja', 'value' => Str::limit(DealFinder::summary($listing->deal_reasons), 1000), 'inline' => false] : null,
            ($transactions = $listing->deal_type === DealType::Sale ? TransactionComparator::describe($listing->transaction_comparison) : null) ? ['name' => '📑 Ceny transakcyjne', 'value' => Str::limit($transactions, 1000), 'inline' => false] : null,
            ($commutes = app(CommuteCalculator::class)->describe($listing)) ? ['name' => '🚗 Dojazd', 'value' => Str::limit(implode("\n", $commutes), 1000), 'inline' => false] : null,
            $listing->risk_flags ? ['name' => '⚠️ Uwaga', 'value' => Str::limit(collect($listing->risk_flags)->pluck('message')->implode("\n"), 1000), 'inline' => false] : null,
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

        return self::PORTAL_COLORS[$listing->portal] ?? self::COLOR_INFO;
    }

    private function digestLine(Listing $listing, ?string $searchNames = null): string
    {
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
            $searchNames,
        ])).($listing->risk_flags ? "\n⚠️ ".collect($listing->risk_flags)->pluck('message')->first() : '');
    }

    /**
     * @param  array{active: int, new: int, median_price: int|null, median_price_per_m2: int|null, median_price_change: float|null, median_price_per_m2_change: float|null}  $summary
     */
    /**
     * Discord przyjmuje embed do 6000 znaków razem: przy dłuższym odcinamy ostatnie pola.
     *
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private function fit(array $embed): array
    {
        $length = fn (array $embed) => mb_strlen(($embed['title'] ?? '').($embed['description'] ?? '').($embed['author']['name'] ?? '').($embed['footer']['text'] ?? ''))
            + collect($embed['fields'] ?? [])->sum(fn (array $field) => mb_strlen($field['name'].$field['value']));

        while ($length($embed) > 5900 && ($embed['fields'] ?? []) !== []) {
            array_pop($embed['fields']);
        }

        return $embed;
    }

    private function reportLine(array $summary): string
    {
        return "{$summary['active']} ".$this->offersWord($summary['active']).", {$summary['new']} ".($summary['new'] === 1 ? 'nowa' : 'nowych')
            .($summary['median_price'] !== null ? "\nmediana ".$this->money($summary['median_price']).$this->changeText($summary['median_price_change']) : '')
            .($summary['median_price_per_m2'] !== null ? ' · '.$this->money($summary['median_price_per_m2']).'/m²'.$this->changeText($summary['median_price_per_m2_change']) : '');
    }

    private function changeText(?float $change): string
    {
        if ($change === null) {
            return '';
        }

        return match (true) {
            $change > 0 => ' (▲ '.$this->percent($change).')',
            $change < 0 => ' (▼ '.$this->percent(abs($change)).')',
            default => ' (bez zmian)',
        };
    }

    private function percent(?float $value): string
    {
        return $value === null ? '?' : str_replace('.', ',', (string) round($value, 1)).'%';
    }

    private function portalName(string $portal): string
    {
        return Portals::label($portal);
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
