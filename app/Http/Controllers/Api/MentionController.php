<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use Illuminate\Http\JsonResponse;

class MentionController extends Controller
{
    public function show(string $username): JsonResponse
    {
        $user = User::where('username', strtolower($username))
            ->whereNull('deleted_at')
            ->first();

        if (! $user || ! $user->is_profile_public) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $profile     = $user->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''))
            ?: $username;

        $first = $profile?->first_name ?? '';
        $last  = $profile?->last_name  ?? '';
        $initials = strtoupper(($first[0] ?? '') . ($last[0] ?? ''))
            ?: strtoupper($displayName[0] ?? '?');

        return response()->json([
            'username'     => $user->username,
            'display_name' => $displayName,
            'initials'     => $initials,
            'account_type' => $user->account_type,
            'trust_score'  => $user->trust_score,
            'state_code'   => $profile?->state_code,
            'is_veteran'   => $user->is_veteran,
            'is_first_responder' => $user->is_first_responder,
            'profile_url'  => url('/hunters/' . $user->username),
        ]);
    }
}
