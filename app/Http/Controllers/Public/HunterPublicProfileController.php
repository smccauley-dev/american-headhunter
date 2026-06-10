<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use Inertia\Inertia;
use Inertia\Response;

class HunterPublicProfileController extends Controller
{
    public function show(string $username): Response
    {
        $user = User::where('username', $username)->first();

        if (! $user) {
            abort(404);
        }

        if (! $user->is_profile_public) {
            return Inertia::render('Public/HunterPublicProfile', [
                'username'  => $username,
                'is_public' => false,
            ]);
        }

        $profile = $user->profile;
        $hunting = $profile?->hunting_profile ?? [];
        $vis     = $profile?->profile_visibility ?? [];

        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''))
            ?: $username;

        return Inertia::render('Public/HunterPublicProfile', [
            'username'     => $username,
            'is_public'    => true,
            'display_name' => $displayName,
            'initials'     => $this->initials($profile?->first_name, $profile?->last_name, $displayName),
            'member_since' => $user->created_at?->format('F Y'),
            'state_code'   => $profile?->state_code,
            'trust_score'  => $user->trust_score,
            'is_veteran'         => $user->is_veteran,
            'veteran_branch'     => $profile?->veteran_branch,
            'is_first_responder' => $user->is_first_responder,
            // Bio + species respect their tab visibility settings
            'bio'     => ($vis['about'] ?? 'public') === 'public' ? ($profile?->bio ?: null) : null,
            'species' => ($vis['about'] ?? 'public') === 'public' ? ($hunting['species'] ?? []) : [],
        ]);
    }

    private function initials(?string $first, ?string $last, string $fallback): string
    {
        $i = strtoupper(($first[0] ?? '') . ($last[0] ?? ''));
        return $i ?: strtoupper($fallback[0] ?? '?');
    }
}
