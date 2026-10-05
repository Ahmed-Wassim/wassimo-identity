<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh rows are the only Sanctum rows now (access is a JWT). Prune the
// expired ones daily or personal_access_tokens grows forever.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
