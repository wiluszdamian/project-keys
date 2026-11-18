<x-filament-panels::page>
    <style>
        .pk-chat { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 1024px) { .pk-chat { grid-template-columns: 15rem 1fr; } }
        .pk-chat__list { display: flex; flex-direction: column; gap: .25rem; }
        .pk-chat__conv { text-align: left; padding: .5rem .75rem; border-radius: .5rem; font-size: .875rem; color: var(--gray-700); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pk-chat__conv:hover { background: var(--gray-100); }
        .pk-chat__conv--active { background: var(--primary-50); color: var(--primary-700); font-weight: 600; }
        .dark .pk-chat__conv { color: var(--gray-300); }
        .dark .pk-chat__conv:hover { background: rgb(255 255 255 / .05); }
        .dark .pk-chat__conv--active { background: rgb(255 255 255 / .08); color: var(--primary-400); }
        .pk-chat__main { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
        .pk-chat__messages { display: flex; flex-direction: column; gap: .75rem; }
        .pk-msg { max-width: 48rem; padding: .75rem 1rem; border-radius: .75rem; font-size: .9375rem; line-height: 1.55; overflow-wrap: anywhere; }
        .pk-msg--user { align-self: flex-end; background: var(--primary-600); color: white; }
        .pk-msg--assistant { align-self: flex-start; background: var(--gray-100); color: var(--gray-950); }
        .dark .pk-msg--assistant { background: rgb(255 255 255 / .06); color: var(--gray-100); }
        .pk-msg p { margin: 0 0 .5rem; } .pk-msg p:last-child { margin-bottom: 0; }
        .pk-msg ul, .pk-msg ol { margin: .25rem 0 .5rem 1.25rem; } .pk-msg ul { list-style: disc; } .pk-msg ol { list-style: decimal; }
        .pk-msg li { margin: .15rem 0; }
        .pk-msg a { color: var(--primary-600); text-decoration: underline; } .dark .pk-msg a { color: var(--primary-400); }
        .pk-msg strong { font-weight: 600; }
        .pk-msg table { border-collapse: collapse; margin: .5rem 0; font-size: .875rem; } .pk-msg th, .pk-msg td { border: 1px solid var(--gray-300); padding: .25rem .5rem; text-align: left; }
        .pk-msg--pending { opacity: .7; font-style: italic; }
        .pk-chat__empty { color: var(--gray-500); font-size: .9375rem; }
        .pk-chat__examples { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
        .pk-chat__example { padding: .375rem .75rem; border-radius: 999px; border: 1px solid var(--gray-300); font-size: .8125rem; color: var(--gray-700); }
        .pk-chat__example:hover { border-color: var(--primary-500); color: var(--primary-600); }
        .dark .pk-chat__example { border-color: rgb(255 255 255 / .15); color: var(--gray-300); }
        .pk-chat__form { display: flex; gap: .5rem; align-items: flex-end; }
        .pk-chat__input { flex: 1; min-height: 3rem; max-height: 12rem; resize: vertical; padding: .625rem .75rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: white; color: var(--gray-950); font-size: .9375rem; }
        .dark .pk-chat__input { background: rgb(255 255 255 / .05); border-color: rgb(255 255 255 / .15); color: white; }
        .pk-chat__error { color: var(--danger-600); font-size: .875rem; }
    </style>

    <div class="pk-chat">
        <x-filament::section compact>
            <div class="pk-chat__list">
                <x-filament::button wire:click="newConversation" icon="heroicon-o-plus" size="sm" :disabled="$waiting">
                    Nowa rozmowa
                </x-filament::button>

                @foreach ($this->conversations as $conversation)
                    <button type="button"
                        wire:click="openConversation('{{ $conversation->id }}')"
                        title="{{ $conversation->title }}"
                        @class(['pk-chat__conv', 'pk-chat__conv--active' => $conversation->id === $conversationId])>
                        {{ $conversation->title }}
                    </button>
                @endforeach
            </div>
        </x-filament::section>

        <div class="pk-chat__main">
            <x-filament::section>
                <div class="pk-chat__messages">
                    @forelse ($this->messages as $message)
                        <div @class(['pk-msg', 'pk-msg--user' => $message->role === 'user', 'pk-msg--assistant' => $message->role !== 'user'])>
                            {!! $message->html !!}
                        </div>
                    @empty
                        @unless ($waiting)
                            <div class="pk-chat__empty">
                                Zapytaj o oferty z bazy. Asystent widzi twoje wyszukiwania, oferty, oceny AI, historię cen i ceny w okolicy.
                                <div class="pk-chat__examples">
                                    @foreach ([
                                        'Pokaż 5 najlepszych ofert wynajmu z ostatniego tygodnia',
                                        'Które mieszkania na Projektancie mają najniższy koszt miesięczny?',
                                        'Porównaj najlepsze oferty sprzedaży do 560 tys.',
                                        'Czy ceny w Boguchwale są niższe niż w Rzeszowie?',
                                    ] as $example)
                                        <button type="button" class="pk-chat__example" wire:click="$set('message', @js($example))">{{ $example }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endunless
                    @endforelse

                    @if ($waiting)
                        @if ($pendingPrompt && $this->messages->last()?->role !== 'user')
                            <div class="pk-msg pk-msg--user">{!! nl2br(e($pendingPrompt)) !!}</div>
                        @endif
                        <div class="pk-msg pk-msg--assistant pk-msg--pending" wire:poll.2s="poll">
                            Szukam w bazie i myślę nad odpowiedzią…
                        </div>
                    @endif

                    @if ($error)
                        <div class="pk-chat__error">{{ $error }}</div>
                    @endif
                </div>
            </x-filament::section>

            <form wire:submit="send" class="pk-chat__form">
                <textarea
                    wire:model="message"
                    class="pk-chat__input"
                    rows="2"
                    maxlength="2000"
                    placeholder="Napisz pytanie… (Enter wysyła, Shift+Enter nowa linia)"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"
                    @disabled($waiting)
                ></textarea>
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" :disabled="$waiting">
                    Wyślij
                </x-filament::button>
            </form>
        </div>
    </div>
</x-filament-panels::page>
