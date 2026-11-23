<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Geo\Infrastructure;
use App\Services\Geo\PoiImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('pois:import {--file= : Plik GeoJSON z osmium (geojsonseq), "-" to wejście standardowe. Bez tego Overpass API} {--skip-listings : Bez przeliczania infrastruktury ofert}')]
#[Description('Pobiera z OpenStreetMap sklepy, żłobki, przedszkola i szkoły wokół wyszukiwań i przelicza infrastrukturę ofert.')]
class PoisImport extends Command
{
    public function handle(PoiImporter $importer, Infrastructure $infrastructure): int
    {
        try {
            $file = $this->option('file');
            $result = match (true) {
                $file === '-' => $importer->importGeoJsonSeq(STDIN),
                filled($file) => $importer->importGeoJsonSeq(fopen($file, 'r')),
                default => $importer->import(),
            };
        } catch (Throwable $exception) {
            $this->error('Import punktów nie wyszedł: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Punkty: {$result['total']} ".json_encode($result['by_category'], JSON_UNESCAPED_UNICODE));

        if (! $this->option('skip-listings')) {
            $count = 0;
            Listing::query()
                ->whereNull('duplicate_of_id')
                ->whereNotNull('lat')
                ->whereHas('searches', fn (Builder $query) => $query->where('is_active', true))
                ->chunkById(100, function ($listings) use ($infrastructure, &$count) {
                    foreach ($listings as $listing) {
                        $count += (int) $infrastructure->refresh($listing);
                    }
                });

            $this->info("Infrastruktura przeliczona dla ofert: {$count}.");
        }

        return self::SUCCESS;
    }
}
