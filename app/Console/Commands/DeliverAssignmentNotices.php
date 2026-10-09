<?php

namespace App\Console\Commands;

use App\Support\Assignments\Notices;
use Illuminate\Console\Command;

class DeliverAssignmentNotices extends Command
{
    protected $signature = 'app:deliver-assignment-notices {--limit=100}';

    protected $description = 'Deliver only supported assignment notices in-app; no historical notices or email';

    public function handle(Notices $notices): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Choose a limit from 1 to 1000.');

            return self::FAILURE;
        }
        $this->info($notices->deliver($limit).' assignment notices delivered in-app.');

        return self::SUCCESS;
    }
}
