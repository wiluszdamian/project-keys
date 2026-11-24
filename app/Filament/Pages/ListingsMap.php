<?php

namespace App\Filament\Pages;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Filament\Support\Format;
use App\Models\CommutePlace;
use App\Models\Listing;
use App\Models\Search;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Mapa ofert z filtrami. Pinezki w kolorze oceny AI, miejsca dojazdu na niebiesko.
 */
class ListingsMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Mapa';

    protected static ?string $title = 'Mapa ofert';

    protected static ?string $slug = 'map';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.listings-map';

    private const LIMIT = 1500;

    /** @var array<string, mixed> */
    public ?array $filters = [];

    public function mount(): void
    {
        $this->filtersForm->fill(['deal_type' => null, 'search' => null, 'days' => '30', 'above_threshold' => true, 'hide_rejected' => true]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->columns(['default' => 2, 'lg' => 5])
            ->components([
                Select::make('deal_type')->label('Rodzaj')->options(DealType::class)->placeholder('Wszystkie')->live(),
                Select::make('search')->label('Wyszukiwanie')->options(fn () => Search::orderBy('id')->pluck('name', 'id'))->placeholder('Wszystkie')->live(),
                Select::make('days')->label('Znalezione')->options(['1' => 'ostatnia doba', '7' => 'ostatni tydzień', '30' => 'ostatnie 30 dni'])->selectablePlaceholder(false)->live(),
                Toggle::make('above_threshold')->label('Tylko z oceną od progu')->inline(false)->live(),
                Toggle::make('hide_rejected')->label('Ukryj odrzucone')->inline(false)->live(),
            ]);
    }

    public function updatedFilters(): void
    {
        $this->dispatch('map-data', data: $this->mapData());
    }

    /**
     * @return array{listings: list<array<string, mixed>>, places: list<array<string, mixed>>, total: int, limited: bool}
     */
    public function mapData(): array
    {
        $filters = $this->filters ?? [];

        $query = Listing::query()
            ->whereHas('searches')
            ->whereNull('duplicate_of_id')
            ->whereNotNull('lat')
            ->available()
            ->where('last_seen_at', '>=', now()->subDays((int) ($filters['days'] ?? 30)))
            ->withMax('analyses', 'score')
            ->when($filters['deal_type'] ?? null, fn (Builder $q, $deal) => $q->where('deal_type', $deal instanceof DealType ? $deal->value : $deal))
            ->when($filters['search'] ?? null, fn (Builder $q, $id) => $q->whereHas('searches', fn (Builder $q) => $q->whereKey($id)))
            ->when($filters['hide_rejected'] ?? true, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value)))
            ->when($filters['above_threshold'] ?? true, fn (Builder $q) => $q->whereHas('analyses', fn (Builder $q) => $q
                ->join('searches', 'searches.id', '=', 'listing_analyses.search_id')
                ->whereColumn('listing_analyses.score', '>=', 'searches.min_score_to_notify')));

        $total = (clone $query)->count();

        $listings = $query->orderByRaw('analyses_max_score desc nulls last')->limit(self::LIMIT)->get()->map(fn (Listing $listing) => [
            'lat' => $listing->lat,
            'lng' => $listing->lng,
            'title' => Str::limit($listing->title, 80),
            'price' => Format::pln($listing->price).($listing->rent_extra ? ' + '.Format::pln($listing->rent_extra) : ''),
            'meta' => collect([Format::area($listing->area), $listing->rooms ? "{$listing->rooms} pok." : null, $listing->district ?? $listing->city])->filter()->implode(' · '),
            'deal' => $listing->deal_type->getLabel(),
            'score' => $listing->analyses_max_score,
            'approx' => in_array($listing->geo_precision, ['district', 'city'], true),
            'risk' => filled($listing->risk_flags),
            'url' => route('filament.admin.resources.listings.view', $listing),
        ])->all();

        $places = CommutePlace::usable()->get()->map(fn (CommutePlace $place) => [
            'lat' => $place->lat,
            'lng' => $place->lng,
            'name' => $place->name,
        ])->all();

        return ['listings' => $listings, 'places' => $places, 'total' => $total, 'limited' => $total > self::LIMIT];
    }
}
