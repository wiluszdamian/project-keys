<?php

namespace App\Filament\Resources\Listings;

use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Filament\Resources\Listings\Schemas\ListingForm;
use App\Filament\Resources\Listings\Schemas\ListingInfolist;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

use function Filament\Support\original_request;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $modelLabel = 'oferta';

    protected static ?string $pluralModelLabel = 'oferty';

    protected static ?string $navigationLabel = 'Oferty';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ListingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ListingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ListingsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'view' => ViewListing::route('/{record}'),
            'edit' => EditListing::route('/{record}/edit'),
        ];
    }

    /**
     * Oferty nie są podświetlone, gdy otwarta jest zakładka Okazje (ma własną pozycję w menu).
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item) => $item->activeWhen(fn (): bool => original_request()->routeIs(static::getNavigationItemActiveRoutePattern())
                && original_request()->query('tab') !== 'deals'),
            parent::getNavigationItems(),
        );
    }
}
