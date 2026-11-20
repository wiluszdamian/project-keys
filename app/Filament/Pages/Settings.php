<?php

namespace App\Filament\Pages;

use App\Notifications\NotifyGroups;
use App\Support\AppSettings;
use App\Support\Portals;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Ustawienia całej aplikacji. Zapis restartuje workery (queue:restart), żeby wzięły nowe wartości.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Ustawienia';

    protected static ?string $title = 'Ustawienia';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(AppSettings::formValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Pobieranie')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->schema([
                                Section::make('Jak często i jak szybko')
                                    ->description('Za krótkie przerwy albo za dużo stron zwiększają ryzyko blokady przez portal.')
                                    ->columns(3)
                                    ->schema([
                                        self::int('scrape_interval_minutes', 'Co ile minut sprawdzać wyszukiwanie', 'min', 5, 1440),
                                        self::int('scraper_max_pages', 'Ile stron wyników na portal', null, 1, 10),
                                        self::int('scraper_block_hours', 'Pauza po blokadzie (403/429)', 'h', 1, 48),
                                        self::int('scraper_delay_ms', 'Przerwa między zapytaniami', 'ms', 1000, 60000),
                                        self::int('scraper_jitter_ms', 'Losowy dodatek do przerwy', 'ms', 0, 60000),
                                        self::int('scraper_retries', 'Ponowienia przy błędzie serwera', null, 0, 5),
                                        TextInput::make('scraper_user_agent')
                                            ->label('User-Agent')
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Lokalizacje')
                                    ->description('Część portali potrzebuje w adresie własnej ścieżki dla każdej miejscowości. Klucz to nazwa miejscowości bez polskich znaków, małymi literami, spacje jako myślniki (np. rudna-mala). Ścieżkę skopiuj z adresu wyników na stronie portalu. OLX, Rzeszowiak i Nieruchomości-online jej nie potrzebują.')
                                    ->schema([
                                        Tabs::make('locations')
                                            ->contained(false)
                                            ->tabs([
                                                self::locations('otodom', 'część adresu po /mieszkanie/', 'podkarpackie/rzeszowski/glogow-malopolski/rudna-mala'),
                                                self::locations('morizon', 'część adresu po /mieszkania/najnowsze/', 'rzeszowski/gmina-glogow-malopolski/rudna-mala'),
                                                self::locations('gratka', 'część adresu po /nieruchomosci/mieszkania/', 'rudna-mala-37382'),
                                                self::locations('domiporta', 'część adresu po /mieszkanie/sprzedam/', 'podkarpackie/rudna-mala'),
                                                self::locations('adresowo', 'część adresu po /mieszkania/', 'rudna-mala'),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Ceny transakcyjne')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->schema([
                                Section::make('Rejestr Cen Nieruchomości (RCN)')
                                    ->description('Prawdziwe ceny sprzedaży mieszkań z aktów notarialnych, z usługi GUGiK (bezpłatnej od lutego 2026). Pobieranie w każdą niedzielę o 4:30. Każda oferta sprzedaży dostaje porównanie z transakcjami w okolicy o podobnym metrażu.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('rcn_enabled')
                                            ->label('Porównuj oferty sprzedaży z cenami transakcyjnymi')
                                            ->inline(false),
                                        TextInput::make('rcn_counties')
                                            ->label('Powiaty (kody TERYT, po przecinku)')
                                            ->helperText('1863 to Rzeszów, 1816 powiat rzeszowski. Kod powiatu to 4 pierwsze cyfry kodu TERYT gminy.')
                                            ->regex('/^\s*\d{4}(\s*,\s*\d{4})*\s*$/'),
                                    ]),
                            ]),

                        Tab::make('Powiadomienia')
                            ->icon(Heroicon::OutlinedBell)
                            ->schema([
                                Section::make('Kiedy i ile')
                                    ->columns(2)
                                    ->schema([
                                        self::int('notify_digest_threshold', 'Podsumowanie, gdy nowych ofert w przebiegu jest więcej niż', null, 1, 100),
                                        self::int('notify_digest_size', 'Ile ofert w podsumowaniu', null, 1, 25),
                                        self::int('notify_alert_cooldown_hours', 'Ten sam alert systemowy najwyżej raz na', 'h', 1, 72),
                                        Toggle::make('notify_price_drops')
                                            ->label('Wysyłaj obniżki cen wysłanych ofert')
                                            ->inline(false),
                                        Toggle::make('notify_weekly_report')
                                            ->label('Tygodniowy raport rynku')
                                            ->helperText('W każdą sobotę o 20:00, na Discordzie i w panelu (Raport rynku).')
                                            ->inline(false),
                                        Toggle::make('notify_alerts_discord')
                                            ->label('Alerty systemowe także na Discorda')
                                            ->helperText('Alerty (blokada portalu, brak przebiegów, błędy AI) zawsze trafiają do dzwoneczka w panelu.')
                                            ->inline(false),
                                    ]),
                                Section::make('Zbiorcze powiadomienia kanałów')
                                    ->description('Jedna wiadomość na Discordzie dla wszystkich wyszukiwań danego typu: nowe dobre oferty i obniżki cen. Gdy ustawisz tryb zbiorczy, wygrywa on z ustawieniami pojedynczych wyszukiwań. Dzwoneczek w panelu dostaje powiadomienia od razu.')
                                    ->columns(2)
                                    ->schema([
                                        ...self::group('rent', 'Wynajem'),
                                        ...self::group('sale', 'Sprzedaż'),
                                    ]),
                                Section::make('Discord')
                                    ->description('Adresy webhooków to sekrety: pole jest puste, a wpisana wartość zastępuje zapisaną. Bez osobnych webhooków wszystko idzie na główny.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('discord_username')
                                            ->label('Nazwa bota')
                                            ->columnSpanFull(),
                                        self::secret('discord_webhook_url', 'Webhook główny'),
                                        self::secret('discord_webhook_url_rent', 'Webhook dla wynajmu'),
                                        self::secret('discord_webhook_url_sale', 'Webhook dla sprzedaży'),
                                    ]),
                            ]),

                        Tab::make('AI')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->schema([
                                Section::make('Ocena ofert')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('ai_enabled')
                                            ->label('Oceniaj nowe oferty przez AI')
                                            ->helperText('Wyłączone: oferty przychodzą bez oceny i bez progu.')
                                            ->inline(false)
                                            ->columnSpanFull(),
                                        TextInput::make('ai_model')
                                            ->label('Model'),
                                        Select::make('ai_reasoning_effort')
                                            ->label('Rozumowanie modelu')
                                            ->options(['low' => 'niskie (szybko, tanio)', 'high' => 'wysokie', 'max' => 'maksymalne'])
                                            ->placeholder('domyślne modelu')
                                            ->native(false),
                                        self::int('ai_max_per_minute', 'Limit zapytań do AI', 'na minutę', 1, 200),
                                        self::int('ai_description_limit', 'Opis ogłoszenia przycięty do', 'znaków', 500, 20000),
                                    ]),
                                Section::make('Dostawca')
                                    ->description('Dostawca zgodny z API OpenAI (teraz OpenCode).')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('ai_base_url')
                                            ->label('Adres API')
                                            ->url()
                                            ->columnSpanFull(),
                                        self::secret('ai_api_key', 'Klucz API'),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Zapisz')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        AppSettings::save($this->form->getState());

        $this->form->fill(AppSettings::formValues());

        Notification::make()
            ->success()
            ->title('Zapisano')
            ->body('Workery przeładują ustawienia po bieżącym zadaniu.')
            ->send();
    }

    /**
     * @return list<Fieldset>
     */
    private static function group(string $deal, string $label): array
    {
        $schedule = fn (Get $get) => $get("notify_{$deal}_schedule");

        return [
            Fieldset::make($label)
                ->columns(1)
                ->columnSpan(1)
                ->schema([
                    Select::make("notify_{$deal}_schedule")
                        ->label('Wysyłka')
                        ->options([
                            NotifyGroups::PER_SEARCH => 'Według ustawień wyszukiwań',
                            'every_hours' => 'Jedno podsumowanie co kilka godzin',
                            'daily' => 'Jedno podsumowanie dziennie',
                        ])
                        ->native(false)
                        ->live(),
                    TextInput::make("notify_{$deal}_every_hours")
                        ->label('Co ile godzin')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(72)
                        ->suffix('h')
                        ->visible(fn (Get $get) => $schedule($get) === 'every_hours'),
                    TimePicker::make("notify_{$deal}_daily_at")
                        ->label('O której')
                        ->seconds(false)
                        ->format('H:i')
                        ->visible(fn (Get $get) => $schedule($get) === 'daily'),
                ]),
        ];
    }

    private static function locations(string $portal, string $hint, string $example): Tab
    {
        return Tab::make(Portals::label($portal))
            ->schema([
                KeyValue::make("{$portal}_locations")
                    ->hiddenLabel()
                    ->helperText("Ścieżka to {$hint}.")
                    ->keyLabel('Miejscowość (np. rudna-mala)')
                    ->valueLabel("Ścieżka (np. {$example})")
                    ->addActionLabel('Dodaj miejscowość')
                    ->reorderable(false),
            ]);
    }

    private static function int(string $name, string $label, ?string $suffix, int $min, int $max): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->suffix($suffix);
    }

    private static function secret(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->password()
            ->revealable()
            ->autocomplete('off')
            ->placeholder(fn () => AppSettings::isSecretSet($name) ? 'ustawiony (wpisz, aby zmienić)' : 'nie ustawiony')
            ->hint(fn () => AppSettings::isStored($name) ? 'z panelu' : (AppSettings::isSecretSet($name) ? 'z .env' : null))
            ->hintAction(
                Action::make("clear_{$name}")
                    ->label('Usuń z panelu')
                    ->visible(fn () => AppSettings::isStored($name))
                    ->requiresConfirmation()
                    ->action(function () use ($name) {
                        AppSettings::save([], [$name]);
                        Notification::make()->success()->title('Usunięto. Działa wartość z .env.')->send();
                    }),
            );
    }
}
