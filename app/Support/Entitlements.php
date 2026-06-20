<?php

namespace App\Support;

/**
 * Feature key constants for use with EntitlementService.
 *
 * Usage:
 *   $entitlements->can($user, Entitlements::TRAIL_CAMERA_INTEGRATION)
 *   $entitlements->limit($user, Entitlements::SAVED_SEARCHES_LIMIT)
 *
 * These constants mirror the feature_key values in the feature_entitlements table (DB 12).
 * When adding a new feature, add the constant here AND seed it in a new plan_versions migration.
 */
final class Entitlements
{
    // Hunter entitlements
    const SAVED_SEARCHES_LIMIT          = 'saved_searches_limit';
    const LEASE_APPLICATIONS_PER_SEASON = 'lease_applications_per_season';
    const TRAIL_CAMERA_INTEGRATION      = 'trail_camera_integration';
    const DIGITAL_ID_CARD               = 'digital_id_card';
    const BACKGROUND_CHECKS_PER_YEAR    = 'background_checks_per_year';
    const EARLY_LISTING_ACCESS_HOURS    = 'early_listing_access_hours';
    const TRUST_BADGE_LEVEL             = 'trust_badge_level';
    const CONCIERGE_MESSAGING           = 'concierge_messaging';
    const SINGLE_STATE_HUNT             = 'single_state_hunt';
    const MULTI_STATE_HUNT              = 'multi_state_hunt';

    // Landowner entitlements
    const CUSTOM_LEASE_TEMPLATE              = 'custom_lease_template';
    const MAX_ACTIVE_LISTINGS                = 'max_active_listings';
    const PHOTO_UPLOADS_PER_LISTING          = 'photo_uploads_per_listing';
    const VIDEO_UPLOADS_PER_LISTING          = 'video_uploads_per_listing';
    const SEARCH_PLACEMENT                   = 'search_placement';
    const ADVANCED_ANALYTICS                 = 'advanced_analytics';
    const BACKGROUND_CHECK_CREDITS_PER_YEAR  = 'background_check_credits_per_year';
    const DEDICATED_SUPPORT                  = 'dedicated_support';
    const API_ACCESS                         = 'api_access';

    // Club entitlements
    const SHARED_CALENDAR      = 'shared_calendar';
    const STAND_ASSIGNMENT     = 'stand_assignment';
    const EXPENSE_SPLITTING    = 'expense_splitting';
    const MEMBER_VOTING        = 'member_voting';
    const MEMBER_ANNOUNCEMENTS = 'member_announcements';
    const SHARED_TRAIL_CAMS    = 'shared_trail_cams';
    const GUEST_PASS_TIER      = 'guest_pass_tier';

    /**
     * Entitlement implications: granting a key automatically grants the keys it
     * maps to, without seeding both on every plan. Resolution is transitive
     * (A => B and B => C means A grants both B and C) and applies only to
     * boolean "enabled" features. Extend this map as cross-feature dependencies
     * emerge; keys on either side should be constants defined above.
     *
     * @var array<string, string[]>
     */
    const IMPLIES = [
        // A club with shared trail cameras necessarily has trail-camera integration.
        self::SHARED_TRAIL_CAMS => [self::TRAIL_CAMERA_INTEGRATION],
    ];

    /**
     * The canonical catalog of entitlements the platform offers. This is the
     * single source of truth for the admin "Entitlement" picker, so admins can
     * only attach keys the application understands (no free-text typos that
     * silently do nothing). Each entry: human label, value type, and the
     * account-type group it belongs to.
     *
     * To offer a NEW capability: add its constant above, add it here, then wire
     * the gate in code (EntitlementService::can/limit/value) — only then does it
     * actually do anything.
     *
     * @var array<string, array{label: string, type: string, group: string}>
     */
    const DEFINITIONS = [
        // Hunter
        self::SAVED_SEARCHES_LIMIT          => ['label' => 'Saved searches limit',          'type' => 'integer', 'group' => 'Hunter'],
        self::LEASE_APPLICATIONS_PER_SEASON => ['label' => 'Lease applications per season',  'type' => 'integer', 'group' => 'Hunter'],
        self::TRAIL_CAMERA_INTEGRATION      => ['label' => 'Trail camera integration',       'type' => 'boolean', 'group' => 'Hunter'],
        self::DIGITAL_ID_CARD               => ['label' => 'Digital hunter ID card',          'type' => 'boolean', 'group' => 'Hunter'],
        self::BACKGROUND_CHECKS_PER_YEAR    => ['label' => 'Background checks per year',      'type' => 'integer', 'group' => 'Hunter'],
        self::EARLY_LISTING_ACCESS_HOURS    => ['label' => 'Early listing access (hours)',    'type' => 'integer', 'group' => 'Hunter'],
        self::TRUST_BADGE_LEVEL             => ['label' => 'Trust badge level',               'type' => 'string',  'group' => 'Hunter'],
        self::CONCIERGE_MESSAGING           => ['label' => 'Concierge messaging',             'type' => 'boolean', 'group' => 'Hunter'],
        self::SINGLE_STATE_HUNT             => ['label' => 'Single-state hunting only',       'type' => 'boolean', 'group' => 'Hunter'],
        self::MULTI_STATE_HUNT              => ['label' => 'Multi-state hunting (any state)',  'type' => 'boolean', 'group' => 'Hunter'],

        // Landowner
        self::CUSTOM_LEASE_TEMPLATE             => ['label' => 'Custom lease template',             'type' => 'boolean', 'group' => 'Landowner'],
        self::MAX_ACTIVE_LISTINGS               => ['label' => 'Max active listings',               'type' => 'integer', 'group' => 'Landowner'],
        self::PHOTO_UPLOADS_PER_LISTING         => ['label' => 'Photo uploads per listing',         'type' => 'integer', 'group' => 'Landowner'],
        self::VIDEO_UPLOADS_PER_LISTING         => ['label' => 'Video uploads per listing',         'type' => 'integer', 'group' => 'Landowner'],
        self::SEARCH_PLACEMENT                  => ['label' => 'Search placement',                  'type' => 'string',  'group' => 'Landowner'],
        self::ADVANCED_ANALYTICS                => ['label' => 'Advanced analytics',                'type' => 'boolean', 'group' => 'Landowner'],
        self::BACKGROUND_CHECK_CREDITS_PER_YEAR => ['label' => 'Background check credits per year',  'type' => 'integer', 'group' => 'Landowner'],
        self::DEDICATED_SUPPORT                 => ['label' => 'Dedicated support',                 'type' => 'boolean', 'group' => 'Landowner'],
        self::API_ACCESS                        => ['label' => 'API access',                        'type' => 'boolean', 'group' => 'Landowner'],

        // Club
        self::SHARED_CALENDAR      => ['label' => 'Shared hunt calendar',       'type' => 'boolean', 'group' => 'Club'],
        self::STAND_ASSIGNMENT     => ['label' => 'Stand assignment tools',     'type' => 'boolean', 'group' => 'Club'],
        self::EXPENSE_SPLITTING    => ['label' => 'Expense splitting',          'type' => 'boolean', 'group' => 'Club'],
        self::MEMBER_VOTING        => ['label' => 'Member voting',              'type' => 'boolean', 'group' => 'Club'],
        self::MEMBER_ANNOUNCEMENTS => ['label' => 'Member announcements',       'type' => 'boolean', 'group' => 'Club'],
        self::SHARED_TRAIL_CAMS    => ['label' => 'Shared trail camera access', 'type' => 'boolean', 'group' => 'Club'],
        self::GUEST_PASS_TIER      => ['label' => 'Guest pass tier',            'type' => 'string',  'group' => 'Club'],
    ];

    /**
     * Grouped <optgroup>-style options for a Filament Select, keyed by group then
     * feature_key. Pass $exclude to hide keys already attached to a plan.
     *
     * @param  string[]  $exclude
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(array $exclude = []): array
    {
        $options = [];

        foreach (self::DEFINITIONS as $key => $def) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $options[$def['group']][$key] = $def['label'];
        }

        return $options;
    }

    /**
     * The canonical value type for a catalog key, or null if uncatalogued.
     */
    public static function typeFor(string $key): ?string
    {
        return self::DEFINITIONS[$key]['type'] ?? null;
    }

    private function __construct() {}
}
