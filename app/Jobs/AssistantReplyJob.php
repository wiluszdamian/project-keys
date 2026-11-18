<?php

namespace App\Jobs;

use App\Ai\Agents\ApartmentAssistant;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Odpowiedź asystenta w tle. Odpowiedź z narzędziami trwa nieraz dłużej niż limit Cloudflare (100 s),
 * więc strona czatu tylko sprawdza stan w cache.
 */
class AssistantReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $userId, public ?string $conversationId, public string $prompt)
    {
        $this->onQueue('ai');
    }

    public static function stateKey(int $userId): string
    {
        return "assistant:{$userId}:state";
    }

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        try {
            $response = (new ApartmentAssistant)
                ->continueOrStart($this->conversationId, $user)
                ->prompt($this->prompt);

            Cache::put(self::stateKey($this->userId), ['status' => 'done', 'conversation_id' => $response->conversationId ?? $this->conversationId], now()->addHour());
        } catch (Throwable $exception) {
            Log::warning('Asystent: błąd odpowiedzi', ['error' => $exception->getMessage()]);
            Cache::put(self::stateKey($this->userId), ['status' => 'error', 'conversation_id' => $this->conversationId, 'message' => 'Asystent nie odpowiedział (błąd modelu albo limit czasu). Spróbuj jeszcze raz.'], now()->addHour());
        }
    }
}
