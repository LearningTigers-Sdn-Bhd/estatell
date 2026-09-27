<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'admin/dashboard')->name('dashboard');
});
