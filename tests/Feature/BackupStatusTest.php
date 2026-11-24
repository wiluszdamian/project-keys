<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\User;
use App\Services\BackupStatus;
use Livewire\Livewire;

beforeEach(function () {
    $this->statusFile = tempnam(sys_get_temp_dir(), 'pk-backup-');
    config(['backup.status_path' => $this->statusFile, 'backup.expected' => true]);
});

afterEach(function () {
    @unlink($this->statusFile);
});

function writeBackupStatus(string $path, array $data): void
{
    file_put_contents($path, json_encode($data));
}

it('świeża kopia z udanym testem przywracania to brak problemów', function () {
    writeBackupStatus($this->statusFile, [
        'last_ok_at' => now()->subHours(3)->toIso8601String(),
        'size_bytes' => 5_400_000,
        'restore_test_ok_at' => now()->subDays(2)->toIso8601String(),
        'remote_enabled' => false,
    ]);

    $status = new BackupStatus;

    expect($status->problems())->toBe([])
        ->and($status->lastOkAt()->diffInHours(now()))->toBeLessThan(4)
        ->and($status->size())->toContain('MB');
});

it('stara kopia, błąd kopii i nieudany test przywracania to problemy', function () {
    writeBackupStatus($this->statusFile, ['last_ok_at' => now()->subHours(30)->toIso8601String()]);
    expect((new BackupStatus)->problems()[0])->toContain('Ostatnia udana kopia bazy jest z');

    writeBackupStatus($this->statusFile, [
        'last_ok_at' => now()->subHours(25)->toIso8601String(),
        'last_error' => 'pg_dump: connection refused',
        'last_error_at' => now()->subHour()->toIso8601String(),
        'restore_test_error' => 'pg_restore: błąd',
        'remote_enabled' => true,
        'remote_error' => 'rclone: 403',
    ]);

    expect((new BackupStatus)->problems())->toHaveCount(3)
        ->sequence(
            fn ($problem) => $problem->toContain('connection refused'),
            fn ($problem) => $problem->toContain('Test przywracania'),
            fn ($problem) => $problem->toContain('R2'),
        );
});

it('brak pliku stanu to problem tylko tam, gdzie kopie mają działać', function () {
    unlink($this->statusFile);

    expect((new BackupStatus)->problems())->toHaveCount(1);

    config(['backup.expected' => false]);
    expect((new BackupStatus)->problems())->toBe([]);

    file_put_contents($this->statusFile, '{zepsuty');
    expect((new BackupStatus)->exists())->toBeFalse();
});

it('kontener dopiero ruszył: brak kopii nie jest jeszcze problemem', function () {
    writeBackupStatus($this->statusFile, ['started_at' => now()->subMinutes(5)->toIso8601String()]);
    expect((new BackupStatus)->problems())->toBe([]);

    writeBackupStatus($this->statusFile, ['started_at' => now()->subHours(3)->toIso8601String()]);
    expect((new BackupStatus)->problems())->toBe(['Nie ma jeszcze żadnej udanej kopii bazy.']);
});

it('alerts:check zgłasza problem z kopią w panelu, także bez aktywnych wyszukiwań', function () {
    $user = User::factory()->create();
    writeBackupStatus($this->statusFile, ['last_ok_at' => now()->subDays(2)->toIso8601String()]);

    $this->artisan('alerts:check')->expectsOutputToContain('kopią bazy')->assertSuccessful();

    expect($user->notifications()->sole()->data['title'])->toBe('Problem z kopią bazy');
});

it('strona główna pokazuje stan kopii', function () {
    writeBackupStatus($this->statusFile, [
        'last_ok_at' => now()->subHours(2)->toIso8601String(),
        'size_bytes' => 2_000_000,
        'restore_test_ok_at' => now()->subDay()->toIso8601String(),
    ]);

    Livewire::test(StatsOverview::class)
        ->assertSee('Kopia bazy')
        ->assertSee('test przywracania');

    writeBackupStatus($this->statusFile, ['last_ok_at' => now()->subDays(3)->toIso8601String()]);

    Livewire::test(StatsOverview::class)->assertSee('Ostatnia udana kopia bazy jest z');
});
