<?php

use App\Support\LogReader;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/pk-logs-'.uniqid();
    mkdir($this->dir);
    file_put_contents($this->dir.'/laravel-2026-11-20.log', implode("\n", [
        '[2026-11-20 10:00:00] production.INFO: Przebieg otodom ok',
        '[2026-11-20 10:05:00] production.ERROR: Portal olx odpowiedział 403 {"exception":"[object] (RuntimeException(code: 0): 403"}',
        '[stacktrace]',
        '#0 /var/www/html/app/Jobs/ScrapePortalJob.php(81): fetch()',
        '[2026-11-20 10:10:00] production.WARNING: Alert: Ocena AI często się nie udaje.',
        '',
    ]));
    $this->reader = new LogReader($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('czyta wpisy od najnowszego razem ze stack trace', function () {
    $entries = $this->reader->entries('laravel-2026-11-20.log');

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[1]['level'])->toBe('error')
        ->and($entries[1]['message'])->toContain('403')
        ->and($entries[1]['details'])->toContain('ScrapePortalJob.php');
});

it('filtruje od poziomu i po tekście', function () {
    expect($this->reader->entries('laravel-2026-11-20.log', 'warning'))->toHaveCount(2)
        ->and($this->reader->entries('laravel-2026-11-20.log', 'error'))->toHaveCount(1)
        ->and($this->reader->entries('laravel-2026-11-20.log', null, 'otodom'))->toHaveCount(1);
});

it('czyta tylko pliki z katalogu logów', function () {
    expect($this->reader->files())->toHaveKey('laravel-2026-11-20.log')
        ->and($this->reader->path('../.env'))->toBeNull()
        ->and($this->reader->entries('../../.env'))->toBe([]);
});
