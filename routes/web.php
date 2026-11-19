<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'generations.index' : 'login');
});

// Breeze sends users here after login/registration; land them in the app.
Route::get('/dashboard', fn () => redirect()->route('generations.index'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Public, unauthenticated: Pixelforge posts events here (signature-verified).
Route::post('/webhooks/pixelforge', WebhookController::class)->name('webhooks.pixelforge');

Route::middleware('auth')->group(function () {
    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
