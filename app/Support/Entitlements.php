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

    private function __construct() {}
}
