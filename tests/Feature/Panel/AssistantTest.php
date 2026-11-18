<?php

use App\Ai\Agents\ApartmentAssistant;
use App\Ai\Tools\FindListingsTool;
use App\Ai\Tools\GetListingTool;
use App\Filament\Pages\Assistant;
use App\Jobs\AssistantReplyJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel('admin');
});

it('otwiera stronę asystenta', function () {
    $this->get('/admin/assistant')->assertOk()->assertSee('Nowa rozmowa');
});

it('wysłanie pytania wrzuca odpowiedź do kolejki i pokazuje oczekiwanie', function () {
    Queue::fake();

    Livewire::test(Assistant::class)
        ->set('message', 'Pokaż najlepsze oferty')
        ->call('send')
        ->assertSet('waiting', true)
        ->assertSet('message', '')
        ->assertSee('Szukam w bazie');

    Queue::assertPushed(AssistantReplyJob::class, fn ($job) => $job->prompt === 'Pokaż najlepsze oferty' && $job->userId === $this->user->id);
});

it('odpowiedź asystenta zapisuje rozmowę, a strona ją pokazuje', function () {
    ApartmentAssistant::fake(['Najlepsza jest **oferta A**.']);

    (new AssistantReplyJob($this->user->id, null, 'Co polecasz?'))->handle();

    Livewire::test(Assistant::class)
        ->assertSet('waiting', false)
        ->assertSee('Co polecasz?')
        ->assertSeeHtml('<strong>oferta A</strong>');
});

it('narzędzie wyszukiwania ofert filtruje i podaje linki do panelu', function () {
    $search = Search::factory()->create(['deal_type' => 'rent']);
    $good = Listing::factory()->create(['deal_type' => 'rent', 'title' => 'Projektant 3 pokoje', 'price' => 2900, 'rooms' => 3]);
    $other = Listing::factory()->create(['deal_type' => 'rent', 'title' => 'Inne', 'price' => 5000, 'rooms' => 1]);
    $search->listings()->attach([$good->id => ['matched_at' => now()], $other->id => ['matched_at' => now()]]);
    ListingAnalysis::factory()->create(['listing_id' => $good->id, 'search_id' => $search->id, 'score' => 8, 'monthly_cost' => 3470]);

    $result = json_decode((string) app(FindListingsTool::class)->handle(new Request(['max_price' => 3500, 'text' => 'Projektant'])), true);

    expect($result['total_matching'])->toBe(1)
        ->and($result['listings'][0])->toMatchArray(['id' => $good->id, 'score' => 8, 'monthly_cost_ai' => 3470])
        ->and($result['listings'][0]['panel_url'])->toEndWith("/admin/listings/{$good->id}");

    $details = json_decode((string) app(GetListingTool::class)->handle(new Request(['ids' => [$good->id]])), true);
    expect($details[0]['analyses'][0]['score'])->toBe(8);
});
