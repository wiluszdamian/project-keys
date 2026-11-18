<?php

namespace App\Filament\Pages;

use App\Jobs\AssistantReplyJob;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

/**
 * Czat z asystentem o ofertach. Odpowiedź liczy się w kolejce (AssistantReplyJob), strona odpytuje co 2 s.
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

    public bool $waiting = false;

    public ?string $pendingPrompt = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->conversationId = $this->conversationsQuery()->value('id');
        $this->restoreState();
    }

    public function send(): void
    {
        $prompt = trim($this->message);

        if ($prompt === '' || $this->waiting) {
            return;
        }

        $prompt = Str::limit($prompt, 2000, '');
        $this->message = '';
        $this->error = null;
        $this->waiting = true;
        $this->pendingPrompt = $prompt;

        Cache::put(AssistantReplyJob::stateKey(auth()->id()), ['status' => 'pending', 'conversation_id' => $this->conversationId, 'prompt' => $prompt], now()->addHour());
        AssistantReplyJob::dispatch(auth()->id(), $this->conversationId, $prompt);
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

    /**
     * @return Collection<int, object{role: string, html: string, created_at: string}>
     */
    public function getMessagesProperty(): Collection
    {
        if ($this->conversationId === null) {
            return collect();
        }

        return DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))
            ->where('conversation_id', $this->conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['role', 'content', 'created_at'])
            ->map(fn ($row) => (object) [
                'role' => $row->role,
                'html' => $row->role === 'assistant'
                    ? Str::markdown($row->content, ['html_input' => 'strip', 'allow_unsafe_links' => false])
                    : nl2br(e($row->content)),
            ]);
    }

    public function getConversationsProperty(): Collection
    {
        return $this->conversationsQuery()->limit(15)->get(['id', 'title', 'updated_at']);
    }

    private function conversationsQuery()
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
