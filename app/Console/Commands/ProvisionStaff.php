<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ProvisionStaff extends Command
{
    protected $signature = 'app:provision-staff {role : collector, processor or administrator}';

    protected $description = 'Create a local staff account through private interactive prompts';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Staff bootstrap is available only in the local environment.');

            return self::FAILURE;
        }
        $role = Role::tryFrom($this->argument('role'));
        if (! $role || $role === Role::Consumer) {
            $this->error('Choose collector, processor or administrator. Consumers register through the public form.');

            return self::FAILURE;
        }

        $fields = [
            'email' => strtolower(trim((string) $this->ask('Email'))),
            'display_name' => $this->ask('Display name (optional)'),
            'password' => $this->secret('Password (12–128 characters)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($fields, [
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($fields, $role): void {
            $account = User::create([
                'email' => $fields['email'],
                'display_name' => $fields['display_name'],
                'password_hash' => $fields['password'],
            ]);
            $account->membership()->create(['role' => $role, 'active' => true]);
        });
        $this->info($role->label().' account created. No organization or facility permissions have been assigned.');

        return self::SUCCESS;
    }
}
