<?php

namespace App\Filament\Resources\ScrapeRuns\Tables;

use App\Enums\ScrapeRunStatus;
use App\Models\ScrapeRun;
use App\Support\Portals;
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
                    ->label('Wyszukiwanie')
                    ->visibleFrom('md'),
                TextColumn::make('portal')
                    ->label('Portal')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Portals::label($state))
                    ->color(fn (string $state) => Portals::color($state)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->tooltip(fn (ScrapeRun $record) => $record->error_message),
                TextColumn::make('pages_fetched')
                    ->label('Strony')
                    ->visibleFrom('2xl'),
                TextColumn::make('found_count')
                    ->label('Znalezione')
                    ->visibleFrom('xl'),
                TextColumn::make('new_count')
                    ->label('Nowe')
                    ->weight(fn ($state) => $state > 0 ? 'bold' : null)
                    ->visibleFrom('sm'),
                TextColumn::make('finished_at')
                    ->label('Czas')
                    ->state(fn (ScrapeRun $record) => $record->finished_at ? (int) $record->started_at->diffInSeconds($record->finished_at, true).' s' : null)
                    ->placeholder('trwa')
                    ->visibleFrom('xl'),
                TextColumn::make('error_message')
                    ->label('Błąd')
                    ->limit(30)
                    ->tooltip(fn (ScrapeRun $record) => $record->error_message)
                    ->placeholder('-')
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('search')
                    ->label('Wyszukiwanie')
                    ->relationship('search', 'name'),
                SelectFilter::make('portal')
                    ->label('Portal')
                    ->options(Portals::allOptions()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ScrapeRunStatus::class),
            ]);
    }
}
