<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bezpiecznik: RefreshDatabase czyści bazę, więc testy mogą działać tylko na bazie testowej.
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'mieszkania_test') {
            throw new RuntimeException("Testy muszą używać bazy mieszkania_test, a używają: {$database}");
        }

        // Żadnych prawdziwych zapytań do portali ani Discorda. Każde zapytanie musi być w Http::fake.
        Http::preventStrayRequests();
    }
}
