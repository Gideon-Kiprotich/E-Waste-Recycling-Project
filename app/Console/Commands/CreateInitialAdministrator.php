<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateInitialAdministrator extends Command
{
    protected $signature = 'app:create-initial-administrator';

    protected $description = 'Privately bootstrap the first local Administrator without changing existing accounts';

    public function handle(): int
    {
        $c = config('database.connections.'.config('database.default'));
        if (! app()->environment('local') || ($c['driver'] ?? '') !== 'pgsql' || ! in_array($c['database'], ['ewaste_development', 'ewaste_testing'], true) || $c['host'] !== '127.0.0.1' || (string) $c['port'] !== '5432' || $c['username'] !== 'ewaste_app' || ! empty($c['url'])) {
            $this->error('Initial Administrator setup requires local environment and a dedicated project PostgreSQL connection.');

            return self::FAILURE;
        }
        $identity = DB::selectOne('SELECT current_database() AS database,current_user AS role,pg_get_userbyid(datdba) AS owner,rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS elevated FROM pg_database JOIN pg_roles ON rolname=current_user WHERE datname=current_database()');
        if ($identity->database !== $c['database'] || $identity->role !== 'ewaste_app' || $identity->owner !== 'ewaste_app' || $identity->elevated) {
            $this->error('Unexpected database ownership or privileges.');

            return self::FAILURE;
        }
        if ($this->exists()) {
            $this->error('An active Administrator already exists. No account was changed.');

            return self::FAILURE;
        }
        $fields = ['display_name' => $this->ask('Administrator display name'), 'email' => strtolower(trim((string) $this->ask('Administrator email'))), 'password' => $this->secret('Private password'), 'password_confirmation' => $this->secret('Confirm private password')];
        $validation = Validator::make($fields, ['display_name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'], 'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()]]);
        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $created = DB::transaction(function () use ($fields): bool {
            Review::lock();
            if ($this->exists() || User::where('email', $fields['email'])->exists()) {
                return false;
            }
            $user = new User;
            $user->display_name = $fields['display_name'];
            $user->email = $fields['email'];
            $user->password_hash = Hash::make($fields['password']);
            $user->status = 'active';
            $user->version = 1;
            $user->session_version = 1;
            $user->save();
            $user->membership()->create(['role' => Role::Administrator, 'active' => true]);

            return true;
        });
        $created ? $this->info('Initial Administrator created. Sign in privately; no existing account was changed.') : $this->error('Setup changed concurrently. No account was created.');

        return $created ? self::SUCCESS : self::FAILURE;
    }

    private function exists(): bool
    {
        return DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('role', 'administrator')->where('active', true)->where('users.status', 'active')->exists();
    }
}
