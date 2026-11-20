<?php

namespace App\Support;

/**
 * Czyta logi Laravela z storage/logs (wspólny wolumen app, workerów i schedulera).
 * Czyta tylko koniec pliku, żeby duży log nie zapchał pamięci.
 */
class LogReader
{
    /** Poziomy od najpoważniejszego. Filtr "od poziomu" pokazuje dany i poważniejsze. */
    public const LEVELS = [
        'emergency' => 'emergency',
        'alert' => 'alert',
        'critical' => 'critical',
        'error' => 'error',
        'warning' => 'warning',
        'notice' => 'notice',
        'info' => 'info',
        'debug' => 'debug',
    ];

    private const TAIL_BYTES = 3_000_000;

    private const ENTRY_START = '/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m';

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('logs');
    }

    /**
     * Pliki logów od najnowszego: nazwa => opis z rozmiarem.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = glob($this->dir.'/*.log') ?: [];
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $list = [];

        foreach ($files as $file) {
            $list[basename($file)] = basename($file).' ('.$this->size((int) filesize($file)).')';
        }

        return $list;
    }

    /**
     * Pełna ścieżka pliku, tylko dla nazw z listy (bez ../ i innych katalogów).
     */
    public function path(?string $file): ?string
    {
        if ($file === null || ! array_key_exists($file, $this->files())) {
            return null;
        }

        return $this->dir.'/'.$file;
    }

    /**
     * Wpisy od najnowszego.
     *
     * @return list<array{time: string, level: string, message: string, details: string}>
     */
    public function entries(?string $file, ?string $minLevel = null, ?string $search = null, int $limit = 200): array
    {
        $path = $this->path($file);

        if ($path === null) {
            return [];
        }

        $allowed = $minLevel && isset(self::LEVELS[$minLevel])
            ? array_slice(array_keys(self::LEVELS), 0, array_search($minLevel, array_keys(self::LEVELS), true) + 1)
            : null;

        $entries = [];

        foreach (array_reverse(preg_split(self::ENTRY_START, $this->tail($path), -1, PREG_SPLIT_NO_EMPTY)) as $chunk) {
            if (! preg_match('/^\[(?<time>[^\]]+)\] \w+\.(?<level>\w+): (?<rest>.*)$/s', rtrim($chunk), $match)) {
                continue;
            }

            $level = strtolower($match['level']);

            if ($allowed !== null && ! in_array($level, $allowed, true)) {
                continue;
            }

            if (filled($search) && mb_stripos($match['rest'], $search) === false) {
                continue;
            }

            [$message, $details] = array_pad(explode("\n", $match['rest'], 2), 2, '');

            $entries[] = [
                'time' => $match['time'],
                'level' => $level,
                'message' => $message,
                'details' => trim($details),
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    private function tail(string $path): string
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return '';
        }

        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        // Ucięty początek pierwszego wpisu: zaczynamy od pełnego wpisu.
        if ($offset > 0 && preg_match(self::ENTRY_START, $content, $match, PREG_OFFSET_CAPTURE)) {
            $content = substr($content, (int) $match[0][1]);
        }

        return $content;
    }

    private function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
