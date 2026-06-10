<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

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
        if (! in_array($method, ['totp', 'sms', 'email'])) {
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
}
