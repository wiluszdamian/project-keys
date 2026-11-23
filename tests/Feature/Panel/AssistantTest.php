<?php

use App\Ai\Agents\ApartmentAssistant;
use App\Ai\Tools\FindListingsTool;
use App\Ai\Tools\GetListingTool;
use App\Ai\Tools\MortgageTool;
use App\Ai\Tools\UpdateListingTool;
use App\Filament\Pages\Assistant;
use App\Jobs\AssistantReplyJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
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

function fakeConversation(User $user, string $title, string $text = 'Pytanie'): string
{
    $id = (string) Str::uuid7();
    DB::table('agent_conversations')->insert(['id' => $id, 'participant_type' => Conversation::participantType($user), 'participant_id' => $user->id, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('agent_conversation_messages')->insert(['id' => (string) Str::uuid7(), 'conversation_id' => $id, 'participant_type' => Conversation::participantType($user), 'participant_id' => $user->id, 'agent' => ApartmentAssistant::class, 'role' => 'user', 'content' => $text, 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('usuwa rozmowę, zmienia nazwę i szuka w rozmowach', function () {
    $keep = fakeConversation($this->user, 'Okazje w Boguchwale', 'Jakie są okazje?');
    $delete = fakeConversation($this->user, 'Kredyt', 'Ile wyniesie rata?');
    $other = fakeConversation(User::factory()->create(), 'Cudza rozmowa');

    Livewire::test(Assistant::class)
        ->callAction(TestAction::make('deleteConversation')->arguments(['id' => $delete]))
        ->callAction(TestAction::make('renameConversation')->arguments(['id' => $keep]), data: ['title' => 'Okazje sprzedaż'])
        ->set('filter', 'okazje')
        ->assertSee('Okazje sprzedaż')
        ->set('filter', 'rata')
        ->assertSee('Nic nie znaleziono.');

    expect(DB::table('agent_conversations')->pluck('title')->sort()->values()->all())->toBe(['Cudza rozmowa', 'Okazje sprzedaż'])
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $delete)->count())->toBe(0);

    // Cudzej rozmowy nie da się usunąć.
    Livewire::test(Assistant::class)->callAction(TestAction::make('deleteConversation')->arguments(['id' => $other]));
    expect(DB::table('agent_conversations')->where('id', $other)->exists())->toBeTrue();
});

it('usuwa wszystkie swoje rozmowy i pobiera rozmowę jako markdown', function () {
    fakeConversation($this->user, 'Pierwsza');
    fakeConversation($this->user, 'Druga', 'Treść drugiej');

    Livewire::test(Assistant::class)
        ->assertSet('conversationId', fn ($id) => $id !== null)
        ->callAction('export')
        ->assertFileDownloaded()
        ->callAction('deleteAll');

    expect(DB::table('agent_conversations')->count())->toBe(0);
});

it('przerywa czekanie i ponawia ostatnie pytanie', function () {
    Queue::fake();

    Livewire::test(Assistant::class)
        ->set('message', 'Pokaż okazje')
        ->call('send')
        ->call('cancel')
        ->assertSet('waiting', false)
        ->assertSee('Spróbuj jeszcze raz')
        ->call('retry')
        ->assertSet('waiting', true);

    Queue::assertPushed(AssistantReplyJob::class, 2);
});

it('narzędzie kredytu liczy RKM dla oferty', function () {
    $listing = Listing::factory()->create(['deal_type' => 'sale', 'price' => 500_000, 'area' => 55, 'city' => 'Rzeszów']);

    $result = json_decode((string) app(MortgageTool::class)->handle(new Request(['listing_id' => $listing->id])), true);

    expect($result)->loan->toBe(500000)->own_contribution->toBe(0)->bgk_guarantee->toBe(100000)
        ->and($result['variants']['fixed']['installment'])->toBeGreaterThan(2500)
        ->and($result['price_limit']['ok'])->toBeTrue()
        ->and($result['page_url'])->toContain('/admin/kredyt?listing='.$listing->id);
});

it('narzędzie zmienia status oferty z powodem w notatce', function () {
    $listing = Listing::factory()->create(['note' => null]);

    $result = json_decode((string) app(UpdateListingTool::class)->handle(new Request(['listing_id' => $listing->id, 'status' => 'rejected', 'reason' => 'za daleko od pracy'])), true);

    expect($result['status'])->toBe('Odrzucona')
        ->and($listing->fresh())->status->value->toBe('rejected')->note->toBe('Odrzucona: za daleko od pracy');
});
