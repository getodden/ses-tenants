<?php

use Illuminate\Support\Facades\Route;
use Odden\SesTenants\Http\SesEventsController;

Route::post((string) config('ses-tenants.events.path'), SesEventsController::class)
    ->middleware((array) config('ses-tenants.events.middleware', []))
    ->name('ses-tenants.events');
