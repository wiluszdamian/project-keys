<?php

use Illuminate\Support\Facades\Redis;

it('zwraca ok, gdy baza i redis działają', function () {
    Redis::shouldReceive('connection->ping')->andReturn(true);

    $this->getJson('/api/health')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => true, 'redis' => true]]);
});

it('zwraca 503, gdy redis nie działa', function () {
    Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('brak połączenia'));

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'error', 'checks' => ['database' => true, 'redis' => false]]);
});
