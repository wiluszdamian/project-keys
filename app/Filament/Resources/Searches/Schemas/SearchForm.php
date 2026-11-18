<?php

namespace App\Filament\Resources\Searches\Schemas;

use App\Enums\DealType;
use App\Enums\PropertyType;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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
                            ->helperText('Dla Otodom miejscowość musi mieć lokalizację w Ustawieniach.')
                            ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                if (in_array('otodom', $get('portals') ?? [], true)
                                    && ! array_key_exists(Str::slug((string) $value), config('scrapers.otodom.locations'))) {
                                    $fail('Otodom nie zna tej miejscowości. Dodaj ją w Ustawienia → Lokalizacje Otodom albo odznacz Otodom.');
                                }
                            }),
                        TagsInput::make('districts')
                            ->label('Dzielnice')
                            ->placeholder('Puste = całe miasto')
                            ->helperText('Oferta musi mieć jedną z tych dzielnic (tak jak podaje portal).'),
                    ]),

                Section::make('Filtry')
                    ->description('Puste pole = bez ograniczenia.')
                    ->columns(2)
                    ->schema([
                        ...self::range('price', 'Cena', 'zł'),
                        ...self::range('area', 'Metraż', 'm²', max: 10000),
                        ...self::range('rooms', 'Pokoje', null, min: 1, max: 20),
                    ]),

                Section::make('Portale, AI i powiadomienia')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('portals')
                            ->label('Portale')
                            ->options(collect(config('scrapers.portals'))->mapWithKeys(fn ($portal) => [$portal => match ($portal) {
                                'otodom' => 'Otodom',
                                'olx' => 'OLX',
                                default => $portal,
                            }])->all())
                            ->default(config('scrapers.portals'))
                            ->required()
                            ->live(),
                        CheckboxList::make('notify_channels')
                            ->label('Powiadomienia')
                            ->options(collect(config('notify.channels'))->mapWithKeys(fn ($channel) => [$channel => match ($channel) {
                                'discord' => 'Discord',
                                'telegram' => 'Telegram (jeszcze nie działa)',
                                default => $channel,
                            }])->all())
                            ->default(['discord'])
                            ->required(),
                        TextInput::make('min_score_to_notify')
                            ->label('Minimalna ocena AI do powiadomienia')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(6)
                            ->required()
                            ->suffix('/ 10'),
                        Toggle::make('is_active')
                            ->label('Aktywne')
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
            ]);
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
