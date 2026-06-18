<?php

namespace App\Support;

use App\Models\Identity\User;

/**
 * Semantic role-check helpers for Filament canAccess() gates.
 * All methods return false when unauthenticated.
 */
class AdminAuth
{
    public static function user(): ?User
    {
        /** @var User|null */
        return auth()->user();
    }

    public static function hasAnyRole(string ...$roles): bool
    {
        return static::user()?->hasAnyRole(...$roles) ?? false;
    }

    // ── Semantic gates ────────────────────────────────────────────────────────

    public static function isSuperAdmin(): bool
    {
        return static::hasAnyRole('super_admin');
    }

    /** Properties, amenities, listings */
    public static function canManageProperties(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin', 'property_admin');
    }

    /** Admin user management, security settings, audit log */
    public static function canManageSecurity(): bool
    {
        return static::hasAnyRole('super_admin', 'security_admin');
    }

    /** Homepage, navigation, and platform-wide CMS content */
    public static function canManagePlatformContent(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin');
    }

    /** Articles, reviews, editorial content */
    public static function canManageArticles(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin', 'article_admin');
    }

    /** Lease applications, lease oversight */
    public static function canManageLeases(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin', 'property_admin', 'staff');
    }

    /** Platform user management — hunters, landowners, club, outfitter, consultant, seller accounts */
    public static function canManageUsers(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin', 'security_admin');
    }

    /** Membership plans, plan versions, entitlements, promotions, promo codes */
    public static function canManagePricing(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin');
    }

    /** Billing oversight — read-only invoices, payments, payouts */
    public static function canViewBilling(): bool
    {
        return static::hasAnyRole('super_admin', 'global_admin', 'security_admin');
    }

    /** Feature flags, system configuration — super_admin only */
    public static function canManageSystem(): bool
    {
        return static::isSuperAdmin();
    }
}
