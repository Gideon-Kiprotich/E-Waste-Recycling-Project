<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Collection\Review;
use App\Support\Demo\DemoSetupException;
use App\Support\Demo\DevelopmentConnection;
use App\Support\Demo\NairobiDataset;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class SetupNairobiDemo extends Command
{
    protected $signature = 'app:setup-nairobi-demo {administrator : Existing active Administrator UUID} {--apply : Apply only when no existing active work is affected} {--reference-evidence= : Private path to genuine downloaded public-reference PDF/PNG/JPEG; never a fabricated certificate}';

    protected $description = 'Preview or add a bounded fictional Nairobi allocation fixture; development only, no existing-record edits';

    public function handle(NairobiDataset $dataset): int
    {
        try {
            DevelopmentConnection::verify();
            $actor = User::with('membership')->find($this->argument('administrator'));
            if (! $actor) {
                throw new DemoSetupException('Existing Administrator not found. No new Administrator is created.');
            }
            $plan = DB::transaction(function () use ($dataset, $actor): array {
                DB::statement('SET TRANSACTION READ ONLY');

                return $dataset->plan($actor);
            });
            $this->preview($plan);
            if (! $this->option('apply')) {
                $this->info('Dry-run only: no writes, password prompts, files or notifications.');

                return self::SUCCESS;
            }
            if ($plan['conflicts'] || $plan['assignmentConflicts']) {
                throw new DemoSetupException('Apply refused: directory additions would change existing active work. Resolve the listed conflict separately; no existing requests are changed here.');
            }
            $evidence = null;
            if (in_array(null, $plan['organizations'], true) || in_array(null, $plan['places'], true)) {
                $path = $this->option('reference-evidence');
                if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                    throw new DemoSetupException('Supply --reference-evidence with a privately stored genuine public-source document/image. See docs/directory-data-sources.md. No evidence is generated or downloaded automatically.');
                }
                $evidence = new UploadedFile($path, basename($path), null, null, true);
                Validator::make(['evidence' => $evidence], ['evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']])->validate();
            }
            $passwords = [];
            foreach ($plan['accounts'] as $key => $id) {
                if ($id) {
                    continue;
                }
                if (! $this->input->isInteractive() || ! stream_isatty(STDIN)) {
                    throw new DemoSetupException('New demo accounts require a private interactive terminal; no password argument, shared password or non-interactive fallback is supported.');
                }
                $password = $this->secret('Private password for '.NairobiDataset::ACCOUNTS[$key]['name'].' (12–128 characters)', false);
                $confirmation = $this->secret('Confirm private password', false);
                Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)]])->validate();
                $passwords[$key] = $password;
            }
            try {
                $result = $dataset->apply($actor, $passwords, $evidence);
            } finally {
                unset($passwords, $password, $confirmation);
            }
            foreach ($result['requests'] as $key => $id) {
                $record = DB::table('requests')->where('id', $id)->firstOrFail();
                $this->line($key.' pickup: '.$record->public_code.' · '.Review::LABELS[app(Review::class)->state($record)]);
            }
            $this->info('Demo records added/reused atomically. Existing edits, passwords and work retained. No offer, acceptance, custody transition, mail or notice dispatch performed.');

            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                // Exception text can include submitted data; only approved field names leave this boundary.
                $this->error('Validation failed for '.(in_array($field, ['password', 'evidence', 'active', 'capabilities', 'decision', 'name', 'items'], true) ? $field : 'demo setup').'; no partial fixture committed.');
            }

            return self::FAILURE;
        } catch (DemoSetupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Demo setup failed; no partial fixture committed. Private exception details were withheld.');

            return self::FAILURE;
        }
    }

    private function preview(array $plan): void
    {
        $this->line('All operational records below are simulated. Real names are references only; no verified entrance, license, acceptance, operating hours or partnership is implied.');
        $this->line('Reuse existing synthetic collecting operator '.$plan['study'].' and synthetic study boundary.');
        foreach (['organizations', 'places', 'accounts', 'devices', 'requests'] as $kind) {
            $created = count(array_filter($plan[$kind], fn ($id) => $id === null));
            $this->line($kind.': create '.$created.', reuse '.(count($plan[$kind]) - $created));
            foreach ($plan[$kind] as $key => $id) {
                $label = match ($kind) {
                    'organizations' => NairobiDataset::ORGANIZATIONS[$key]['name'],
                    'places' => NairobiDataset::PLACES[$key],
                    'accounts' => NairobiDataset::ACCOUNTS[$key]['name'].' · '.NairobiDataset::ACCOUNTS[$key]['role'],
                    default => $key.' (synthetic)',
                };
                $this->line('  '.($id ? 'Reuse '.$id : 'Create').' · '.$label);
            }
        }
        foreach ($plan['conflicts'] as $id) {
            $this->warn('BLOCKED: directory save would stale request '.$id.', bump its version and append history/outbox; reservations would remain active.');
        }
        foreach ($plan['assignmentConflicts'] as $id) {
            $this->warn('BLOCKED: directory save would invalidate active assignment '.$id.'.');
        }
        $this->line('Simulated explicit capabilities: Laptop, HDD, SSD. New requests use synthetic locations, 14-day date preferences and honest unknown function/damage; all setup precedes Ready reviews.');
    }
}
