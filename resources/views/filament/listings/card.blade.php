@php
    use App\Filament\Support\Format;
    use App\Support\Portals;

    $listing = $getRecord();
    $score = $listing->bestScore();
    $place = collect([$listing->district, $listing->street])->filter()->unique()->implode(' · ') ?: $listing->city;
    $facts = collect([
        Format::area($listing->area),
        $listing->rooms ? $listing->rooms.' pok.' : null,
        $listing->floor !== null ? 'piętro '.$listing->floor : null,
    ])->filter()->implode(' · ');
    $scoreClass = match (Format::scoreColor($score)) {
        'success' => 'pk-card__score--good',
        'warning' => 'pk-card__score--mid',
        'danger' => 'pk-card__score--bad',
        default => 'pk-card__score--none',
    };
@endphp

<div class="pk-card">
    @if ($listing->image_url)
        <img class="pk-card__img" src="{{ $listing->image_url }}" alt="" loading="lazy">
    @else
        <div class="pk-card__img pk-card__img--empty"></div>
    @endif

    <div class="pk-card__body">
        <div class="pk-card__title">{{ $listing->title }}</div>
        <div class="pk-card__muted">{{ $place }}</div>

        <div class="pk-card__price">
            @if ($score !== null)
                <span class="pk-card__score {{ $scoreClass }}">{{ $score }}/10</span>
            @endif
            <strong>{{ Format::pln($listing->price) ?? 'cena ?' }}</strong>
            @if ($fee = Format::adminFee($listing))
                <span class="pk-card__muted">{{ $fee }}</span>
            @endif
        </div>

        @if ($facts)
            <div class="pk-card__muted">{{ $facts }}</div>
        @endif

        <div class="pk-card__meta">
            @if ($listing->isGone())
                <x-filament::badge size="sm" color="danger">Zdjęta</x-filament::badge>
            @endif
            @if ($listing->deal_at)
                <x-filament::badge size="sm" color="success">Okazja</x-filament::badge>
            @endif
            @if ($listing->status)
                <x-filament::badge size="sm" :color="$listing->status->getColor()">{{ $listing->status->getLabel() }}</x-filament::badge>
            @endif
            @if ($listing->risk_flags)
                <x-filament::badge size="sm" color="danger">⚠ {{ count($listing->risk_flags) }}</x-filament::badge>
            @endif
            @foreach (Portals::sources($listing) as $portal)
                <x-filament::badge size="sm" :color="Portals::color($portal)">{{ Portals::short($portal) }}</x-filament::badge>
            @endforeach
            <span>{{ $listing->first_seen_at?->diffForHumans(short: true) }}</span>
        </div>
    </div>
</div>
