<?php

namespace App\Filament\Widgets;

use App\Enums\ListingStatus;
use App\Filament\Pages\Assistant;
use App\Filament\Pages\ListingsMap;
use App\Filament\Pages\MarketReportPage;
use App\Filament\Pages\MortgagePage;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Searches\SearchResource;
use App\Models\Listing;
use App\Models\Search;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Strona główna: skróty do najważniejszych miejsc z licznikami. Listę ofert pokazuje zakładka Oferty, bez powtórki tutaj.
 */
class QuickLinks extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-links';

    /**
     * @return list<array{label: string, description: string, url: string, icon: string, count: ?int, color: string}>
     */
    public function getLinks(): array
    {
        $listings = fn () => Listing::query()->whereNull('duplicate_of_id')->whereHas('searches', fn (Builder $query) => $query->where('is_active', true));

        return [
            [
                'label' => 'Nowe oferty',
                'description' => 'Z ostatnich 24 godzin',
                'url' => ListingResource::getUrl('index'),
                'icon' => 'heroicon-o-home-modern',
                'count' => $listings()->where('first_seen_at', '>=', now()->subDay())->count(),
                'color' => 'primary',
            ],
            [
                'label' => 'Okazje',
                'description' => 'Taniej niż rynek albo po obniżce',
                'url' => ListingResource::getUrl('index', ['tab' => 'deals']),
                'icon' => 'heroicon-o-fire',
                'count' => $listings()->whereNotNull('deal_at')->count(),
                'color' => 'success',
            ],
            [
                'label' => 'Ulubione i oglądanie',
                'description' => 'Oferty, którymi się zajmujesz',
                'url' => ListingResource::getUrl('index', ['tab' => 'mine']),
                'icon' => 'heroicon-o-star',
                'count' => Listing::query()->whereIn('status', [ListingStatus::Favorite->value, ListingStatus::Contacted->value, ListingStatus::Viewing->value])->count(),
                'color' => 'warning',
            ],
            [
                'label' => 'Wyszukiwania',
                'description' => 'Filtry, portale, powiadomienia',
                'url' => SearchResource::getUrl('index'),
                'icon' => 'heroicon-o-magnifying-glass',
                'count' => Search::active()->count(),
                'color' => 'gray',
            ],
            [
                'label' => 'Mapa',
                'description' => 'Oferty i miejsca dojazdu',
                'url' => ListingsMap::getUrl(),
                'icon' => 'heroicon-o-map',
                'count' => null,
                'color' => 'gray',
            ],
            [
                'label' => 'Kredyt',
                'description' => 'Symulacja RKM',
                'url' => MortgagePage::getUrl(),
                'icon' => 'heroicon-o-banknotes',
                'count' => null,
                'color' => 'gray',
            ],
            [
                'label' => 'Asystent',
                'description' => 'Zapytaj o oferty',
                'url' => Assistant::getUrl(),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'count' => null,
                'color' => 'gray',
            ],
            [
                'label' => 'Raport rynku',
                'description' => 'Ceny i zmiany z tygodnia',
                'url' => MarketReportPage::getUrl(),
                'icon' => 'heroicon-o-chart-bar',
                'count' => null,
                'color' => 'gray',
            ],
        ];
    }
}
