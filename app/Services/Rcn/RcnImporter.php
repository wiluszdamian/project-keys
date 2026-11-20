<?php

namespace App\Services\Rcn;

use App\Models\RcnTransaction;
use Carbon\CarbonInterface;

/**
 * Zapisuje transakcje z RCN. Ten sam rekord (rcn_id) przy kolejnym imporcie jest nadpisywany.
 */
class RcnImporter
{
    public function __construct(private RcnClient $client) {}

    /**
     * @return list<string> TERYT powiatów z ustawień
     */
    public static function counties(): array
    {
        $value = config('rcn.counties');
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $list), fn ($teryt) => preg_match('/^\d{4}$/', $teryt)));
    }

    /**
     * @param  callable(int $saved): void|null  $progress
     * @return int liczba zapisanych transakcji
     */
    public function import(string $teryt, CarbonInterface $since, ?callable $progress = null): int
    {
        $saved = 0;

        foreach ($this->client->pages($teryt, $since) as $records) {
            $now = now();
            $rows = collect($records)
                ->keyBy('rcn_id')
                ->map(fn (array $record) => $record + ['created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all();

            RcnTransaction::upsert($rows, ['rcn_id'], array_diff(array_keys($rows[0]), ['rcn_id', 'created_at']));
            $saved += count($rows);

            if ($progress) {
                $progress($saved);
            }
        }

        return $saved;
    }
}
