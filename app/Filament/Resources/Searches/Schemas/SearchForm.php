<?php

namespace App\Filament\Resources\Searches\Schemas;

use App\Enums\DealType;
use App\Enums\NotifyMode;
use App\Enums\PropertyType;
use App\Notifications\NotifyGroups;
use App\Support\Portals;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SearchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Co szukamy')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nazwa')
                            ->placeholder('np. Rzeszów wynajem 3 pokoje')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('deal_type')
                            ->label('Rodzaj')
                            ->options(DealType::class)
                            ->required()
                            ->native(false),
                        Select::make('property_type')
                            ->label('Nieruchomość')
                            ->options(PropertyType::class)
                            ->default(PropertyType::Flat->value)
                            ->required()
                            ->native(false),
                        TextInput::make('city')
                            ->label('Miasto lub miejscowość')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Otodom, Morizon, Gratka, Domiporta i Adresowo potrzebują lokalizacji z Ustawień.')
                            ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                $missing = collect($get('portals') ?? [])->filter(fn ($portal) => Portals::missingLocation($portal, (string) $value));

                                if ($missing->isNotEmpty()) {
                                    $fail($missing->map(Portals::label(...))->implode(', ').' nie zna tej miejscowości. Dodaj ją w Ustawienia → Lokalizacje albo odznacz te portale.');
                                }
                            }),
                        TagsInput::make('districts')
                            ->label('Dzielnice')
                            ->placeholder('Puste = całe miasto')
                            ->helperText('Oferta musi mieć jedną z tych dzielnic (tak jak podaje portal).'),
                    ]),

                Section::make('Filtry')
                    ->description('Puste pole = bez ograniczenia.')
                    // Od i do obok siebie także na telefonie.
                    ->columns(['default' => 2])
                    ->schema([
                        ...self::range('price', 'Cena', 'zł'),
                        ...self::range('area', 'Metraż', 'm²', max: 10000),
                        ...self::range('rooms', 'Pokoje', null, min: 1, max: 20),
                    ]),

                Section::make('Portale i AI')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('portals')
                            ->label('Portale')
                            ->options(Portals::options())
                            ->columns(['default' => 2])
                            ->default(config('scrapers.portals'))
                            ->required()
                            ->live(),
                        Toggle::make('is_active')
                            ->label('Wyszukiwanie aktywne')
                            ->helperText('Wyłączone: portale nie są sprawdzane.')
                            ->default(true)
                            ->inline(false),
                        Textarea::make('ai_preferences')
                            ->label('Czego szukasz (dla AI)')
                            ->placeholder('np. Najchętniej osiedle Projektant, wykończone, gotowe do zamieszkania. Parter to minus.')
                            ->helperText('AI ocenia każdą nową ofertę według tego opisu, filtrów i cen w okolicy.')
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),

                Section::make('Powiadomienia')
                    ->columns(2)
                    ->schema([
                        Toggle::make('notify_enabled')
                            ->label('Wysyłaj powiadomienia')
                            ->helperText('Wyłączone: oferty dalej są pobierane i oceniane, widzisz je tylko w panelu.')
                            ->default(true)
                            ->live()
                            ->inline(false)
                            ->columnSpanFull(),
                        CheckboxList::make('notify_channels')
                            ->label('Kanały')
                            ->options(collect(config('notify.channels'))->mapWithKeys(fn ($channel) => [$channel => match ($channel) {
                                'discord' => 'Discord',
                                'telegram' => 'Telegram (jeszcze nie działa)',
                                default => $channel,
                            }])->all())
                            ->default(['discord'])
                            ->required()
                            ->visible(fn (Get $get) => $get('notify_enabled'))
                            ->dehydratedWhenHidden(),
                        TextInput::make('min_score_to_notify')
                            ->label('Wysyłaj oferty z oceną AI od')
                            ->helperText('Słabsze oferty są tylko w panelu.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(6)
                            ->required()
                            ->suffix('/ 10')
                            ->visible(fn (Get $get) => $get('notify_enabled'))
                            ->dehydratedWhenHidden(),
                        Select::make('notify_mode')
                            ->label('Jak często')
                            ->options(NotifyMode::class)
                            ->default(NotifyMode::Instant->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->helperText(fn (Get $get) => self::groupNotice($get('deal_type')) ?? match (self::modeValue($get('notify_mode'))) {
                                NotifyMode::Instant->value => 'Każda dobra oferta osobno. Gdy w jednym przebiegu jest ich dużo, przychodzi jedno podsumowanie.',
                                default => 'Dobre oferty czekają i przychodzą razem w jednym podsumowaniu. Obniżki cen dalej od razu.',
                            })
                            ->visible(fn (Get $get) => $get('notify_enabled'))
                            ->dehydratedWhenHidden(),
                        TextInput::make('notify_every_hours')
                            ->label('Co ile godzin')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(72)
                            ->default(3)
                            ->suffix('h')
                            ->required(fn (Get $get) => self::modeValue($get('notify_mode')) === NotifyMode::EveryHours->value)
                            ->visible(fn (Get $get) => $get('notify_enabled') && self::modeValue($get('notify_mode')) === NotifyMode::EveryHours->value),
                        TimePicker::make('notify_daily_at')
                            ->label('O której godzinie')
                            ->seconds(false)
                            ->format('H:i')
                            ->default('20:00')
                            ->required(fn (Get $get) => self::modeValue($get('notify_mode')) === NotifyMode::Daily->value)
                            ->visible(fn (Get $get) => $get('notify_enabled') && self::modeValue($get('notify_mode')) === NotifyMode::Daily->value),
                    ]),
            ]);
    }

    /**
     * Tekst, gdy kanał (wynajem albo sprzedaż) ma zbiorcze powiadomienia, które wygrywają z tym ustawieniem.
     */
    private static function groupNotice(mixed $dealType): ?string
    {
        $deal = $dealType instanceof DealType ? $dealType : DealType::tryFrom((string) $dealType);

        if (! $deal || ! NotifyGroups::isCombined($deal)) {
            return null;
        }

        return 'Kanał '.mb_strtolower($deal->getLabel()).' ma zbiorcze powiadomienie ('.mb_strtolower(NotifyGroups::periodLabel($deal)).') i ono wygrywa z tym ustawieniem. Zmienisz to w Ustawienia → Powiadomienia.';
    }

    private static function modeValue(mixed $mode): ?string
    {
        return $mode instanceof NotifyMode ? $mode->value : $mode;
    }

    /**
     * Para pól od-do. Maksimum nie może być mniejsze od minimum.
     *
     * @return list<TextInput>
     */
    private static function range(string $field, string $label, ?string $suffix, int $min = 0, ?int $max = null): array
    {
        return [
            TextInput::make("{$field}_min")
                ->label("{$label} od")
                ->integer()
                ->minValue($min)
                ->maxValue($max)
                ->suffix($suffix),
            TextInput::make("{$field}_max")
                ->label("{$label} do")
                ->integer()
                ->minValue($min)
                ->maxValue($max)
                ->suffix($suffix)
                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get, $field) {
                    $from = $get("{$field}_min");

                    if (filled($value) && filled($from) && (int) $value < (int) $from) {
                        $fail('Wartość „do” nie może być mniejsza niż „od”.');
                    }
                }),
        ];
    }
}
