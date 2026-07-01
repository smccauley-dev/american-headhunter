<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Identity\SendPasswordResetJob;
use App\Models\Identity\PasswordResetToken;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mobile password recovery. JSON mirror of the web Auth\PasswordController — same
 * token issuance, same lockout-clearing reset — returning API responses instead
 * of Inertia redirects. Routed on the db.system path so the pre-context identity
 * writes (token row, password update) are not RLS-denied.
 */
class PasswordController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', strtolower($request->input('email')))->first();

        // Always report success — never reveal whether the email is on file.
        if ($user) {
            $token = Str::random(64);

            PasswordResetToken::create([
                'user_id' => $user->id,
                'token_hash' => Hash::make($token),
                'expires_at' => now()->addHour(),
                'ip_address' => $request->ip(),
            ]);

            SendPasswordResetJob::dispatch($user->id, $token);
        }

        return response()->json([
            'message' => 'If that email is on file, a reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $user = User::where('email', strtolower($request->input('email')))->first();

        if (! $user) {
            return response()->json(['message' => 'This reset link is invalid or has expired.'], 422);
        }

        $resetToken = PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (! $resetToken || ! Hash::check($request->input('token'), $resetToken->token_hash)) {
            return response()->json(['message' => 'This reset link is invalid or has expired.'], 422);
        }

        $resetToken->update(['used_at' => now()]);

        // A successful reset proves email ownership, so clear any failed-login
        // lockout — otherwise the new password still can't get in (attempt()
        // checks isLocked() before the password).
        $user->update([
            'password_hash' => Hash::make($request->input('password')),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $this->audit->logPasswordChanged($user->id, $request->ip());

        return response()->json(['message' => 'Password reset. You can now sign in.']);
    }
}
