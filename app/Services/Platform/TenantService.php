<?php

namespace App\Services\Platform;

use App\Models\Platform\TenantSettings;
use App\Services\BaseService;

class TenantService extends BaseService
{
    /**
     * Get a platform tenant setting by key.
     * Returns the decoded JSON value, or $default if the key does not exist.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $cached = $this->cache("cfg:platform:{$key}", function () use ($key, $default) {
            $row = TenantSettings::on('platform')->where('key', $key)->first();
            return $row ? $row->value : $default;
        }, ttlMinutes: 60);

        return $cached ?? $default;
    }

    /**
     * Set a tenant setting. Upserts the row and invalidates the cache.
     */
    public function setSetting(string $key, mixed $value, ?string $description = null): void
    {
        TenantSettings::on('platform')->updateOrCreate(
            ['key' => $key],
            array_filter([
                'value'       => $value,
                'description' => $description,
            ], fn($v) => ! is_null($v))
        );

        $this->invalidate("cfg:platform:{$key}");
    }

    /**
     * Convenience wrappers for common settings.
     */
    public function platformName(): string
    {
        return $this->getSetting('platform.name', 'American Headhunter');
    }

    public function supportEmail(): string
    {
        return $this->getSetting('platform.support_email', 'support@americanheadhunter.com');
    }

    public function sosPhone(): string
    {
        return $this->getSetting('platform.sos_phone', '+18005550000');
    }

    public function billingCurrency(): string
    {
        return $this->getSetting('billing.currency', 'USD');
    }

    public function foundingLandownerSlots(): int
    {
        return (int) $this->getSetting('billing.founding_landowner_slots', 500);
    }
}
