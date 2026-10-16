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

Schedule::command('app:deliver-handover-notices')->everyMinute()->withoutOverlapping();
Artisan::command('app:deliver-handover-notices {--limit=100}', function (): void {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Use a limit from 1 to 1000.');

        return;
    }
    $this->info(app(App\Support\Handovers\Notices::class)->deliver($limit).' supported handover notices delivered.');
})->purpose('Deliver supported handover in-app notices only; no email or unrelated outbox dispatch');

Schedule::command('app:deliver-assessment-notices')->everyMinute()->withoutOverlapping();
Artisan::command('app:deliver-assessment-notices {--limit=100}', function (): void {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('Use a limit from 1 to 1000.');

        return;
    }
    $this->info(app(App\Support\Assessments\Notices::class)->deliver($limit).' supported assessment notices delivered.');
})->purpose('Deliver supported inspection/decision in-app notices only; no email or historical outbox draining');
