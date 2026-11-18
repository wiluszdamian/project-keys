<?php

namespace App\Filament\Resources\ScrapeRuns;

use App\Filament\Resources\ScrapeRuns\Pages\ListScrapeRuns;
use App\Filament\Resources\ScrapeRuns\Tables\ScrapeRunsTable;
use App\Models\ScrapeRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Przebiegi scraperów, tylko do podglądu.
 */
class ScrapeRunResource extends Resource
{
    protected static ?string $model = ScrapeRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $modelLabel = 'przebieg';

    protected static ?string $pluralModelLabel = 'przebiegi';

    protected static ?string $navigationLabel = 'Przebiegi';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return ScrapeRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScrapeRuns::route('/'),
        ];
    }
}
