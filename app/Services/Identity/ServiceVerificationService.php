<?php

namespace App\Services\Identity;

use App\Models\Identity\FirstResponderVerification;
use App\Models\Identity\User;
use App\Models\Identity\VeteranVerification;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Audit\AuditService;
use App\Services\Billing\BillingService;
use App\Services\Platform\TenantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Drives veteran / first-responder service-status verification: creating pending
 * records, and approving/rejecting them. Veteran and First Responder live in
 * parallel tables (veteran_verifications / first_responder_verifications) but
 * share one lifecycle, so this service handles both, branching on `$type`.
 *
 * The verification *method* (manual upload vs ID.me) and the promotion granted
 * on approval are both DB-12 settings, so they can be reconfigured from admin
 * with no deploy. On approval we flip the user flag and apply the configured
 * promotion (which itself audits + invalidates the entitlement cache).
 */
class ServiceVerificationService
{
    public const TYPE_VETERAN         = 'veteran';
    public const TYPE_FIRST_RESPONDER = 'first_responder';

    /** The upload (non-ID.me) method recorded per type. */
    private const UPLOAD_METHOD = [
        self::TYPE_VETERAN         => 'dd214_upload',
        self::TYPE_FIRST_RESPONDER => 'credential_upload',
    ];

    private const FLAG = [
        self::TYPE_VETERAN         => 'is_veteran',
        self::TYPE_FIRST_RESPONDER => 'is_first_responder',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly BillingService $billing,
        private readonly TenantService $tenant,
    ) {}

    /**
     * The configured verification method for a type: 'manual' | 'id_me' | 'both'.
     * Defaults to 'manual' so verification works with no third-party integration.
     */
    public function methodFor(string $type): string
    {
        return (string) $this->tenant->getSetting("verification.{$type}.method", 'manual');
    }

    /** The record-level method value to use for a manual (upload) submission. */
    public function uploadMethodFor(string $type): string
    {
        return self::UPLOAD_METHOD[$type];
    }

    /**
     * Open a pending verification for a user. `$method` is the record-level value
     * ('id_me' or the type's upload method); `$documentId` is the uploaded proof
     * (DB 11) for the manual path, null for ID.me.
     */
    public function createPending(User $user, string $type, string $method, ?string $documentId = null): Model
    {
        $record = $this->newModel($type)->newInstance([
            'user_id'     => $user->id,
            'method'      => $method,
            'status'      => 'pending',
            'document_id' => $documentId,
        ]);
        $record->save();

        $this->audit->log(
            eventType:      "{$type}.verification_submitted",
            sourceDatabase: 'ah_identity',
            tableName:      $record->getTable(),
            recordId:       $record->id,
            userId:         $user->id,
            actionSummary:  ucfirst(str_replace('_', ' ', $type)) . " verification submitted ({$method})",
        );

        return $record;
    }

    /**
     * Approve a pending verification: mark it approved, flip the user's status
     * flag, and apply the configured promotion (if it exists, is active, and
     * targets this user's account type). Granting the promotion invalidates the
     * entitlement cache; flag-only approvals don't change entitlements.
     */
    public function approve(Model $record, ?string $reviewerId = null): void
    {
        $type = $this->typeOf($record);

        $record->status              = 'approved';
        $record->verified_at         = now();
        $record->reviewed_by_user_id = $reviewerId;
        $record->save();

        $user = User::on('identity')->find($record->user_id);
        if ($user) {
            $user->{self::FLAG[$type]} = true;
            $user->save();

            $this->grantPromotion($user, $type, $reviewerId);
        }

        $this->audit->log(
            eventType:      "{$type}.verified",
            sourceDatabase: 'ah_identity',
            tableName:      $record->getTable(),
            recordId:       $record->id,
            userId:         $record->user_id,
            actionSummary:  ucfirst(str_replace('_', ' ', $type)) . ' verification approved',
        );
    }

    /** Reject a pending verification. The user's status flag is left untouched. */
    public function reject(Model $record, ?string $reviewerId = null): void
    {
        $type = $this->typeOf($record);

        $record->status              = 'rejected';
        $record->reviewed_by_user_id = $reviewerId;
        $record->save();

        $this->audit->log(
            eventType:      "{$type}.verification_rejected",
            sourceDatabase: 'ah_identity',
            tableName:      $record->getTable(),
            recordId:       $record->id,
            userId:         $record->user_id,
            actionSummary:  ucfirst(str_replace('_', ' ', $type)) . ' verification rejected',
        );
    }

    /**
     * Apply the configured benefit promotion for this verification type. Skips
     * silently (logging) if no promo is configured, it isn't active, or it
     * doesn't target the user's account type — approval still succeeds.
     */
    private function grantPromotion(User $user, string $type, ?string $reviewerId): void
    {
        $promoKey = (string) $this->tenant->getSetting("verification.{$type}.promo_key", '');
        if ($promoKey === '') {
            return;
        }

        $promo = PromotionalPeriod::on('platform')->where('promo_key', $promoKey)->first();

        if (! $promo || ! $promo->isActive()) {
            Log::info('ServiceVerificationService: no active promo to grant', [
                'type' => $type, 'promo_key' => $promoKey, 'user_id' => $user->id,
            ]);
            return;
        }

        // Don't grant a promo that targets a different account type (e.g. the
        // hunter-only veteran grant must not hand a hunter plan to a landowner).
        $targets = $promo->target_account_types ?? [];
        if (! empty($targets) && ! in_array($user->account_type, $targets, true)) {
            Log::info('ServiceVerificationService: promo does not target this account type', [
                'type' => $type, 'promo_key' => $promoKey,
                'account_type' => $user->account_type, 'user_id' => $user->id,
            ]);
            return;
        }

        $this->billing->applyPromotion($user, $promo, [
            'trigger_event'      => "{$type}_verification",
            'applied_by_user_id' => $reviewerId,
        ]);
    }

    private function newModel(string $type): VeteranVerification|FirstResponderVerification
    {
        return $type === self::TYPE_VETERAN
            ? new VeteranVerification()
            : new FirstResponderVerification();
    }

    private function typeOf(Model $record): string
    {
        return $record instanceof VeteranVerification
            ? self::TYPE_VETERAN
            : self::TYPE_FIRST_RESPONDER;
    }
}
