<?php

namespace App\Jobs\Billing;

use App\Mail\PromotionExpiringMail;
use App\Models\Billing\PromotionClaim;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Billing\PromotionExpirationService;
use App\Services\Identity\UserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily promotion-claim maintenance (Phase 5.4 Slice 2). Two passes over the
 * active claims:
 *
 *   1. Warning emails — at 30 / 7 / 1 day before expiry. Each window is sent at
 *      most once (guarded by the reminder_*_sent_at columns), and stamping a
 *      nearer window backfills the further ones so a short promo never sends an
 *      out-of-order "30 days" notice after a "7 days" one.
 *   2. Expiry — claims past expires_at are handed to PromotionExpirationService,
 *      which branches on the promo's on_expiration mode (downgrade / auto-charge /
 *      pause). Transitioning the claim off 'active' makes the pass idempotent.
 *
 * Runs on the default queue under ah_system (the queue worker). Per-claim work is
 * isolated in try/catch so one bad record never aborts the batch.
 */
class ExpirePromotionClaims implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PromotionExpirationService $expiration, UserService $users): void
    {
        $now = now();

        $this->sendReminders($now, $users);
        $this->processExpired($now, $expiration);
    }

    private function sendReminders(Carbon $now, UserService $users): void
    {
        $claims = PromotionClaim::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->copy()->addDays(30))
            ->get();

        foreach ($claims as $claim) {
            try {
                $daysLeft = max(1, (int) ceil($now->diffInHours($claim->expires_at) / 24));

                if ($daysLeft <= 1 && $claim->reminder_1d_sent_at === null) {
                    $this->dispatchReminder($claim, $daysLeft, $users);
                    $this->stamp($claim, ['reminder_1d_sent_at', 'reminder_7d_sent_at', 'reminder_30d_sent_at'], $now);
                } elseif ($daysLeft <= 7 && $claim->reminder_7d_sent_at === null) {
                    $this->dispatchReminder($claim, $daysLeft, $users);
                    $this->stamp($claim, ['reminder_7d_sent_at', 'reminder_30d_sent_at'], $now);
                } elseif ($daysLeft <= 30 && $claim->reminder_30d_sent_at === null) {
                    $this->dispatchReminder($claim, $daysLeft, $users);
                    $this->stamp($claim, ['reminder_30d_sent_at'], $now);
                }
            } catch (\Throwable $e) {
                Log::warning('ExpirePromotionClaims: reminder failed', ['claim_id' => $claim->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function processExpired(Carbon $now, PromotionExpirationService $expiration): void
    {
        $claims = PromotionClaim::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($claims as $claim) {
            try {
                $expiration->expire($claim);
            } catch (\Throwable $e) {
                Log::error('ExpirePromotionClaims: expiry failed', ['claim_id' => $claim->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function dispatchReminder(PromotionClaim $claim, int $daysLeft, UserService $users): void
    {
        $user = $users->findById($claim->user_id);
        if (! $user || ! $user->email) {
            return;
        }

        $whenLabel = $daysLeft <= 1 ? 'tomorrow' : "in {$daysLeft} days";

        Mail::to($user->email)->send(new PromotionExpiringMail(
            recipientName: $user->getFilamentName(),
            whenLabel:     $whenLabel,
            statusMessage: $this->message($claim, $whenLabel),
            manageUrl:     url('/member'),
        ));
    }

    /** Window-specific copy reflecting what happens to the member at expiry. */
    private function message(PromotionClaim $claim, string $whenLabel): string
    {
        $promo    = PromotionalPeriod::on('platform')->find($claim->promotion_period_id);
        $plan     = $claim->granted_plan_id ? MembershipPlan::on('platform')->find($claim->granted_plan_id) : null;
        $planName = $plan?->display_name ?? 'your membership';
        $mode     = $promo?->on_expiration ?? 'downgrade_free';

        return match ($mode) {
            'auto_charge'   => "Your {$planName} promotional period ends {$whenLabel}. We'll then start your paid {$planName} subscription automatically using your payment method on file — no action needed unless you'd like to change plans.",
            'pause_account' => "Your {$planName} promotional period ends {$whenLabel}. To keep your account active, start a paid subscription before then — otherwise your account will be paused until you subscribe.",
            default         => "Your {$planName} promotional period ends {$whenLabel}. After that you'll move to the free plan. Subscribe any time to keep your {$planName} features.",
        };
    }

    /** @param list<string> $columns */
    private function stamp(PromotionClaim $claim, array $columns, Carbon $now): void
    {
        foreach ($columns as $column) {
            if ($claim->{$column} === null) {
                $claim->{$column} = $now;
            }
        }
        $claim->save();
    }
}
