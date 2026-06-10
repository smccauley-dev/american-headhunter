<?php

namespace App\Services\Platform;

use App\Models\Platform\MfaFactorSetting;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;

class MfaFactorService extends BaseService
{
    /**
     * Whether this MFA factor is open for new enrollment.
     * Cached for 5 min; invalidate after any admin toggle.
     */
    public function isFactorEnabled(string $factor): bool
    {
        return $this->cache("mfa_factor_enabled:{$factor}", function () use ($factor) {
            $row = MfaFactorSetting::where('factor', $factor)->first();
            return $row?->is_enabled ?? false;
        }, ttlMinutes: 5);
    }

    public function getAll(): Collection
    {
        return MfaFactorSetting::orderBy('factor')->get();
    }

    public function invalidateFactor(string $factor): void
    {
        $this->invalidate("mfa_factor_enabled:{$factor}");
    }
}
