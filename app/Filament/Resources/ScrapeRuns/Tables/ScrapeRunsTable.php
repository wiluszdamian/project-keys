<?php

namespace App\Filament\Resources\ScrapeRuns\Tables;

use App\Enums\ScrapeRunStatus;
use App\Models\ScrapeRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScrapeRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('started_at')
                    ->label('Start')
                    ->dateTime('d.m H:i')
                    ->sortable(),
                TextColumn::make('search.name')
                    ->label('Wyszukiwanie'),
                TextColumn::make('portal')
                    ->label('Portal')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'olx' ? 'OLX' : 'Otodom')
                    ->color(fn (string $state) => $state === 'olx' ? 'info' : 'success'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('pages_fetched')
                    ->label('Strony'),
                TextColumn::make('found_count')
                    ->label('Znalezione'),
                TextColumn::make('new_count')
                    ->label('Nowe')
                    ->weight(fn ($state) => $state > 0 ? 'bold' : null),
                TextColumn::make('finished_at')
                    ->label('Czas')
                    ->state(fn (ScrapeRun $record) => $record->finished_at ? (int) $record->started_at->diffInSeconds($record->finished_at, true).' s' : null)
                    ->placeholder('trwa'),
                TextColumn::make('error_message')
                    ->label('Błąd')
                    ->limit(60)
                    ->tooltip(fn (ScrapeRun $record) => $record->error_message)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('search')
                    ->label('Wyszukiwanie')
                    ->relationship('search', 'name'),
                SelectFilter::make('portal')
                    ->label('Portal')
                    ->options(['otodom' => 'Otodom', 'olx' => 'OLX']),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ScrapeRunStatus::class),
            ]);
    }
}
