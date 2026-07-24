<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('/api/webhooks/github', GitHubWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.github');

Route::prefix('{current_team}')

    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
