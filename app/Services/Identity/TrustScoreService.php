<?php

namespace App\Services\Identity;

use App\Models\Identity\TrustScoreEvent;
use App\Models\Identity\User;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

class TrustScoreService extends BaseService
{
    private const DELTAS = [
        'background_check_passed'          =>  10,
        'background_check_failed'          => -15,
        'lease_completed'                  =>   5,
        'lease_terminated_early'           => -10,
        'dispute_raised'                   =>  -5,
        'dispute_resolved_for_user'        =>   5,
        'dispute_resolved_against_user'    => -10,
        'verified_landowner'               =>  10,
        'email_verified'                   =>   5,
        'phone_verified'                   =>   5,
        'id_verified'                      =>  10,
        'ofac_cleared'                     =>   5,
        'ofac_match'                       => -100,
        'positive_review'                  =>   3,
        'negative_review'                  =>  -5,
        'account_suspended'                => -50,
    ];

    /**
     * Record a trust score event and atomically update users.trust_score.
     * Clamps the resulting score between 0 and 100.
     */
    public function record(User $user, string $eventType, ?array $metadata = null, ?int $overrideDelta = null): void
    {
        $delta = $overrideDelta ?? self::DELTAS[$eventType] ?? 0;

        DB::connection('identity')->transaction(function () use ($user, $eventType, $delta, $metadata) {
            // Clamp: score must stay between 0 and 100
            $newScore = DB::connection('identity')
                ->selectOne(
                    'UPDATE users SET trust_score = GREATEST(0, LEAST(100, trust_score + ?)) WHERE id = ? RETURNING trust_score',
                    [$delta, $user->id]
                )?->trust_score ?? $user->trust_score;

            TrustScoreEvent::create([
                'user_id'    => $user->id,
                'event_type' => $eventType,
                'delta'      => $delta,
                'score_after' => $newScore,
                'metadata'   => $metadata ?? [],
            ]);

            // Invalidate the user cache so the new score is reflected immediately
            $this->invalidate("user:{$user->id}");
        });
    }
}
