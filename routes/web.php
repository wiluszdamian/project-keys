<?php

use Illuminate\Support\Facades\Route;

// Panel Filament jest pod /admin, API w routes/api.php.
Route::redirect('/', '/admin');
