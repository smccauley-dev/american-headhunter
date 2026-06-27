<?php

namespace App\Services\Billing;

use App\Models\Billing\FeeSchedule;
use App\Services\BaseService;

/**
 * Resolves the customer-facing processing-fee surcharge for a transaction (DB 4
 * fee_schedules). The surcharge recovers Stripe's processing cost on a charge;
 * it is distinct from the tier-based platform fee (plan_versions.platform_fee_pct)
 * which deducts from the landowner's net.
 *
 * Rules are looked up by transaction category and (optionally) state — the
 * most-specific active, in-window rule wins (an exact state beats the all-states
 * rule). Resolutions are cached in Valkey Cluster 2; FeeScheduleResource flushes
 * the cache on any admin mutation.
 */
class FeeService extends BaseService
{
    /**
     * The processing-fee surcharge for a transaction. `fee_cents` is
     * round(base × pct%) + flat. Returns a zero fee when no rule applies.
     *
     * @return array{schedule_id:?string, pct:float, flat_cents:int, fee_cents:int, payer:string}
     */
    public function processingFee(string $category, ?string $stateCode, int $baseCents): array
    {
        $rule = $this->resolveRule($category, $stateCode);

        if ($rule === null) {
            return ['schedule_id' => null, 'pct' => 0.0, 'flat_cents' => 0, 'fee_cents' => 0, 'payer' => 'customer'];
        }

        $pct      = (float) ($rule['pct'] ?? 0.0);
        $flat     = (int) ($rule['flat_cents'] ?? 0);
        $feeCents = (int) round(max(0, $baseCents) * $pct / 100) + $flat;

        return [
            'schedule_id' => $rule['id'],
            'pct'         => $pct,
            'flat_cents'  => $flat,
            'fee_cents'   => $feeCents,
            'payer'       => $rule['payer'],
        ];
    }

    /**
     * The active, in-window fee rule for a transaction, most-specific first: a row
     * with an exact state_code beats an all-states (NULL state) row. Cached per
     * (category, state); a "no rule" result is not cached (cheap indexed lookup).
     *
     * @return array{id:string, pct:?float, flat_cents:?int, payer:string}|null
     */
    private function resolveRule(string $category, ?string $stateCode): ?array
    {
        $state = $stateCode ? strtoupper($stateCode) : null;
        $key   = "fee_schedule:{$category}:" . ($state ?? 'all');

        return $this->cache($key, function () use ($category, $state) {
            // Bind with microsecond precision: TIMESTAMPTZ columns store microseconds,
            // but a Carbon bound truncates to whole seconds, which would exclude a
            // rule whose effective_from is NOW() to the same second it is queried.
            $now = now()->format('Y-m-d H:i:s.u');

            $candidates = FeeSchedule::query()
                ->where('transaction_category', $category)
                ->where('is_active', true)
                ->where('effective_from', '<=', $now)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
                ->when(
                    $state !== null,
                    fn ($q) => $q->where(fn ($w) => $w->where('state_code', $state)->orWhereNull('state_code')),
                    fn ($q) => $q->whereNull('state_code'),
                )
                ->get();

            $best = $candidates
                ->sortByDesc(fn (FeeSchedule $r) => $r->state_code !== null ? 1 : 0)
                ->first();

            return $best === null ? null : [
                'id'         => $best->id,
                'pct'        => $best->pct,
                'flat_cents' => $best->flat_cents,
                'payer'      => $best->payer,
            ];
        }, 30);
    }

    /** Drop all cached fee-rule resolutions — call after any admin mutation. */
    public function flushCache(): void
    {
        $this->invalidatePattern('*fee_schedule:*');
    }
}
