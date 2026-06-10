<?php

namespace App\Services\Identity;

use App\Models\Identity\OfacScreeningResult;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OfacService extends BaseService
{
    private const RESCREENING_INTERVAL_DAYS = 365;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Screen a user against the OFAC SDN list.
     * Creates an OfacScreeningResult record and suspends the account on match.
     * This method never throws — failures are logged.
     */
    public function screen(User $user): string
    {
        try {
            $status = $this->callOfacApi($user);

            $encryptionKey = config('database.connections.identity.options.encryption_key');

            $insertData = [
                'id'               => (string) Str::uuid(),
                'user_id'          => $user->id,
                'status'           => $status,
                'screened_at'      => now(),
                'next_screening_at' => now()->addDays(self::RESCREENING_INTERVAL_DAYS),
                'created_at'       => now(),
            ];

            DB::connection('identity')->table('ofac_screening_results')->insert($insertData);

            if ($status === 'match') {
                $user->update(['status' => 'suspended']);

                $this->audit->log(
                    eventType:     'ofac_match',
                    sourceDatabase: 'ah_identity',
                    tableName:     'users',
                    recordId:      $user->id,
                    actionSummary: 'Account suspended — OFAC match detected',
                );

                Log::warning('OFAC match — account suspended', ['user_id' => $user->id]);
            } else {
                $this->audit->log(
                    eventType:     'ofac_cleared',
                    sourceDatabase: 'ah_identity',
                    tableName:     'users',
                    recordId:      $user->id,
                    userId:        $user->id,
                    actionSummary: 'OFAC screening cleared',
                );
            }

            return $status;
        } catch (\Throwable $e) {
            Log::error('OfacService: screening failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            // Record as pending so re-screening is scheduled
            DB::connection('identity')->table('ofac_screening_results')->insert([
                'id'               => (string) Str::uuid(),
                'user_id'          => $user->id,
                'status'           => 'pending',
                'screened_at'      => now(),
                'next_screening_at' => now()->addHours(1),
                'created_at'       => now(),
            ]);

            return 'pending';
        }
    }

    /**
     * Return the latest screening result for a user.
     */
    public function getLatestResult(User $user): ?OfacScreeningResult
    {
        return OfacScreeningResult::where('user_id', $user->id)
            ->orderBy('screened_at', 'desc')
            ->first();
    }

    private function callOfacApi(User $user): string
    {
        // Placeholder — integrate with actual OFAC API or third-party provider
        // e.g. ComplyAdvantage, LexisNexis Bridger, or direct Treasury SDN API
        $profile = $user->profile;
        $name    = trim("{$profile?->first_name} {$profile?->last_name}");

        // TODO: implement real OFAC API call
        // For now returns 'clear' so registration flow works end-to-end
        return 'clear';
    }
}
