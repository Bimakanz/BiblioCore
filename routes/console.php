<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the expensive cold paths (home sections, top titles' streams,
// manga catalog) warm so real users almost always hit cache.
Schedule::command('cache:prewarm')->everyThirtyMinutes()->withoutOverlapping();
