<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** F2-3: doble factor por correo. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['publiblog.two_factor.enabled' => true]);
        Mail::fake();
        RateLimiter::clear('two-factor-resend:1');

        $this->user = User::factory()->create(['role' => 'superadmin', 'email' => 'ana@example.com']);
    }

    /** Hace login con contraseña y devuelve el código enviado por correo. */
    private function loginAndGetCode(): string
    {
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertRedirect(route('two-factor.show'));

        $code = null;
        Mail::assertSent(TwoFactorCode::class, function (TwoFactorCode $m) use (&$code) {
            $code = $m->code;

            return $m->hasTo('ana@example.com');
        });

        return $code;
    }

    public function test_password_alone_does_not_open_a_session_when_2fa_is_on(): void
    {
        $this->loginAndGetCode();

        $this->assertGuest();
        $this->get('/blogs')->assertRedirect(route('login'));
    }

    public function test_correct_code_completes_the_login(): void
    {
        $code = $this->loginAndGetCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->get(route('two-factor.show'))->assertOk()->assertSee('an•@example.com', false)->assertDontSee('ana@example.com');

        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect('/blogs');

        $this->assertAuthenticatedAs($this->user);
        $this->assertNull($this->user->fresh()->two_factor_code);
    }

    public function test_code_is_stored_hashed_not_in_clear(): void
    {
        $code = $this->loginAndGetCode();

        $stored = $this->user->fresh()->getRawOriginal('two_factor_code');
        $this->assertNotSame($code, $stored);
        $this->assertSame(64, strlen($stored));
    }

    public function test_code_is_single_use(): void
    {
        $code = $this->loginAndGetCode();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect('/blogs');

        $this->post(route('logout'));
        $this->flushSession();

        // Reutilizar el mismo código en un login nuevo no sirve.
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_wrong_code_is_rejected_and_keeps_the_session_pending(): void
    {
        $code = $this->loginAndGetCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->post(route('two-factor.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertGuest();

        // El código bueno sigue valiendo tras un fallo.
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect('/blogs');
        $this->assertAuthenticated();
    }

    public function test_code_is_locked_after_too_many_wrong_attempts_even_if_the_right_one_follows(): void
    {
        $code = $this->loginAndGetCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        }

        // El quinto fallo bloquea: hay que volver a empezar desde la contraseña.
        $this->post(route('two-factor.verify'), ['code' => $wrong])->assertRedirect(route('login'));
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($this->user->fresh()->two_factor_code);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->loginAndGetCode();

        $this->travel(11)->minutes();

        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_password_never_sends_a_code(): void
    {
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'mala'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->get(route('two-factor.show'))->assertRedirect(route('login'));
    }

    public function test_challenge_page_requires_a_pending_login(): void
    {
        $this->get(route('two-factor.show'))->assertRedirect(route('login'));
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->post(route('two-factor.resend'))->assertRedirect(route('login'));
    }

    public function test_resend_issues_a_new_code_and_invalidates_the_old_one(): void
    {
        $first = $this->loginAndGetCode();

        $this->post(route('two-factor.resend'))->assertSessionHas('status');
        Mail::assertSent(TwoFactorCode::class, 2);

        $second = null;
        Mail::assertSent(TwoFactorCode::class, function (TwoFactorCode $m) use (&$second, $first) {
            if ($m->code !== $first) {
                $second = $m->code;
            }

            return true;
        });

        if ($second !== null && $second !== $first) {
            $this->post(route('two-factor.verify'), ['code' => $first])->assertSessionHasErrors('code');
            $this->post(route('two-factor.verify'), ['code' => $second])->assertRedirect('/blogs');
        } else {
            $this->markTestSkipped('Los dos códigos aleatorios coincidieron.');
        }
    }

    public function test_resend_is_rate_limited(): void
    {
        $this->loginAndGetCode();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('two-factor.resend'))->assertSessionHas('status');
        }

        $this->post(route('two-factor.resend'))->assertSessionHasErrors('code');
        Mail::assertSent(TwoFactorCode::class, 4); // 1 del login + 3 reenvíos
    }

    public function test_remember_choice_survives_the_second_step(): void
    {
        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password', 'remember' => '1']);
        $code = null;
        Mail::assertSent(TwoFactorCode::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        $response = $this->post(route('two-factor.verify'), ['code' => $code]);

        $response->assertRedirect('/blogs');
        $this->assertNotEmpty($this->user->fresh()->remember_token);
        $this->assertTrue(collect($response->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
    }

    public function test_mail_failure_does_not_leave_a_usable_code_or_session(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp caído'));

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($this->user->fresh()->two_factor_code);
        $this->get(route('two-factor.show'))->assertRedirect(route('login'));
    }

    public function test_login_is_unchanged_when_2fa_is_disabled(): void
    {
        config(['publiblog.two_factor.enabled' => false]);

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password'])->assertRedirect('/blogs');

        $this->assertAuthenticated();
        Mail::assertNothingSent();
    }

    public function test_the_code_never_leaks_in_user_serialization(): void
    {
        $this->loginAndGetCode();

        $this->assertArrayNotHasKey('two_factor_code', $this->user->fresh()->toArray());
    }
}
