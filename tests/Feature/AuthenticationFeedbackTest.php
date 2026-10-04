<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Models\User;
use App\Support\PrivacyNotice;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationFeedbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $this->travelTo(now()->setDate(2026, 10, 4)->startOfDay());
        Notification::fake();
    }

    private function account(): User
    {
        return User::factory()->create(['email' => 'feedback@example.test', 'display_name' => 'Synthetic Consumer']);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_registration_login_and_logout_success_appears_on_each_destination(): void
    {
        $this->post('/register', [
            'email' => 'feedback@example.test',
            'password' => 'test-password-only',
            'password_confirmation' => 'test-password-only',
            'notice_acknowledged' => '1',
            'notice_version' => PrivacyNotice::VERSION,
        ])->assertRedirect(route('consumer.home'));
        $this->get('/consumer')->assertOk()->assertSee('Your Consumer account has been created. You are signed in.')
            ->assertSee('feedback--success')->assertSee('role="status"', false);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('You have signed out.')->assertSee('feedback--success');
        $this->post('/login', ['email' => 'feedback@example.test', 'password' => 'test-password-only'])
            ->assertRedirect(route('consumer.home'));
        $response = $this->get('/consumer')->assertOk()->assertSee('You have signed in.')->assertSee('feedback--success');
        $document = $this->document($response->getContent());
        $this->assertSame(1, $document->query('//*[@role="status"]')->length);
        $this->assertSame(1, $document->query('//*[@id="auth-feedback"]//svg[@aria-hidden="true"]')->length);
    }

    public function test_recovery_feedback_is_identical_and_neutral_for_known_unknown_suspended_and_throttled_requests(): void
    {
        $user = $this->account();
        $suspended = User::factory()->create(['email' => 'suspended@example.test', 'display_name' => 'Synthetic suspended account']);
        $suspended->forceFill(['status' => 'suspended'])->save();
        $limited = User::factory()->create(['email' => 'limited@example.test', 'display_name' => 'Synthetic limited account']);
        $acknowledgment = 'If an eligible account exists for that email, password reset instructions will be sent.';
        $this->assertSame($acknowledgment, PasswordRecoveryController::REQUEST_MESSAGE);
        $contents = [];
        foreach ([$user->email, 'unknown@example.test', $suspended->email, $user->email, 'unknown@example.test', $limited->email, 'unknown@example.test'] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertRedirect(route('password.request'))
                ->assertSessionHas('status', $acknowledgment)->assertSessionHas('status_type', 'info');
            $response = $this->get('/forgot-password')->assertOk()->assertSee($acknowledgment)->assertSee('feedback--info');
            $document = $this->document($response->getContent());
            $contents[] = $document->query('//*[@id="auth-feedback"]')->item(0)->textContent;
            $this->assertSame(1, $document->query('//*[@role="status"]')->length);
            $this->assertSame(0, $document->query('//*[@role="alert"]')->length);
        }
        $this->assertCount(1, array_unique($contents));
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertNotSentTo($suspended, ResetPassword::class);
        Notification::assertNotSentTo($limited, ResetPassword::class);
        $this->travel(61)->seconds();
        $this->post('/forgot-password', ['email' => $limited->email])
            ->assertRedirect(route('password.request'))->assertSessionHas('status', $acknowledgment);
        Notification::assertSentToTimes($limited, ResetPassword::class, 1);
    }

    public function test_validation_summary_and_inline_errors_are_associated_without_duplicate_live_regions(): void
    {
        $this->from('/register')->post('/register', [
            'email' => 'invalid-email', 'display_name' => 'Safe synthetic name',
            'password' => 'short', 'password_confirmation' => 'different', 'token' => 'synthetic-secret-value',
        ])->assertSessionHasErrors(['email', 'password', 'notice_acknowledged']);
        foreach (['password', 'password_confirmation', 'token'] as $secret) {
            $this->assertArrayNotHasKey($secret, session()->getOldInput());
        }
        $this->withCookie(config('session.cookie'), session()->getId());
        $response = $this->get('/register')->assertOk()->assertSee('Safe synthetic name')->assertSee('feedback--error');
        $document = $this->document($response->getContent());
        $this->assertSame(1, $document->query('//*[@role="alert"]')->length);
        $this->assertSame(1, $document->query('//*[@id="auth-errors" and @tabindex="-1" and @data-error-summary]')->length);
        foreach (['email', 'password', 'notice_acknowledged'] as $field) {
            $input = $document->query('//*[@id="'.$field.'"]')->item(0);
            $this->assertSame('true', $input->getAttribute('aria-invalid'));
            $this->assertContains($field.'-error', explode(' ', $input->getAttribute('aria-describedby')));
            $this->assertSame(1, $document->query('//*[@id="'.$field.'-error"]')->length);
            $this->assertGreaterThanOrEqual(1, $document->query('//*[@id="auth-errors"]//a[@href="#'.$field.'"]')->length);
        }
        $this->assertSame('', $document->query('//*[@id="password"]')->item(0)->getAttribute('value'));
        $this->assertSame('', $document->query('//*[@id="password_confirmation"]')->item(0)->getAttribute('value'));
        $this->assertSame('invalid-email', $document->query('//*[@id="email"]')->item(0)->getAttribute('value'));
    }

    public function test_unsuccessful_and_throttled_login_has_clear_safe_feedback(): void
    {
        $user = $this->account();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'incorrect-password'])
                ->assertSessionHasErrors('email');
            $this->withCookie(config('session.cookie'), session()->getId());
            $this->get('/login')->assertSee('Unable to sign in with these credentials.')->assertSee('feedback--error');
        }
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'test-password-only']);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/login')->assertSee('Too many sign-in attempts.')->assertSee('feedback--error');
        $this->assertGuest();
    }

    public function test_expired_and_invalid_links_redirect_to_actionable_generic_error_feedback(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
        $this->travel(61)->minutes();
        foreach ([$token, 'synthetic-invalid-token'] as $value) {
            $this->get(route('password.reset', ['token' => $value, 'email' => $user->email]))
                ->assertRedirect(route('password.request'));
            $this->get('/forgot-password')->assertOk()->assertSee('This reset link is invalid or expired. Request a new link.')
                ->assertSee('Request reset link')->assertSee('feedback--error');
            $this->assertArrayNotHasKey('token', session()->getOldInput());
        }
    }

    public function test_feedback_escapes_message_text_and_uses_the_short_staff_guidance(): void
    {
        $message = '<script>alert("synthetic")</script>';
        $this->withSession(['status' => $message, 'status_type' => 'success'])->get('/login')
            ->assertSee($message)->assertDontSee($message, false);
        $this->get('/register')->assertSee('Staff accounts are created by an administrator.')
            ->assertDontSee('Staff accounts are provided by a project administrator.');
    }
}
