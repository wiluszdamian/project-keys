<?php

use App\Enums\ListingStatus;
use App\Enums\NotifyMode;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Searches\Pages\CreateSearch;
use App\Filament\Resources\Searches\Pages\EditSearch;
use App\Filament\Resources\Searches\Pages\ListSearches;
use App\Jobs\RunSearchJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ListingPriceChange;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
});

it('wpuszcza do panelu tylko po zalogowaniu', function () {
    auth()->logout();

    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/')->assertRedirect('/admin');
});

it('otwiera wszystkie strony panelu', function () {
    $search = Search::factory()->create();
    $listing = Listing::factory()->create();
    $search->listings()->attach($listing, ['matched_at' => now()]);
    ListingAnalysis::factory()->create(['listing_id' => $listing->id, 'search_id' => $search->id, 'score' => 8]);
    ListingPriceChange::create(['listing_id' => $listing->id, 'old_price' => 3000, 'new_price' => 2800, 'changed_at' => now()]);
    ScrapeRun::factory()->for($search)->create();

    foreach ([
        '/admin',
        '/admin/searches',
        '/admin/searches/create',
        "/admin/searches/{$search->id}/edit",
        '/admin/listings',
        "/admin/listings/{$listing->id}",
        "/admin/listings/{$listing->id}/edit",
        '/admin/scrape-runs',
        '/admin/settings',
    ] as $url) {
        $this->get($url)->assertOk();
    }

    $this->get("/admin/listings/{$listing->id}")->assertSee($listing->title)->assertSee('8/10');
});

it('tworzy wyszukiwanie z formularza', function () {
    Livewire::test(CreateSearch::class)
        ->fillForm([
            'name' => 'Rzeszów wynajem',
            'deal_type' => 'rent',
            'property_type' => 'flat',
            'city' => 'Rzeszów',
            'districts' => ['Projektant'],
            'price_max' => 3500,
            'rooms_min' => 3,
            'portals' => ['otodom', 'olx'],
            'notify_channels' => ['discord'],
            'min_score_to_notify' => 6,
            'ai_preferences' => 'osiedle Projektant',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Search::sole())
        ->city->toBe('Rzeszów')
        ->price_max->toBe(3500)
        ->portals->toBe(['otodom', 'olx'])
        ->districts->toBe(['Projektant']);
});

it('odrzuca miejscowość bez lokalizacji Otodom i odwrócony zakres', function () {
    Livewire::test(CreateSearch::class)
        ->fillForm([
            'name' => 'Nieznane',
            'deal_type' => 'sale',
            'property_type' => 'flat',
            'city' => 'Pcim Dolny',
            'price_min' => 500000,
            'price_max' => 300000,
            'portals' => ['otodom'],
            'notify_channels' => ['discord'],
            'min_score_to_notify' => 6,
        ])
        ->call('create')
        ->assertHasFormErrors(['city', 'price_max']);

    expect(Search::count())->toBe(0);
});

it('bez Otodom miejscowość nie musi mieć lokalizacji', function () {
    Livewire::test(CreateSearch::class)
        ->fillForm([
            'name' => 'Pcim OLX',
            'deal_type' => 'sale',
            'property_type' => 'flat',
            'city' => 'Pcim Dolny',
            'portals' => ['olx'],
            'notify_channels' => ['discord'],
            'min_score_to_notify' => 6,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('edytuje wyszukiwanie i uruchamia je od razu', function () {
    Queue::fake();
    $search = Search::factory()->create(['city' => 'Kraków']);

    Livewire::test(EditSearch::class, ['record' => $search->getRouteKey()])
        ->fillForm(['price_max' => 4000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($search->fresh()->price_max)->toBe(4000);

    Livewire::test(ListSearches::class)->callTableAction('run', $search);
    Queue::assertPushed(RunSearchJob::class, fn ($job) => $job->search->is($search));
});

it('ustawia status oferty i domyślnie ukrywa odrzucone oraz duplikaty', function () {
    [$keep, $reject] = Listing::factory()->count(2)->create();
    $duplicate = Listing::factory()->create(['duplicate_of_id' => $keep->id]);

    Livewire::test(ListListings::class)->removeTableFilter('above_threshold')->callTableAction('status_rejected', $reject);
    expect($reject->fresh()->status)->toBe(ListingStatus::Rejected);

    Livewire::test(ListListings::class)
        ->removeTableFilter('above_threshold')
        ->assertCanSeeTableRecords([$keep])
        ->assertCanNotSeeTableRecords([$reject, $duplicate]);

    Livewire::test(EditListing::class, ['record' => $keep->getRouteKey()])
        ->fillForm(['status' => 'viewing', 'note' => 'sobota 12:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($keep->fresh())->status->toBe(ListingStatus::Viewing)->note->toBe('sobota 12:00');
});

it('domyślnie pokazuje tylko oferty z oceną od progu wyszukiwania', function () {
    $search = Search::factory()->create(['min_score_to_notify' => 6]);
    [$good, $weak, $unscored] = Listing::factory()->count(3)->create();
    ListingAnalysis::factory()->create(['listing_id' => $good->id, 'search_id' => $search->id, 'score' => 7]);
    ListingAnalysis::factory()->create(['listing_id' => $weak->id, 'search_id' => $search->id, 'score' => 4]);

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords([$good])
        ->assertCanNotSeeTableRecords([$weak, $unscored])
        ->removeTableFilter('above_threshold')
        ->assertCanSeeTableRecords([$good, $weak, $unscored]);
});

it('zapisuje tryb powiadomień z formularza', function () {
    $search = Search::factory()->create(['notify_channels' => ['discord']]);

    Livewire::test(EditSearch::class, ['record' => $search->getRouteKey()])
        ->fillForm(['notify_mode' => 'daily', 'notify_daily_at' => '19:30'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($search->fresh())->notify_mode->toBe(NotifyMode::Daily)->notify_daily_at->toBe('19:30');

    Livewire::test(EditSearch::class, ['record' => $search->getRouteKey()])
        ->fillForm(['notify_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($search->fresh())->notify_enabled->toBeFalse()->notify_channels->toBe(['discord']);
});

it('otwiera mapę i miejsca dojazdu', function () {
    $listing = Listing::factory()->create(['lat' => 50.03, 'lng' => 22.01]);

    $this->get('/admin/map')->assertOk()->assertSee('leaflet');
    $this->get('/admin/commute-places')->assertOk()->assertSee('Dodaj miejsce');
    $this->get("/admin/listings/{$listing->id}")->assertOk();
});
