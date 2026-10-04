<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'test-password-only';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        Notification::fake();
    }

    private function account(Role $role = Role::Consumer): User
    {
        $user = User::factory()->create([
            'email' => 'synthetic-'.(++$this->sequence).'@example.test',
            'display_name' => 'Synthetic account',
        ]);
        $user->membership->update(['role' => $role]);

        return $user->refresh();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'display_name' => 'Synthetic Consumer',
            'email' => 'consumer@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'notice_acknowledged' => '1',
            'notice_version' => PrivacyNotice::VERSION,
        ], $overrides);
    }

    private function resetToken(User $user): string
    {
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', PasswordRecoveryController::REQUEST_MESSAGE);
        $notification = Notification::sent($user, ResetPassword::class)->last();
        $this->assertNotNull($notification);

        return $notification->token;
    }

    private function resetPayload(User $user, string $token): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-test-password-only',
            'password_confirmation' => 'new-test-password-only',
        ];
    }

    public function test_registration_is_atomic_and_records_current_notice_with_hashed_password(): void
    {
        $this->post('/register', $this->registration(['email' => ' Consumer@EXAMPLE.TEST ']))
            ->assertRedirect(route('consumer.home'))->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertNotSame(self::PASSWORD, $user->password_hash);
        $this->assertSame(Role::Consumer, $user->role());
        $this->assertSame('active', $user->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $user->id);
        $this->assertDatabaseHas('notice_acknowledgments', ['user_id' => $user->id, 'notice_version' => PrivacyNotice::VERSION]);
        $this->assertTrue($user->noticeAcknowledgments->sole()->acknowledged_at->equalTo(now()));
        $this->assertSame($user->session_version, session('auth_session_version'));
    }

    public static function invalidRegistration(): array
    {
        return [
            'invalid email' => [['email' => 'invalid'], 'email'],
            'array email' => [['email' => ['invalid']], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'mismatch' => [['password_confirmation' => 'different-password'], 'password'],
            'missing notice' => [['notice_acknowledged' => '0'], 'notice_acknowledged'],
            'stale notice' => [['notice_version' => 'unreviewed'], 'notice_version'],
            'long name' => [['display_name' => str_repeat('a', 101)], 'display_name'],
            'staff injection' => [['role' => 'administrator'], 'role'],
            'status injection' => [['status' => 'active'], 'status'],
            'membership injection' => [['memberships' => ['role' => 'collector']], 'memberships'],
            'session injection' => [['session_version' => 9], 'session_version'],
        ];
    }

    #[DataProvider('invalidRegistration')]
    public function test_invalid_registration_creates_no_partial_account(array $fields, string $error): void
    {
        $this->post('/register', $this->registration($fields))->assertSessionHasErrors($error);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('notice_acknowledgments', 0);
        $this->assertGuest();
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->get('/register')->assertOk();
    }

    public function test_duplicate_email_is_rejected_and_display_name_is_optional(): void
    {
        $user = $this->account();
        $this->post('/register', $this->registration(['email' => strtoupper($user->email)]))->assertSessionHasErrors('email');
        $this->post('/register', $this->registration(['display_name' => null]))->assertSessionHasNoErrors();
        $this->assertSame(2, User::count());
    }

    public function test_long_unicode_password_is_not_truncated_by_hashing(): void
    {
        $password = str_repeat('é', 45).'first-suffix';
        $this->post('/register', $this->registration(['password' => $password, 'password_confirmation' => $password]))
            ->assertSessionHasNoErrors();
        $hash = User::sole()->password_hash;
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertFalse(Hash::check(str_repeat('é', 45).'different-suffix', $hash));
    }

    public function test_unrecognized_registration_attributes_cannot_change_account_privileges(): void
    {
        $this->post('/register', $this->registration(['version' => 99, 'id' => 'forged', 'active' => false]))->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertSame(1, $user->version);
        $this->assertTrue($user->membership->active);
        $this->assertSame(Role::Consumer, $user->role());
    }

    public static function roles(): array
    {
        return array_map(fn (Role $role) => [$role], Role::cases());
    }

    #[DataProvider('roles')]
    public function test_valid_login_rotates_session_and_all_role_boundaries_are_enforced(Role $role): void
    {
        $user = $this->account($role);
        $this->get('/login')->assertOk();
        $previousId = Session::getId();
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => self::PASSWORD])
            ->assertRedirect(route($role->value.'.home'));
        $this->assertNotSame($previousId, Session::getId());
        $this->assertAuthenticatedAs($user);
        foreach (Role::cases() as $other) {
            $response = $this->get(route($other->value.'.home'));
            if ($other === $role) {
                $response->assertOk()->assertSee($role->label().' workspace');
            } else {
                $response->assertForbidden();
            }
        }
        $this->get('/dashboard')->assertRedirect(route($role->value.'.home'));
    }

    public function test_guest_cannot_access_any_role_home(): void
    {
        foreach (Role::cases() as $role) {
            $this->get(route($role->value.'.home'))->assertRedirect(route('login'));
        }
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_unknown_and_invalid_credentials_use_the_same_safe_error(): void
    {
        $user = $this->account();
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'incorrect-password'])
                ->assertSessionHasErrors(['email' => 'Unable to sign in with these credentials.']);
            $this->assertGuest();
        }
    }

    public function test_five_failed_attempts_block_login_for_fifteen_minutes(): void
    {
        $user = $this->account();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect-password'])
                ->assertSessionHasErrors(['email' => 'Unable to sign in with these credentials.']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Too many sign-in attempts. Try again in 900 seconds.']);
        $this->assertGuest();
        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('consumer.home'));
    }

    public function test_successful_login_clears_prior_failures(): void
    {
        $user = $this->account();
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect-password']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->post('/logout');
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect-password'])
                ->assertSessionHasErrors(['email' => 'Unable to sign in with these credentials.']);
        }
    }

    public static function deniedAccounts(): array
    {
        return [['suspended', true], ['redacted', true], ['active', false]];
    }

    #[DataProvider('deniedAccounts')]
    public function test_ineligible_account_is_denied_at_login_and_during_existing_session(string $status, bool $membershipActive): void
    {
        $user = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $user->forceFill(['status' => $status])->save();
        $user->membership->update(['active' => $membershipActive]);
        $this->get('/consumer')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Unable to sign in with these credentials.']);
        $this->assertGuest();
    }

    public function test_logout_is_post_only_and_invalidates_session_and_csrf_token(): void
    {
        $user = $this->account();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $previousId = Session::getId();
        $previousToken = session()->token();
        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($previousId, Session::getId());
        $this->assertNotSame($previousToken, session()->token());
        $this->assertNull(session('auth_session_version'));
        $this->get('/consumer')->assertRedirect(route('login'));
    }

    public function test_browser_commands_require_csrf_outside_the_test_bypass(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        foreach (['/register', '/login', '/forgot-password', '/reset-password'] as $path) {
            $this->post($path)->assertStatus(419);
        }
    }

    public function test_recovery_known_unknown_inactive_and_repeated_requests_have_identical_responses(): void
    {
        $user = $this->account();
        $inactive = $this->account();
        $inactive->forceFill(['status' => 'suspended'])->save();
        foreach ([$user->email, 'unknown@example.test', $inactive->email, $user->email] as $email) {
            $this->from(route('password.request'))->post('/forgot-password', ['email' => $email])
                ->assertRedirect(route('password.request'))
                ->assertSessionHas('status', PasswordRecoveryController::REQUEST_MESSAGE)->assertSessionHasNoErrors();
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_local_log_mail_link_opens_the_reset_form_and_resets_the_synthetic_account(): void
    {
        $user = $this->account();
        $directory = sys_get_temp_dir().'/ewaste-mail-test-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        $path = $directory.'/recovery.log';
        config([
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'recovery_test',
            'logging.channels.recovery_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'],
        ]);
        Notification::swap(new ChannelManager($this->app));
        try {
            $response = $this->from(route('password.request'))->post('/forgot-password', ['email' => $user->email]);
            $response->assertSessionHas('status', PasswordRecoveryController::REQUEST_MESSAGE);
            $message = quoted_printable_decode(File::get($path));
            $this->assertSame(1, preg_match('~/reset-password/([a-f0-9]{64})~', $message, $matches));
            $this->assertTrue(Password::tokenExists($user, $matches[1]));
            $this->assertFalse(str_contains($response->getContent(), $matches[1]));
            $this->assertSame(1, preg_match('~https?://[^\s"<>]+/reset-password/[a-f0-9]{64}\?email=[^\s"<>]+~', $message, $links));
            $link = html_entity_decode($links[0]);
            $form = $this->get($link)->assertOk();
            $this->assertTrue(str_contains($form->getContent(), 'Choose a new password'));
            $this->post('/reset-password', $this->resetPayload($user, $matches[1]))->assertRedirect(route('login'));
            $this->get('/login')->assertSee('Password updated. Sign in with your new password.')->assertSee('feedback--success');
            $this->assertTrue(Hash::check('new-test-password-only', $user->fresh()->password_hash));
            $this->get($link)->assertRedirect(route('password.request'));
            $this->post('/reset-password', $this->resetPayload($user, $matches[1]))->assertSessionHasErrors('email');
            $this->actingAs($user->fresh())->withSession(['auth_session_version' => 1])->get('/consumer')->assertRedirect(route('login'));
            $this->assertGuest();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_recovery_token_is_hashed_and_success_is_single_use_with_session_revocation(): void
    {
        $user = $this->account();
        $token = $this->resetToken($user);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
        $this->post('/reset-password', $this->resetPayload($user, $token))->assertRedirect(route('login'));
        $user->refresh();
        $this->assertTrue(Hash::check('new-test-password-only', $user->password_hash));
        $this->assertSame(2, $user->session_version);
        $this->assertSame(2, $user->version);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertGuest();
        $this->post('/reset-password', $this->resetPayload($user, $token))->assertSessionHasErrors('email');
        $this->assertSame(2, $user->fresh()->session_version);
        $this->actingAs($user)->withSession(['auth_session_version' => 1])->get('/consumer')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'new-test-password-only'])->assertRedirect(route('consumer.home'));
    }

    public function test_expired_and_invalid_tokens_cannot_change_the_password(): void
    {
        $user = $this->account();
        $token = $this->resetToken($user);
        $previousHash = $user->password_hash;
        $this->post('/reset-password', $this->resetPayload($user, 'invalid-token'))->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/reset-password', $this->resetPayload($user, $token))->assertSessionHasErrors('email');
        $this->assertSame($previousHash, $user->fresh()->password_hash);
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_reset_validation_preserves_token_and_never_flashes_secrets(): void
    {
        $user = $this->account();
        $token = $this->resetToken($user);
        $this->post('/reset-password', array_replace($this->resetPayload($user, $token), ['password_confirmation' => 'mismatch']))
            ->assertSessionHasErrors('password');
        $this->assertTrue(Password::tokenExists($user, $token));
        foreach (['password', 'password_confirmation', 'token'] as $secret) {
            $this->assertArrayNotHasKey($secret, session()->getOldInput());
        }
    }

    public function test_suspension_after_issuance_rejects_reset_and_a_new_link_supersedes_an_old_one(): void
    {
        $user = $this->account();
        $oldToken = $this->resetToken($user);
        $this->travel(61)->seconds();
        $token = $this->resetToken($user);
        $this->assertFalse(Password::tokenExists($user, $oldToken));
        $user->forceFill(['status' => 'suspended'])->save();
        $this->post('/reset-password', $this->resetPayload($user, $token))->assertSessionHasErrors('email');
        $this->assertSame(1, $user->fresh()->session_version);
    }

    public function test_legacy_or_unbound_authenticated_session_is_rejected(): void
    {
        $this->actingAs($this->account())->get('/consumer')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_reset_revokes_multiple_existing_file_sessions_when_replayed(): void
    {
        $user = $this->account();
        $token = $this->resetToken($user);
        $directory = sys_get_temp_dir().'/ewaste-session-test-'.Str::uuid();
        File::makeDirectory($directory, 0700);
        config(['session.driver' => 'file', 'session.files' => $directory]);
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $store = app('session')->driver();
        $cookie = config('session.cookie');
        $sessions = [Str::random(40), Str::random(40)];
        try {
            foreach ($sessions as $id) {
                $old = new Store($cookie, $store->getHandler(), $id, config('session.serialization'));
                $old->start();
                $old->put(Auth::guard()->getName(), $user->id);
                $old->put('auth_session_version', 1);
                $old->save();
            }
            $this->app['auth']->forgetGuards();
            $this->withCookie($cookie, $sessions[0])->get('/consumer')->assertOk();
            $this->app['auth']->forgetGuards();
            $store->flush();
            $this->defaultCookies = [];
            $this->post('/reset-password', $this->resetPayload($user, $token))->assertRedirect(route('login'));
            foreach ($sessions as $id) {
                $this->app['auth']->forgetGuards();
                $store->flush();
                $this->withCookie($cookie, $id)->get('/consumer')->assertRedirect(route('login'));
                $this->assertGuest();
                $this->assertFileDoesNotExist($directory.'/'.$id);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_staff_bootstrap_is_local_only_and_rejects_invalid_roles(): void
    {
        $this->artisan('app:provision-staff', ['role' => 'administrator'])->assertFailed();
        $this->app->detectEnvironment(fn () => 'local');
        foreach (['consumer', 'observer'] as $role) {
            $this->artisan('app:provision-staff', ['role' => $role])->assertFailed();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_staff_bootstrap_creates_only_the_requested_role_without_default_password(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->artisan('app:provision-staff', ['role' => 'collector'])
            ->expectsQuestion('Email', 'collector@example.test')
            ->expectsQuestion('Display name (optional)', 'Synthetic collector')
            ->expectsQuestion('Password (12–128 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();
        $user = User::sole();
        $this->assertSame(Role::Collector, $user->role());
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertDatabaseCount('notice_acknowledgments', 0);
    }

    public function test_public_forms_and_notice_are_accessible_without_authentication(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/privacy-notice'] as $path) {
            $this->get($path)->assertOk()->assertSee('Skip to content')->assertSee('Nairobi County');
        }
        $this->get('/privacy-notice')->assertSee(PrivacyNotice::VERSION);
        $this->get('/reset-password/synthetic-invalid-token?email[]=invalid')->assertRedirect(route('password.request'));
    }
}
