<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // El límite usa la IP real (proxies de confianza de Cloudflare en bootstrap/app.php).
        $key = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Vuelve a probar en {$seconds} segundos.",
            ]);
        }

        if (TwoFactorService::enabled()) {
            // Con 2FA la contraseña sola no abre sesión: se comprueba y se pide el código del correo.
            $user = Auth::getProvider()->retrieveByCredentials(['email' => $credentials['email']]);

            if (! $user || ! Auth::getProvider()->validateCredentials($user, $credentials)) {
                RateLimiter::hit($key, 60);

                throw ValidationException::withMessages(['email' => 'Las credenciales no son correctas.']);
            }

            RateLimiter::clear($key);

            return app(TwoFactorController::class)->begin($request, $user, $request->boolean('remember'));
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended('/blogs');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
