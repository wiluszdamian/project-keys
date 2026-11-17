<?php

use App\Scrapers\Exceptions\PortalBlockedException;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\HttpFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->fetcher = app(HttpFetcher::class);
});

it('wysyła User-Agent z konfiguracji', function () {
    config(['scrapers.user_agent' => 'TestAgent/1.0']);
    Http::fake(['*' => Http::response('ok')]);

    expect($this->fetcher->get('otodom', 'https://example.test/a'))->toBe('ok');

    Http::assertSent(fn ($request) => $request->header('User-Agent') === ['TestAgent/1.0']);
});

it('wstrzymuje portal po 403 albo 429', function (int $status) {
    Http::fake(['*' => Http::response('blocked', $status)]);

    expect(fn () => $this->fetcher->get('otodom', 'https://example.test/a'))
        ->toThrow(PortalBlockedException::class);

    expect($this->fetcher->isPaused('otodom'))->toBeTrue()
        ->and($this->fetcher->isPaused('olx'))->toBeFalse();

    // Kolejne zapytanie nie idzie do portalu.
    expect(fn () => $this->fetcher->get('otodom', 'https://example.test/b'))
        ->toThrow(PortalBlockedException::class, 'wstrzymany');

    Http::assertSentCount(1);
})->with([403, 429]);

it('wstrzymanie mija po 2 godzinach', function () {
    Http::fake(['*' => Http::sequence()->push('', 429)->push('ok')]);

    expect(fn () => $this->fetcher->get('otodom', 'https://example.test/a'))->toThrow(PortalBlockedException::class);

    $this->travel(121)->minutes();

    expect($this->fetcher->get('otodom', 'https://example.test/a'))->toBe('ok');
});

it('ponawia 2 razy przy błędzie 5xx', function () {
    Http::fake(['*' => Http::sequence()->push('', 500)->push('', 503)->push('ok')]);

    expect($this->fetcher->get('otodom', 'https://example.test/a'))->toBe('ok');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 30, 2);
});

it('poddaje się po 3 błędach 5xx', function () {
    Http::fake(['*' => Http::response('', 502)]);

    expect(fn () => $this->fetcher->get('otodom', 'https://example.test/a'))
        ->toThrow(ScraperException::class, '502');

    Http::assertSentCount(3);
    expect($this->fetcher->isPaused('otodom'))->toBeFalse();
});

it('ponawia przy timeoucie', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('timeout');
    });

    expect(fn () => $this->fetcher->get('otodom', 'https://example.test/a'))
        ->toThrow(ScraperException::class, 'timeout');

    expect($calls)->toBe(3);
});

it('robi przerwę między zapytaniami do tego samego portalu', function () {
    config(['scrapers.delay_ms' => 4000, 'scrapers.jitter_ms' => 2000]);
    Http::fake(['*' => Http::response('ok')]);

    $this->fetcher->get('otodom', 'https://example.test/a');
    Sleep::assertNeverSlept();

    $this->fetcher->get('otodom', 'https://example.test/b');
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds > 3000 && $duration->totalMilliseconds <= 6000);
});

it('błąd 404 nie wstrzymuje portalu', function () {
    Http::fake(['*' => Http::response('', 404)]);

    expect(fn () => $this->fetcher->get('otodom', 'https://example.test/a'))->toThrow(ScraperException::class, '404');
    expect($this->fetcher->isPaused('otodom'))->toBeFalse();
});
