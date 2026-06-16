<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\Auth\MfaService;
use App\Services\Mfa\TotpMfaMethod;
use App\Services\Platform\MfaFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    public function __construct(
        private readonly AuditService      $audit,
        private readonly MfaService        $mfa,
        private readonly TotpMfaMethod     $totp,
        private readonly MfaFactorService  $factors,
    ) {}

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password'      => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password_hash' => Hash::make($data['password'])]);

        try {
            $this->audit->logPasswordChanged($userId, $request->ip());
        } catch (\Throwable) {}

        return redirect()->route('member.profile')->with('success', 'Password updated successfully.');
    }

    public function enableMfa(Request $request, string $method)
    {
        // TOTP cannot be flag-flipped on — it requires a real enrolled secret,
        // or the user is locked out at login with a code that can never validate.
        // Use the enroll/confirm flow (enrollTotp + confirmTotp) instead.
        if (! in_array($method, ['sms', 'email'])) {
            abort(422);
        }

        $userId = session('auth.user_id');

        $config = MfaConfiguration::firstOrNew(['user_id' => $userId, 'method' => $method]);
        $config->is_enabled  = true;
        $config->verified_at = now();
        $config->save();

        try {
            $this->audit->log(
                eventType:      'mfa_enabled',
                sourceDatabase: 'ah_identity',
                tableName:      'mfa_configurations',
                recordId:       $userId,
                userId:         $userId,
                ipAddress:      $request->ip(),
            );
        } catch (\Throwable) {}

        return redirect()->route('member.profile')->with('success', 'Two-factor authentication enabled.');
    }

    public function setProfileVisibility(Request $request)
    {
        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);

        $makePublic = (bool) $request->input('is_profile_public', false);

        if ($makePublic && ! $user->username) {
            $request->validate([
                'is_profile_public' => 'required|boolean',
                'username'          => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z][a-z0-9_]{2,29}$/'],
            ]);

            $username = $request->input('username');

            if (DB::connection('identity')->table('users')->where('username', $username)->exists()) {
                return back()->withErrors(['username' => 'That username is already taken.']);
            }

            $user->update(['is_profile_public' => true, 'username' => $username]);
        } else {
            $request->validate(['is_profile_public' => 'required|boolean']);
            $user->update(['is_profile_public' => $makePublic]);
        }

        try {
            $this->audit->log(
                eventType:      $makePublic ? 'profile_made_public' : 'profile_made_private',
                sourceDatabase: 'ah_identity',
                tableName:      'users',
                recordId:       $userId,
                userId:         $userId,
                ipAddress:      $request->ip(),
            );
        } catch (\Throwable) {}

        $msg = $makePublic ? 'Your profile is now public.' : 'Your profile is now private.';

        return redirect()->route('member.profile')->with('success', $msg);
    }

    public function checkUsername(string $username)
    {
        $clean = strtolower(trim($username));

        if (! preg_match('/^[a-z][a-z0-9_]{2,29}$/', $clean)) {
            return response()->json(['available' => false, 'reason' => 'invalid']);
        }

        $taken = DB::connection('identity')
            ->table('users')
            ->where('username', $clean)
            ->whereNull('deleted_at')
            ->exists();

        return response()->json(['available' => ! $taken]);
    }

    public function disableMfa(Request $request, string $method)
    {
        if (! in_array($method, ['totp', 'sms', 'email'])) {
            abort(422);
        }

        $data = $request->validate([
            'current_password' => 'required|string',
        ]);

        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            return back()->withErrors(['mfa_password' => 'Password is incorrect.']);
        }

        MfaConfiguration::where('user_id', $userId)
            ->where('method', $method)
            ->update(['is_enabled' => false, 'verified_at' => null]);

        try {
            $this->audit->log(
                eventType:      'mfa_disabled',
                sourceDatabase: 'ah_identity',
                tableName:      'mfa_configurations',
                recordId:       $userId,
                userId:         $userId,
                ipAddress:      $request->ip(),
            );
        } catch (\Throwable) {}

        return redirect()->route('member.profile')->with('success', 'Two-factor authentication disabled.');
    }

    /**
     * Begin TOTP enrollment: generate a secret, store it (disabled until
     * confirmed), and return the secret + a scannable QR code for the user's
     * authenticator app. JSON — driven by the security page, not Inertia.
     */
    public function enrollTotp(Request $request): JsonResponse
    {
        if (! $this->factors->isFactorEnabled('totp')) {
            return response()->json(['message' => 'Authenticator app MFA is not currently available.'], 422);
        }

        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);

        $secret = $this->totp->generateSecret();
        $this->totp->storeSecret($user, $secret);

        return response()->json([
            'secret'      => $secret,
            'qr_code_uri' => $this->totp->qrCodeSvgDataUri($user->email, $secret),
        ]);
    }

    /**
     * Confirm TOTP enrollment: verify a code against the freshly stored secret,
     * enable the factor, and (on first-ever enrollment) issue recovery codes.
     */
    public function confirmTotp(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);

        if (! $this->totp->verify($user, $data['code'])) {
            return response()->json(['message' => 'That code is incorrect. Try again.'], 422);
        }

        // Recovery codes are generated once per account (keyed on presence in
        // user_recovery_codes), so re-enrolling does not regenerate them.
        $isFirstEnrollment = ! $this->mfa->hasRecoveryCodes($user);

        MfaConfiguration::where('user_id', $user->id)
            ->where('method', 'totp')
            ->update(['is_enabled' => true, 'verified_at' => now()]);

        try {
            $this->audit->log(
                eventType:      'mfa_enabled',
                sourceDatabase: 'ah_identity',
                tableName:      'mfa_configurations',
                recordId:       $user->id,
                userId:         $user->id,
                ipAddress:      $request->ip(),
                actionSummary:  'TOTP enrolled (self-service)',
            );
        } catch (\Throwable) {}

        $response = ['verified' => true];

        if ($isFirstEnrollment) {
            $response['recovery_codes'] = $this->mfa->generateBackupCodes($user);
            try {
                $this->audit->log(
                    eventType:      'recovery_codes_generated',
                    sourceDatabase: 'ah_identity',
                    tableName:      'user_recovery_codes',
                    recordId:       $user->id,
                    userId:         $user->id,
                    ipAddress:      $request->ip(),
                );
            } catch (\Throwable) {}
        }

        return response()->json($response);
    }
}
