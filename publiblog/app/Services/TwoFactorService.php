<?php

namespace App\Services;

use App\Mail\TwoFactorCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Doble factor por correo (F2-3). El código tiene 6 dígitos, caduca, solo vale una vez y
 * se invalida tras demasiados fallos; en base de datos solo queda su huella (HMAC con APP_KEY).
 */
class TwoFactorService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';

    public static function enabled(): bool
    {
        return (bool) config('publiblog.two_factor.enabled');
    }

    public function issue(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = (int) config('publiblog.two_factor.ttl_minutes');

        $user->forceFill([
            'two_factor_code' => $this->fingerprint($user, $code),
            'two_factor_expires_at' => now()->addMinutes($minutes),
            'two_factor_attempts' => 0,
        ])->save();

        Mail::to($user->email)->send(new TwoFactorCode($code, $minutes));
    }

    public function verify(User $user, string $input): string
    {
        $user->refresh();

        if (! $user->two_factor_code || ! $user->two_factor_expires_at || $user->two_factor_expires_at->isPast()) {
            $this->clear($user);

            return self::EXPIRED;
        }

        $max = (int) config('publiblog.two_factor.max_attempts');

        if ($user->two_factor_attempts >= $max) {
            $this->clear($user);

            return self::LOCKED;
        }

        $input = preg_replace('/\D+/', '', $input) ?? '';

        if ($input !== '' && hash_equals($user->two_factor_code, $this->fingerprint($user, $input))) {
            $this->clear($user);

            return self::OK;
        }

        $user->forceFill(['two_factor_attempts' => $user->two_factor_attempts + 1])->save();

        if ($user->two_factor_attempts >= $max) {
            $this->clear($user);

            return self::LOCKED;
        }

        return self::INVALID;
    }

    public function clear(User $user): void
    {
        $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null, 'two_factor_attempts' => 0])->save();
    }

    /** Ligado al usuario: el mismo código no vale para otra cuenta. */
    private function fingerprint(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->getKey().':'.$code, (string) config('app.key'));
    }
}
