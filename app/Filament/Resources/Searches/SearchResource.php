<?php

namespace App\Filament\Resources\Searches;

use App\Filament\Resources\Searches\Pages\CreateSearch;
use App\Filament\Resources\Searches\Pages\EditSearch;
use App\Filament\Resources\Searches\Pages\ListSearches;
use App\Filament\Resources\Searches\Schemas\SearchForm;
use App\Filament\Resources\Searches\Tables\SearchesTable;
use App\Models\Search;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SearchResource extends Resource
{
    protected static ?string $model = Search::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $modelLabel = 'wyszukiwanie';

    protected static ?string $pluralModelLabel = 'wyszukiwania';

    protected static ?string $navigationLabel = 'Wyszukiwania';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SearchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SearchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSearches::route('/'),
            'create' => CreateSearch::route('/create'),
            'edit' => EditSearch::route('/{record}/edit'),
        ];
    }
}
