<?php

return [
    // Stan kopii bazy zapisywany przez kontener backup (wolumen backups, w app tylko do odczytu).
    'status_path' => env('BACKUP_STATUS_PATH', '/backups/status.json'),

    // Czy na tym serwerze działa kontener backup. Gdy tak, brak pliku stanu to alert.
    'expected' => (bool) env('BACKUP_EXPECTED', true),

    // Alert, gdy ostatnia udana kopia jest starsza (kopia idzie raz na dobę).
    'max_age_hours' => 26,

    // Alert, gdy ostatni udany test przywracania jest starszy (test idzie raz w tygodniu).
    'verify_max_age_days' => 9,
];
