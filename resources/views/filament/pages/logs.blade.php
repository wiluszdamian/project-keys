<x-filament-panels::page>
    <style>
        .pk-logs { display: flex; flex-direction: column; gap: .5rem; }
        .pk-log { border: 1px solid var(--gray-200); border-radius: .5rem; padding: .5rem .75rem; font-size: .8125rem; }
        .dark .pk-log { border-color: rgb(255 255 255 / .1); }
        .pk-log summary { display: flex; gap: .75rem; align-items: baseline; cursor: pointer; list-style: none; }
        .pk-log summary::-webkit-details-marker { display: none; }
        .pk-log__time { color: var(--gray-500); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .pk-log__msg { overflow-wrap: anywhere; color: var(--gray-950); }
        .dark .pk-log__msg { color: var(--gray-100); }
        .pk-log pre { margin-top: .5rem; padding: .5rem; max-height: 24rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-size: .75rem; background: var(--gray-50); border-radius: .375rem; color: var(--gray-700); }
        .dark .pk-log pre { background: rgb(255 255 255 / .04); color: var(--gray-300); }
        .pk-logs__empty { color: var(--gray-500); font-size: .875rem; }
        .pk-logs__note { color: var(--gray-500); font-size: .8125rem; }
    </style>

    <x-filament::section compact>
        {{ $this->filtersForm }}
    </x-filament::section>

    <x-filament::section heading="Wpisy" description="Od najnowszego, najwyżej 200. Kliknij wpis, żeby zobaczyć szczegóły." wire:poll.30s>
        <div class="pk-logs">
            @forelse ($this->entries as $entry)
                <details class="pk-log" wire:key="log-{{ $loop->index }}-{{ md5($entry['time'].$entry['message']) }}">
                    <summary>
                        <span class="pk-log__time">{{ $entry['time'] }}</span>
                        <x-filament::badge :color="\App\Filament\Pages\Logs::levelColor($entry['level'])" size="sm">{{ $entry['level'] }}</x-filament::badge>
                        <span class="pk-log__msg">{{ \Illuminate\Support\Str::limit($entry['message'], 400) }}</span>
                    </summary>
                    @if (strlen($entry['message']) > 400 || $entry['details'] !== '')
                        <pre>{{ $entry['message'] }}@if ($entry['details'] !== '')

{{ $entry['details'] }}@endif</pre>
                    @endif
                </details>
            @empty
                <p class="pk-logs__empty">Brak wpisów dla tych filtrów.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section heading="Nieudane zadania" :description="'Zadania kolejki, które nie udały się po wszystkich próbach. Razem: '.$this->failedJobsCount.'. Pokazuję ostatnie 20, starsze niż 7 dni są kasowane.'" collapsible>
        <div class="pk-logs">
            @forelse ($this->failedJobs as $job)
                <details class="pk-log" wire:key="failed-{{ $loop->index }}">
                    <summary>
                        <span class="pk-log__time">{{ $job->failed_at }}</span>
                        <x-filament::badge color="gray" size="sm">{{ $job->queue }}</x-filament::badge>
                        <span class="pk-log__msg"><strong>{{ $job->name }}</strong>: {{ $job->message }}</span>
                    </summary>
                    <pre>{{ $job->exception }}</pre>
                </details>
            @empty
                <p class="pk-logs__empty">Brak nieudanych zadań.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
