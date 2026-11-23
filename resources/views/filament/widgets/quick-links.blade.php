<x-filament-widgets::widget>
    <style>
        .pk-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
        @media (min-width: 768px) { .pk-links { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .pk-link { display: flex; gap: .75rem; align-items: center; padding: .875rem 1rem; border-radius: .75rem; background: white; box-shadow: 0 1px 2px rgb(0 0 0 / .05); border: 1px solid var(--gray-200); transition: border-color .15s; min-width: 0; }
        .pk-link:hover { border-color: var(--primary-500); }
        .dark .pk-link { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .dark .pk-link:hover { border-color: var(--primary-500); }
        .pk-link__icon { flex: none; width: 2.25rem; height: 2.25rem; border-radius: .5rem; display: grid; place-items: center; background: var(--gray-100); color: var(--gray-600); }
        .dark .pk-link__icon { background: rgb(255 255 255 / .06); color: var(--gray-300); }
        .pk-link__icon--primary { background: var(--primary-50); color: var(--primary-600); }
        .pk-link__icon--success { background: var(--success-50); color: var(--success-600); }
        .pk-link__icon--warning { background: var(--warning-50); color: var(--warning-600); }
        .dark .pk-link__icon--primary, .dark .pk-link__icon--success, .dark .pk-link__icon--warning { background: rgb(255 255 255 / .06); }
        .pk-link__text { min-width: 0; flex: 1; }
        .pk-link__label { font-weight: 600; font-size: .9375rem; color: var(--gray-950); } .dark .pk-link__label { color: white; }
        .pk-link__desc { font-size: .75rem; color: var(--gray-500); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pk-link__count { font-weight: 700; font-size: 1.25rem; color: var(--gray-950); font-variant-numeric: tabular-nums; } .dark .pk-link__count { color: white; }
    </style>

    <div class="pk-links">
        @foreach ($this->getLinks() as $link)
            <a href="{{ $link['url'] }}" class="pk-link">
                <span @class(['pk-link__icon', 'pk-link__icon--'.$link['color'] => $link['color'] !== 'gray'])>
                    <x-filament::icon :icon="$link['icon']" class="h-5 w-5" />
                </span>
                <span class="pk-link__text">
                    <span class="pk-link__label">{{ $link['label'] }}</span>
                    <span class="pk-link__desc" style="display:block">{{ $link['description'] }}</span>
                </span>
                @if ($link['count'] !== null)
                    <span class="pk-link__count">{{ $link['count'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
</x-filament-widgets::widget>
