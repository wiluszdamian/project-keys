<?php

namespace App\Filament\Pages;

use App\Jobs\AssistantReplyJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Czat z asystentem o ofertach. Odpowiedź liczy się w kolejce (AssistantReplyJob), strona odpytuje co 2 s.
 * Rozmowy można szukać, zmieniać im nazwę, pobierać i usuwać.
 */
class Assistant extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Asystent';

    protected static ?string $title = 'Asystent';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.assistant';

    public ?string $conversationId = null;

    public string $message = '';

    public string $filter = '';

    public bool $waiting = false;

    public ?string $pendingPrompt = null;

    public ?string $lastPrompt = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->conversationId = $this->conversationsQuery()->value('id');
        $this->restoreState();
    }

    public function send(): void
    {
        $this->ask(trim($this->message));
    }

    /**
     * Ponowienie ostatniego pytania po błędzie albo przerwaniu.
     */
    public function retry(): void
    {
        if ($this->lastPrompt) {
            $this->ask($this->lastPrompt);
        }
    }

    public function ask(string $prompt): void
    {
        if ($prompt === '' || $this->waiting) {
            return;
        }

        $prompt = Str::limit($prompt, 2000, '');
        $this->message = '';
        $this->error = null;
        $this->waiting = true;
        $this->pendingPrompt = $prompt;
        $this->lastPrompt = $prompt;

        Cache::put(AssistantReplyJob::stateKey(auth()->id()), ['status' => 'pending', 'conversation_id' => $this->conversationId, 'prompt' => $prompt], now()->addHour());
        AssistantReplyJob::dispatch(auth()->id(), $this->conversationId, $prompt);
    }

    /**
     * Przestaje czekać. Odpowiedź, która przyjdzie później, i tak zapisze się w rozmowie.
     */
    public function cancel(): void
    {
        Cache::forget(AssistantReplyJob::stateKey(auth()->id()));
        $this->waiting = false;
        $this->pendingPrompt = null;
        $this->error = 'Przerwano czekanie. Jeśli odpowiedź jeszcze przyjdzie, pojawi się w tej rozmowie po odświeżeniu.';
    }

    public function poll(): void
    {
        $this->restoreState();
    }

    public function newConversation(): void
    {
        if (! $this->waiting) {
            $this->conversationId = null;
            $this->error = null;
        }
    }

    public function openConversation(string $id): void
    {
        if (! $this->waiting && $this->conversationsQuery()->where('id', $id)->exists()) {
            $this->conversationId = $id;
            $this->error = null;
        }
    }

    public function renameConversationAction(): Action
    {
        return Action::make('renameConversation')
            ->label('Zmień nazwę')
            ->icon(Heroicon::OutlinedPencil)
            ->iconButton()
            ->size('sm')
            ->color('gray')
            ->modalHeading('Nazwa rozmowy')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Zapisz')
            ->fillForm(fn (array $arguments) => ['title' => $this->conversationsQuery()->where('id', $arguments['id'] ?? null)->value('title')])
            ->schema([
                TextInput::make('title')->label('Nazwa')->required()->maxLength(120),
            ])
            ->action(function (array $data, array $arguments) {
                $this->conversationsQuery()->where('id', $arguments['id'] ?? null)->update(['title' => trim($data['title'])]);
            });
    }

    public function deleteConversationAction(): Action
    {
        return Action::make('deleteConversation')
            ->label('Usuń')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->size('sm')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Usunąć rozmowę?')
            ->modalDescription('Rozmowa i wszystkie wiadomości znikną na stałe.')
            ->modalSubmitActionLabel('Usuń')
            ->action(function (array $arguments) {
                $this->deleteConversations([$arguments['id'] ?? '']);
                Notification::make()->success()->title('Rozmowa usunięta')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Pobierz rozmowę')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->conversationId !== null)
                ->action(fn () => $this->export()),
            Action::make('deleteAll')
                ->label('Usuń wszystkie')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->outlined()
                ->visible(fn () => $this->conversationsQuery()->exists())
                ->disabled(fn () => $this->waiting)
                ->requiresConfirmation()
                ->modalHeading('Usunąć wszystkie rozmowy?')
                ->modalDescription('Wszystkie rozmowy z asystentem znikną na stałe.')
                ->modalSubmitActionLabel('Usuń wszystkie')
                ->action(function () {
                    $count = $this->deleteConversations($this->conversationsQuery()->pluck('id')->all());
                    Notification::make()->success()->title("Usunięte rozmowy: {$count}")->send();
                }),
        ];
    }

    /**
     * @return Collection<int, object{role: string, html: string, time: string}>
     */
    public function getMessagesProperty(): Collection
    {
        if ($this->conversationId === null) {
            return collect();
        }

        return $this->messagesQuery($this->conversationId)
            ->get(['role', 'content', 'created_at'])
            ->map(fn ($row) => (object) [
                'role' => $row->role,
                'html' => $row->role === 'assistant'
                    ? Str::markdown($row->content, ['html_input' => 'strip', 'allow_unsafe_links' => false])
                    : nl2br(e($row->content)),
                'time' => Carbon::parse($row->created_at)->timezone(config('app.timezone'))->format('d.m H:i'),
            ]);
    }

    /**
     * Rozmowy z liczbą wiadomości, filtrowane po nazwie albo treści.
     */
    public function getConversationsProperty(): Collection
    {
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $filter = trim($this->filter);

        return $this->conversationsQuery()
            ->when($filter !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('title', 'ilike', "%{$filter}%")
                ->orWhereExists(fn (Builder $query) => $query->from($messages)
                    ->whereColumn("{$messages}.conversation_id", "{$conversations}.id")
                    ->where('content', 'ilike', "%{$filter}%"))))
            ->limit(100)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn ($row) => (object) [
                'id' => $row->id,
                'title' => $row->title,
                'when' => Carbon::parse($row->updated_at)->timezone(config('app.timezone'))->diffForHumans(short: true),
            ]);
    }

    public function getConversationTitleProperty(): ?string
    {
        return $this->conversationId ? $this->conversationsQuery()->where('id', $this->conversationId)->value('title') : null;
    }

    /**
     * Rozmowa jako Markdown do pobrania.
     */
    private function export(): ?StreamedResponse
    {
        $title = $this->conversationTitle;

        if ($title === null) {
            return null;
        }

        $lines = ["# {$title}", ''];

        foreach ($this->messagesQuery($this->conversationId)->get(['role', 'content', 'created_at']) as $row) {
            $who = $row->role === 'user' ? 'Ty' : 'Asystent';
            $lines[] = "## {$who}, ".Carbon::parse($row->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i');
            $lines[] = '';
            $lines[] = trim($row->content);
            $lines[] = '';
        }

        return response()->streamDownload(fn () => print (implode("\n", $lines)), 'rozmowa-'.Str::slug(Str::limit($title, 40, '')).'.md', ['Content-Type' => 'text/markdown; charset=utf-8']);
    }

    /**
     * @param  list<string>  $ids
     */
    private function deleteConversations(array $ids): int
    {
        $ids = $this->conversationsQuery()->whereIn('id', $ids)->pluck('id')->all();

        DB::transaction(function () use ($ids) {
            DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))->whereIn('conversation_id', $ids)->delete();
            DB::table(config('ai.conversations.tables.conversations', 'agent_conversations'))->whereIn('id', $ids)->delete();
        });

        if (in_array($this->conversationId, $ids, true)) {
            $this->conversationId = $this->conversationsQuery()->value('id');
        }

        return count($ids);
    }

    private function messagesQuery(string $conversationId): Builder
    {
        return DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function conversationsQuery(): Builder
    {
        $user = auth()->user();

        return DB::table(config('ai.conversations.tables.conversations', 'agent_conversations'))
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', $user->getKey())
            ->orderByDesc('updated_at');
    }

    private function restoreState(): void
    {
        $state = Cache::get(AssistantReplyJob::stateKey(auth()->id()));

        match ($state['status'] ?? null) {
            'pending' => [$this->waiting, $this->pendingPrompt] = [true, $state['prompt'] ?? null],
            'done' => [$this->waiting, $this->pendingPrompt, $this->conversationId] = [false, null, $state['conversation_id'] ?? $this->conversationId],
            'error' => [$this->waiting, $this->pendingPrompt, $this->error] = [false, null, $state['message'] ?? 'Błąd asystenta.'],
            default => $this->waiting = false,
        };

        if (in_array($state['status'] ?? null, ['done', 'error'], true)) {
            Cache::forget(AssistantReplyJob::stateKey(auth()->id()));
        }
    }
}
