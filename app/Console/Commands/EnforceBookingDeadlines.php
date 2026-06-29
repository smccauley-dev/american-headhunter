<?php

namespace App\Console\Commands;

use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Services\Billing\BookingDepositService;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\LeaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Enforce the two vet-first booking-fee deadlines:
 *
 *  1. 24-hour booking window — an approved applicant who never paid the booking
 *     fee has their application closed ("Booking Fee was not paid"), freeing the
 *     listing. Applications with any booking-fee row are skipped (the applicant
 *     paid — they either won, or already lost and were refunded).
 *
 *  2. 7-day completion window — a winner who paid the booking fee but never
 *     finished signing + paying the lease balance forfeits the held fee to the
 *     landowner (minus platform fees) and the lease is cancelled, releasing the
 *     reservation so the listing can be re-let.
 *
 * Console commands run as ah_system (BYPASSRLS), so the RLS-protected
 * lease_applications / leases / booking_deposits writes succeed without a
 * per-user context.
 */
class EnforceBookingDeadlines extends Command
{
    protected $signature   = 'booking:enforce-deadlines {--dry-run : Report what would change without writing}';
    protected $description = 'Close unpaid 24h booking windows and forfeit/cancel leases whose 7-day completion window lapsed.';

    public function handle(
        ApplicationService    $applications,
        BookingDepositService $deposits,
        LeaseService          $leases,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $now    = now();

        $closed    = $this->closeUnpaidBookingWindows($applications, $deposits, $now, $dryRun);
        $forfeited = $this->forfeitLapsedCompletions($deposits, $leases, $now, $dryRun);

        $verb = $dryRun ? 'Would close' : 'Closed';
        $this->info("{$verb} {$closed} unpaid booking window(s); " . ($dryRun ? 'would forfeit' : 'forfeited') . " {$forfeited} lapsed lease(s).");

        return self::SUCCESS;
    }

    /** Close approved applications whose 24h booking-fee window lapsed without payment. */
    private function closeUnpaidBookingWindows(ApplicationService $applications, BookingDepositService $deposits, \Illuminate\Support\Carbon $now, bool $dryRun): int
    {
        $stale = LeaseApplication::on('lease')
            ->where('status', 'approved')
            ->whereNotNull('booking_fee_deadline')
            ->where('booking_fee_deadline', '<', $now)
            ->whereNull('deleted_at')
            ->get();

        $count = 0;
        foreach ($stale as $application) {
            // A booking-fee row of any status means the applicant paid — they won
            // (completion clock governs) or already lost and were refunded. Either
            // way it is not an unpaid-window closure.
            if ($deposits->forApplication($application->id) !== null) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] application={$application->id} → close (booking fee not paid)");
                $count++;
                continue;
            }

            try {
                $applications->closeForUnpaidBookingFee($application->id);
                $count++;
            } catch (\Throwable $e) {
                Log::error('booking:enforce-deadlines — failed to close unpaid application', [
                    'application_id' => $application->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /** Forfeit + cancel leases whose 7-day completion window lapsed while still awaiting signing/payment. */
    private function forfeitLapsedCompletions(BookingDepositService $deposits, LeaseService $leases, \Illuminate\Support\Carbon $now, bool $dryRun): int
    {
        $stale = Lease::on('lease')
            ->whereIn('status', ['pending_signatures', 'pending_payment'])
            ->whereNotNull('completion_deadline')
            ->where('completion_deadline', '<', $now)
            ->whereNull('deleted_at')
            ->get();

        $count = 0;
        foreach ($stale as $lease) {
            if ($dryRun) {
                $this->line("  [dry-run] lease={$lease->id} → forfeit booking fee + cancel (completion window lapsed)");
                $count++;
                continue;
            }

            try {
                // Forfeit first (routes the held fee to the landowner), then cancel
                // the lease — which releases any reservation so the listing re-opens.
                $deposits->forfeitForLease($lease->id);
                $leases->cancel(
                    $lease->id,
                    'Booking not completed within 7 days — booking fee forfeited to the landowner.',
                );
                $count++;
            } catch (\Throwable $e) {
                Log::error('booking:enforce-deadlines — failed to forfeit/cancel lapsed lease', [
                    'lease_id' => $lease->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
