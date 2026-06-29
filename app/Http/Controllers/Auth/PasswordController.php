<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\Identity\SendPasswordResetJob;
use App\Models\Identity\PasswordResetToken;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function showForgot(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', strtolower($request->input('email')))->first();

        // Always return success — don't reveal whether email exists
        if ($user) {
            $token = Str::random(64);

            PasswordResetToken::create([
                'user_id'    => $user->id,
                'token_hash' => Hash::make($token),
                'expires_at' => now()->addHour(),
                'ip_address' => $request->ip(),
            ]);

            SendPasswordResetJob::dispatch($user->id, $token);
        }

        return back()->with('success', 'If that email is on file, a reset link has been sent.');
    }

    public function showReset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:12|confirmed',
        ]);

        $user = User::where('email', strtolower($request->input('email')))->first();

        if (! $user) {
            return back()->withErrors(['email' => 'Invalid reset link.']);
        }

        $resetToken = PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (! $resetToken || ! Hash::check($request->input('token'), $resetToken->token_hash)) {
            return back()->withErrors(['token' => 'This reset link is invalid or has expired.']);
        }

        $resetToken->update(['used_at' => now()]);

        // A successful reset proves email ownership, so clear any failed-login
        // lockout — otherwise the new password still can't get in (attempt()
        // checks isLocked() before the password).
        $user->update([
            'password_hash'         => Hash::make($request->input('password')),
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ]);

        $this->audit->logPasswordChanged($user->id, $request->ip());

        return redirect()->route('auth.login')
            ->with('success', 'Password reset. Please log in.');
    }
}
