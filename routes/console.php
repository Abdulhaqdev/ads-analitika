<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// the guard and the fast sync run in the background, so the hours-long full sync below never holds them up
Schedule::command('ads:guard')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();
// the full sync goes through every account and can take hours; today and yesterday stay fresh separately
Schedule::command('facebook:sync --days=2 --daily-only --no-alerts')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('facebook:discover')->everyThreeHours()->withoutOverlapping();
Schedule::command('facebook:sync --days=7')->everyThreeHours()->withoutOverlapping();
Schedule::command('facebook:sync --days=30')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('analytics:digest')->dailyAt('09:00');
Schedule::command('analytics:reports')->everyThreeHours()->withoutOverlapping();
Schedule::command('facebook:thumbs')->everyThreeHours()->withoutOverlapping();
