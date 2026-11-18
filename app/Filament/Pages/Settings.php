<?php

namespace App\Filament\Pages;

use App\Support\AppSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                                Section::make('Lokalizacje Otodom')
                                    ->description('Otodom wymaga ścieżki lokalizacji dla każdej miejscowości. Otwórz wyniki dla miejscowości na otodom.pl i skopiuj część adresu po /mieszkanie/. Nazwa miejscowości bez polskich znaków, małymi literami, spacje jako myślniki (np. rudna-mala). OLX nie potrzebuje tej listy.')
                                    ->schema([
                                        KeyValue::make('otodom_locations')
                                            ->hiddenLabel()
                                            ->keyLabel('Miejscowość (np. rudna-mala)')
                                            ->valueLabel('Ścieżka (np. podkarpackie/rzeszowski/glogow-malopolski/rudna-mala)')
                                            ->addActionLabel('Dodaj miejscowość')
                                            ->reorderable(false),
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
