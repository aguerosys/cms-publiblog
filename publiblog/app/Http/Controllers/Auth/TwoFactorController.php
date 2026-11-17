<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Segundo paso del login: la sesión solo guarda "contraseña correcta, falta el código". */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        return view('admin.two-factor', ['email' => $this->mask($user->email)]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $result = $this->twoFactor->verify($user, $data['code']);

        if ($result === TwoFactorService::OK) {
            $remember = (bool) $request->session()->get('two_factor.remember');
            $request->session()->forget('two_factor');

            Auth::login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended('/blogs');
        }

        if (in_array($result, [TwoFactorService::EXPIRED, TwoFactorService::LOCKED], true)) {
            $request->session()->forget('two_factor');

            return redirect()->route('login')->withErrors([
                'email' => $result === TwoFactorService::LOCKED
                    ? 'Demasiados intentos con el código. Vuelve a iniciar sesión para recibir uno nuevo.'
                    : 'El código ha caducado. Vuelve a iniciar sesión para recibir uno nuevo.',
            ]);
        }

        throw ValidationException::withMessages(['code' => 'El código no es correcto.']);
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $key = 'two-factor-resend:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['code' => 'Has pedido demasiados códigos. Espera '.RateLimiter::availableIn($key).' segundos.']);
        }

        RateLimiter::hit($key, 3600);

        if (! $this->send($user)) {
            $request->session()->forget('two_factor');

            return redirect()->route('login')->withErrors(['email' => 'No se pudo enviar el código. Inténtalo de nuevo más tarde.']);
        }

        return back()->with('status', 'Te hemos enviado un código nuevo.');
    }

    /** Lo usa el login tras comprobar la contraseña. */
    public function begin(Request $request, User $user, bool $remember): RedirectResponse
    {
        if (! $this->send($user)) {
            return redirect()->route('login')->withErrors(['email' => 'No se pudo enviar el código de acceso. Inténtalo de nuevo más tarde.']);
        }

        $request->session()->regenerate();
        $request->session()->put('two_factor', ['user_id' => $user->id, 'remember' => $remember]);

        return redirect()->route('two-factor.show');
    }

    private function send(User $user): bool
    {
        try {
            $this->twoFactor->issue($user);

            return true;
        } catch (\Throwable $e) {
            report($e);
            $this->twoFactor->clear($user);

            return false;
        }
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor.user_id');

        return $id ? User::find($id) : null;
    }

    private function mask(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
    }
}
