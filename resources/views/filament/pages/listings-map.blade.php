<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    @endassets

    <style>
        .pk-map { height: calc(100vh - 19rem); min-height: 28rem; border-radius: .75rem; overflow: hidden; z-index: 0; }
        .pk-map-legend { display: flex; flex-wrap: wrap; gap: 1rem; font-size: .8125rem; color: var(--gray-600); }
        .dark .pk-map-legend { color: var(--gray-400); }
        .pk-map-dot { display: inline-block; width: .75rem; height: .75rem; border-radius: 999px; margin-right: .35rem; vertical-align: -1px; }
        .pk-popup { font-size: .8125rem; line-height: 1.4; max-width: 15rem; }
        .pk-popup__title { font-weight: 600; margin-bottom: .2rem; }
        .pk-popup__risk { color: #dc2626; }
    </style>

    <x-filament::section compact>
        {{ $this->filtersForm }}
    </x-filament::section>

    <div
        wire:ignore
        x-data="{
            map: null,
            layer: null,
            first: true,
            color(score) { return score === null ? '#9ca3af' : (score >= 8 ? '#16a34a' : (score >= 6 ? '#f59e0b' : '#dc2626')) },
            popup(item) {
                const box = document.createElement('div'); box.className = 'pk-popup';
                const title = document.createElement('a'); title.className = 'pk-popup__title'; title.href = item.url; title.textContent = item.title; title.style.display = 'block';
                const price = document.createElement('div'); price.textContent = item.deal + ': ' + item.price + (item.score !== null ? ' · ocena ' + item.score + '/10' : '');
                const meta = document.createElement('div'); meta.textContent = item.meta + (item.approx ? ' · położenie przybliżone' : '');
                box.append(title, price, meta);
                if (item.risk) { const risk = document.createElement('div'); risk.className = 'pk-popup__risk'; risk.textContent = '⚠ Sygnały ryzyka, sprawdź ofertę'; box.append(risk); }
                return box;
            },
            render(data) {
                if (! this.map) return;
                this.layer.clearLayers();
                const bounds = [];
                data.listings.forEach(item => {
                    L.circleMarker([item.lat, item.lng], {
                        radius: item.approx ? 6 : 8, color: item.risk ? '#7f1d1d' : '#ffffff', weight: item.risk ? 3 : 1.5,
                        dashArray: item.approx ? '3' : null, fillColor: this.color(item.score), fillOpacity: item.approx ? 0.55 : 0.9,
                    }).bindPopup(this.popup(item)).addTo(this.layer);
                    bounds.push([item.lat, item.lng]);
                });
                data.places.forEach(place => {
                    L.circleMarker([place.lat, place.lng], { radius: 10, color: '#1e3a8a', weight: 2, fillColor: '#3b82f6', fillOpacity: 1 })
                        .bindTooltip(place.name, { permanent: true, direction: 'top', offset: [0, -8] }).addTo(this.layer);
                    bounds.push([place.lat, place.lng]);
                });
                this.$refs.count.textContent = data.limited ? `Pokazuję ${data.listings.length} z ${data.total} ofert (najlepiej ocenione).` : `Ofert na mapie: ${data.total}.`;
                if (bounds.length && this.first) { this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 }); this.first = false; }
            },
            init() {
                this.map = L.map(this.$refs.map).setView([50.0413, 21.999], 12);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(this.map);
                this.layer = L.layerGroup().addTo(this.map);
                this.render(@js($this->mapData()));
            },
        }"
        x-on:map-data.window="render($event.detail.data)"
        class="space-y-2"
    >
        <div x-ref="map" class="pk-map"></div>
        <div class="pk-map-legend">
            <span><span class="pk-map-dot" style="background:#16a34a"></span>ocena 8 do 10</span>
            <span><span class="pk-map-dot" style="background:#f59e0b"></span>6 do 7</span>
            <span><span class="pk-map-dot" style="background:#dc2626"></span>poniżej 6</span>
            <span><span class="pk-map-dot" style="background:#9ca3af"></span>bez oceny</span>
            <span><span class="pk-map-dot" style="background:#3b82f6"></span>miejsca dojazdu</span>
            <span>Przerywana obwódka: położenie przybliżone. Ciemnoczerwona: sygnały ryzyka.</span>
            <span x-ref="count"></span>
        </div>
    </div>
</x-filament-panels::page>
