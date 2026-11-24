<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Throwable;

/**
 * Stan kopii bazy z pliku status.json, który zapisuje kontener backup (docker/backup).
 * Brak pliku albo zły JSON to "nie wiadomo", nigdy wyjątek.
 */
class BackupStatus
{
    /** @var array<string, mixed>|null */
    private ?array $data;

    public function __construct(?string $path = null)
    {
        $this->data = self::load($path ?? (string) config('backup.status_path'));
    }

    public function exists(): bool
    {
        return $this->data !== null;
    }

    public function lastOkAt(): ?CarbonImmutable
    {
        return $this->date('last_ok_at');
    }

    public function restoreTestOkAt(): ?CarbonImmutable
    {
        return $this->date('restore_test_ok_at');
    }

    public function sizeBytes(): ?int
    {
        return isset($this->data['size_bytes']) ? (int) $this->data['size_bytes'] : null;
    }

    public function size(): ?string
    {
        return $this->sizeBytes() !== null ? Number::fileSize($this->sizeBytes(), precision: 1) : null;
    }

    public function remoteEnabled(): bool
    {
        return ($this->data['remote_enabled'] ?? false) === true;
    }

    /**
     * Co jest nie tak, prostymi zdaniami. Pusta lista, gdy wszystko w porządku albo kopii tu nie robimy.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if ($this->data === null) {
            return config('backup.expected')
                ? ['Nie ma informacji o kopii bazy. Sprawdź, czy działa kontener backup (docker compose ps backup).']
                : [];
        }

        $problems = [];
        $lastOk = $this->lastOkAt();
        $lastError = $this->date('last_error_at');
        $started = $this->date('started_at');

        if ($lastError !== null && ($lastOk === null || $lastError->greaterThan($lastOk))) {
            $problems[] = 'Ostatnia kopia bazy nie wyszła: '.($this->data['last_error'] ?? 'nieznany błąd').'.';
        } elseif ($lastOk === null) {
            // Kontener dopiero ruszył: pierwsza kopia powstaje po minucie.
            if ($started === null || $started->lessThan(now()->subHours(2))) {
                $problems[] = 'Nie ma jeszcze żadnej udanej kopii bazy.';
            }
        } elseif ($lastOk->lessThan(now()->subHours((int) config('backup.max_age_hours')))) {
            $problems[] = 'Ostatnia udana kopia bazy jest z '.$lastOk->timezone(config('app.timezone'))->format('d.m.Y H:i').'.';
        }

        if (filled($this->data['restore_test_error'] ?? null)) {
            $problems[] = 'Test przywracania kopii nie wyszedł: '.$this->data['restore_test_error'].'.';
        } elseif (($tested = $this->restoreTestOkAt()) !== null && $tested->lessThan(now()->subDays((int) config('backup.verify_max_age_days')))) {
            $problems[] = 'Ostatni udany test przywracania kopii jest z '.$tested->timezone(config('app.timezone'))->format('d.m.Y').'.';
        }

        if ($this->remoteEnabled() && filled($this->data['remote_error'] ?? null)) {
            $problems[] = 'Wysyłka kopii do R2 nie wyszła: '.$this->data['remote_error'].'.';
        }

        return $problems;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function load(string $path): ?array
    {
        try {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $data = json_decode((string) file_get_contents($path), true);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
