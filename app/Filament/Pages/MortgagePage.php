<?php

namespace App\Filament\Pages;

use App\Models\Listing;
use App\Models\RcnTransaction;
use App\Services\Mortgage\NbpRates;
use App\Services\Mortgage\RkmSimulator;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Symulacja Rodzinnego Kredytu Mieszkaniowego (bez wkładu własnego, gwarancja BGK).
 * Z oferty (?listing=ID) bierze cenę, metraż, miejscowość i rynek.
 */
class MortgagePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Kredyt';

    protected static ?string $title = 'Kredyt (Rodzinny Kredyt Mieszkaniowy)';

    protected static ?string $slug = 'kredyt';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.mortgage';

    #[Url]
    public ?int $listing = null;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(RkmSimulator::defaults($this->getListingRecord()));
    }

    public function getListingRecord(): ?Listing
    {
        return $this->listing ? Listing::find($this->listing) : null;
    }

    public function form(Schema $schema): Schema
    {
        $money = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->suffix('zł')
            ->live(onBlur: true);
        $percent = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(30)
            ->step(0.01)
            ->suffix('%')
            ->live(onBlur: true);

        return $schema
            ->statePath('data')
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Mieszkanie')
                    ->columns(['default' => 2])
                    ->columnSpan(1)
                    ->schema([
                        $money('price', 'Cena')->required()->columnSpanFull(),
                        TextInput::make('area')
                            ->label('Metraż')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('m²')
                            ->helperText('Do limitu ceny za m².')
                            ->live(onBlur: true),
                        Select::make('market')
                            ->label('Rynek')
                            ->options([RcnTransaction::SECONDARY => 'wtórny', RcnTransaction::PRIMARY => 'od dewelopera'])
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),
                        Select::make('zone')
                            ->label('Gdzie')
                            ->options(collect(config('mortgage.rkm.limits'))->map(fn (array $zone) => ucfirst($zone['label']))->all())
                            ->helperText('Boguchwała, Tajęcina i Rudna Mała to gminy sąsiadujące.')
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Section::make('Kredyt')
                    ->columns(['default' => 2])
                    ->columnSpan(1)
                    ->schema([
                        $money('own', 'Wkład własny')
                            ->helperText(fn (Get $get) => 'Najmniej '.RkmSimulator::money(RkmSimulator::requiredOwn((float) $get('price'))).' przy tej cenie.')
                            ->columnSpanFull(),
                        TextInput::make('years')
                            ->label('Na ile lat')
                            ->integer()
                            ->minValue(config('mortgage.rkm.min_years'))
                            ->maxValue(config('mortgage.rkm.max_years'))
                            ->live(onBlur: true),
                        Select::make('rate_type')
                            ->label('Oprocentowanie')
                            ->options([RkmSimulator::FIXED => 'stałe 5 lat', RkmSimulator::VARIABLE => 'zmienne'])
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),
                        $percent('fixed_rate', 'Stałe przez 5 lat')
                            ->visible(fn (Get $get) => $get('rate_type') === RkmSimulator::FIXED)
                            ->columnSpanFull(),
                        $percent('index_rate', config('mortgage.index_name')),
                        $percent('margin', 'Marża'),
                        $percent('commission', 'Prowizja'),
                        Toggle::make('bridge')
                            ->label('Ubezpieczenie pomostowe')
                            ->helperText('+'.config('mortgage.bridge_extra').' pp przez '.config('mortgage.bridge_months').' mies., do wpisu hipoteki.')
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Section::make('Spłata rodzinna z BGK')
                    ->description('BGK spłaca część kredytu, gdy po podpisaniu umowy urodzi się drugie dziecko ('.RkmSimulator::money(config('mortgage.rkm.family_repayment_second')).') albo trzecie i kolejne ('.RkmSimulator::money(config('mortgage.rkm.family_repayment_third')).'). Wniosek do roku od urodzenia.')
                    ->columns(['default' => 2])
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('second_child_year')
                            ->label('2. dziecko w roku kredytu')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(35)
                            ->placeholder('nie liczę')
                            ->live(onBlur: true),
                        TextInput::make('third_child_year')
                            ->label('3. dziecko w roku kredytu')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(35)
                            ->placeholder('nie liczę')
                            ->live(onBlur: true),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getResultProperty(): array
    {
        return app(RkmSimulator::class)->simulate($this->data ?? []);
    }

    /**
     * Ostrzeżenie, gdy NBP zmienił stopy po dniu, z którego są stawki.
     */
    public function getRatesNoticeProperty(): ?string
    {
        $nbp = app(NbpRates::class)->reference();
        $date = config('mortgage.rates_date');

        if ($nbp === null) {
            return "Stawki z dnia {$date}. Nie udało się sprawdzić stopy NBP.";
        }

        $before = (float) config('mortgage.nbp_rate_at_date');

        if (abs($nbp['rate'] - $before) < 0.001) {
            return null;
        }

        $diff = $nbp['rate'] - $before;
        $suggested = round((float) config('mortgage.index_rate') + $diff, 2);

        return 'NBP zmienił stopę referencyjną z '.number_format($before, 2, ',', '').'% na '.number_format($nbp['rate'], 2, ',', '')."% (od {$nbp['since']}), a stawki są z {$date}. "
            .config('mortgage.index_name').' pewnie wynosi teraz około '.number_format($suggested, 2, ',', '').'%. Zaktualizuj stawki w Ustawieniach (zakładka Kredyt).';
    }
}
