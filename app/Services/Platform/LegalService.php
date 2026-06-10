<?php

namespace App\Services\Platform;

use App\Models\Identity\UserLegalAcceptance;
use App\Models\Platform\LegalDocument;
use App\Services\BaseService;
use Illuminate\Http\Request;

class LegalService extends BaseService
{
    public function getActiveCertification(): ?LegalDocument
    {
        return LegalDocument::getActive('hunter_info_certification');
    }

    public function getActive(string $key): ?LegalDocument
    {
        return LegalDocument::getActive($key);
    }

    /**
     * Record that a user accepted a specific legal document version.
     * Safe to call inside a transaction — failures are silently swallowed.
     */
    public function recordAcceptance(
        string  $userId,
        string  $documentKey,
        int     $documentVersion,
        Request $request,
        string  $contextType = 'lease_application',
        ?string $contextId = null,
    ): void {
        try {
            UserLegalAcceptance::create([
                'user_id'          => $userId,
                'document_key'     => $documentKey,
                'document_version' => $documentVersion,
                'accepted_at'      => now(),
                'ip_address'       => $request->ip(),
                'user_agent'       => substr($request->userAgent() ?? '', 0, 500),
                'context_type'     => $contextType,
                'context_id'       => $contextId,
            ]);
        } catch (\Throwable) {
            // Never fail the caller — acceptance recording is best-effort
        }
    }

    public function getAcceptancesForUser(string $userId): \Illuminate\Support\Collection
    {
        return UserLegalAcceptance::where('user_id', $userId)
            ->orderByDesc('accepted_at')
            ->get();
    }
}
