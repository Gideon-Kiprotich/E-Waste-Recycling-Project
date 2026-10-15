<?php

use App\Support\Receipts\Notices;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('app:deliver-assignment-notices')->everyMinute()->withoutOverlapping();
Schedule::command('app:deliver-receipt-notices')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:deliver-receipt-notices {--limit=100}', function (): void {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Use a limit from 1 to 1000.');

        return;
    }
    $this->info(app(Notices::class)->deliver($limit).' supported receipt notices delivered.');
})->purpose('Deliver only supported physical-visit in-app notices; no email or unrelated outbox dispatch');
