<?php

namespace App\Filament\Pages;

use App\Support\LogReader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Logi aplikacji (app, workery, scheduler) i nieudane zadania kolejki.
 */
class Logs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'Logi';

    protected static ?string $title = 'Logi';

    protected static ?string $slug = 'logs';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.logs';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    public function mount(LogReader $reader): void
    {
        $this->filtersForm->fill([
            'file' => array_key_first($reader->files()),
            'level' => 'warning',
            'search' => null,
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->columns(['default' => 1, 'md' => 3])
            ->components([
                Select::make('file')
                    ->label('Plik')
                    ->options(fn () => app(LogReader::class)->files())
                    ->placeholder('Brak plików')
                    ->live(),
                Select::make('level')
                    ->label('Poziom (ten i poważniejsze)')
                    ->options(LogReader::LEVELS)
                    ->placeholder('Wszystkie')
                    ->live(),
                TextInput::make('search')
                    ->label('Szukaj')
                    ->placeholder('np. otodom, 403, AnalyzeListingJob')
                    ->live(debounce: 500),
            ]);
    }

    /**
     * @return list<array{time: string, level: string, message: string, details: string}>
     */
    public function getEntriesProperty(): array
    {
        $filters = $this->filters ?? [];

        return app(LogReader::class)->entries($filters['file'] ?? null, $filters['level'] ?? null, $filters['search'] ?? null);
    }

    /**
     * Ostatnie nieudane zadania kolejki (po wszystkich próbach).
     *
     * @return Collection<int, object>
     */
    public function getFailedJobsProperty(): Collection
    {
        return DB::table('failed_jobs')->latest('failed_at')->limit(20)->get()->map(function (object $job) {
            $payload = json_decode($job->payload, true) ?: [];

            return (object) [
                'name' => class_basename($payload['displayName'] ?? 'nieznane zadanie'),
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'message' => Str::limit(strtok($job->exception, "\n") ?: '', 300),
                'exception' => Str::limit($job->exception, 8000),
            ];
        });
    }

    public function getFailedJobsCountProperty(): int
    {
        return DB::table('failed_jobs')->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Pobierz plik')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => app(LogReader::class)->path($this->filters['file'] ?? null) !== null)
                ->action(fn (): BinaryFileResponse => response()->download(app(LogReader::class)->path($this->filters['file'] ?? null))),
            Action::make('refresh')
                ->label('Odśwież')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => null),
        ];
    }

    public static function levelColor(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'danger',
            'warning' => 'warning',
            'notice', 'info' => 'info',
            default => 'gray',
        };
    }
}
