<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Models\Identity\LoginHistory;
use App\Models\Identity\MfaConfiguration;
use App\Models\Identity\User;
use App\Models\Identity\UserProfile;
use App\Models\Billing\StripeInvoiceProjection;
use App\Models\Lease\CheckIn;
use App\Models\Wildlife\HarvestLog;
use App\Services\Documents\DocumentService;
use App\Services\Billing\PayoutService;
use App\Services\Lease\LeaseService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\ProfileTemplateService;
use App\Services\Property\PropertyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function show(LeaseService $leaseService, ProfileTemplateService $templates, PropertyService $properties, EntitlementService $entitlements, PayoutService $payouts, string $initialTab = 'about'): Response
    {
        $userId  = session('auth.user_id');
        $user    = User::findOrFail($userId);
        $profile = $user->profile;
        $hunting = $profile?->hunting_profile ?? [];

        $isLandowner = $user->account_type === 'landowner';

        $photos = Document::where('owner_user_id', $userId)
            ->where('document_type', 'profile_photo')
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($doc) => [
                'id'  => $doc->id,
                'url' => route('member.profile.photos.serve', $doc->id),
            ])
            ->values()
            ->toArray();

        $props = [
            'user' => [
                'id'                 => $user->id,
                'email'              => $user->email,
                'phone'              => $user->phone,
                'account_type'       => $user->account_type,
                'trust_score'        => $user->trust_score,
                'is_veteran'         => $user->is_veteran,
                'is_first_responder' => $user->is_first_responder,
                'is_profile_public'  => $user->is_profile_public,
                'username'           => $user->username,
                'member_since'       => $user->created_at?->format('F Y'),
            ],
            'profile' => [
                'first_name'           => $profile?->first_name           ?? '',
                'last_name'            => $profile?->last_name            ?? '',
                'display_name'         => $profile?->display_name         ?? '',
                'bio'                  => $profile?->bio                  ?? '',
                'state_code'           => $profile?->state_code           ?? null,
                'zip_code'             => $profile?->zip_code             ?? '',
                'date_of_birth'        => $profile?->date_of_birth?->format('Y-m-d') ?? null,
                'gender'               => $profile?->gender               ?? null,
                'avatar_url'           => $this->resolveAvatarUrl($profile),
                'veteran_branch'                => $profile?->veteran_branch        ?? null,
                'veteran_is_active'             => $profile?->veteran_is_active    ?? false,
                'veteran_service_start'         => $this->formatDate($profile?->veteran_service_start),
                'veteran_service_end'           => $this->formatDate($profile?->veteran_service_end),
                'veteran_last_rank'             => $profile?->veteran_last_rank    ?? null,
                'veteran_bio'                   => $profile?->veteran_bio          ?? null,
                'first_responder_type'          => $profile?->first_responder_type ?? null,
                'first_responder_is_active'     => $profile?->first_responder_is_active ?? false,
                'first_responder_service_start' => $this->formatDate($profile?->first_responder_service_start),
                'first_responder_service_end'   => $this->formatDate($profile?->first_responder_service_end),
                'first_responder_last_rank'     => $profile?->first_responder_last_rank ?? null,
                'first_responder_bio'           => $profile?->first_responder_bio        ?? null,
                'hunting' => [
                    'species'          => $hunting['species']          ?? [],
                    'terrain'          => $hunting['terrain']          ?? [],
                    'style'            => $hunting['style']            ?? null,
                    'seasons'          => $hunting['seasons']          ?? [],
                    'years_hunting'    => $hunting['years_hunting']    ?? null,
                    'preferred_states' => $hunting['preferred_states'] ?? [],
                ],
                'social_links' => $profile?->social_links ?? [],
                'gear'         => [
                    'items' => $profile?->gear_profile['items'] ?? [],
                ],
                'visibility'   => [
                    'about'   => $profile?->profile_visibility['about']   ?? 'public',
                    'contact' => $profile?->profile_visibility['contact'] ?? 'private',
                    'social'  => $profile?->profile_visibility['social']  ?? 'private',
                    'gear'    => $profile?->profile_visibility['gear']    ?? 'public',
                    'photos'  => $profile?->profile_visibility['photos']  ?? 'public',
                ],
            ],
            'photos'      => $photos,
            'activity'    => $this->buildActivityProps($userId),
            'security'    => $this->buildSecurityProps($userId),
            'leases'      => $leaseService->getLeaseSummariesForLessee($userId),
            'membership'  => $entitlements->currentMembership($user),
            'invoices'    => $this->buildInvoices($userId),
            'checkout'    => request()->query('checkout'),
            'billing'     => request()->query('billing'),
            'payouts_status' => request()->query('payouts'),
            'initial_tab' => $initialTab,
            'template'    => $isLandowner ? null : $templates->getPublishedConfig('hunter'),
        ];

        if ($isLandowner) {
            $props['properties'] = $properties->getManagedPropertySummaries($userId);
            $props['payouts']    = $payouts->onboardingState($user);
        }

        return Inertia::render('Member/Profile/Hunter', $props);
    }

    public function update(Request $request)
    {
        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);

        $data = $request->validate([
            'first_name'               => 'required|string|max:100',
            'last_name'                => 'required|string|max:100',
            'display_name'             => 'nullable|string|max:100',
            'bio'                      => 'nullable|string|max:1000',
            'state_code'               => 'nullable|string|size:2',
            'zip_code'                 => 'nullable|string|max:10',
            'date_of_birth'            => 'nullable|date',
            'gender'                   => 'nullable|in:male,female,nonbinary,prefer_not_to_say',
            'phone'                    => 'nullable|string|max:20',
            'is_veteran'               => 'nullable|boolean',
            'is_first_responder'       => 'nullable|boolean',
            'veteran_branch'                => 'nullable|string|max:50',
            'veteran_is_active'             => 'nullable|boolean',
            'veteran_service_start'         => 'nullable|date',
            'veteran_service_end'           => 'nullable|date',
            'veteran_last_rank'             => 'nullable|string|max:100',
            'veteran_bio'                   => 'nullable|string|max:500',
            'first_responder_type'          => 'nullable|string|max:50',
            'first_responder_is_active'     => 'nullable|boolean',
            'first_responder_service_start' => 'nullable|date',
            'first_responder_service_end'   => 'nullable|date',
            'first_responder_last_rank'     => 'nullable|string|max:100',
            'first_responder_bio'           => 'nullable|string|max:500',
            'hunting'                  => 'nullable|array',
            'hunting.species'          => 'nullable|array',
            'hunting.terrain'          => 'nullable|array',
            'hunting.style'            => 'nullable|string|max:100',
            'hunting.seasons'          => 'nullable|array',
            'hunting.years_hunting'    => 'nullable|integer|min:0|max:80',
            'hunting.preferred_states'   => 'nullable|array',
            'social_links'               => 'nullable|array',
            'social_links.instagram'     => 'nullable|string|max:255',
            'social_links.facebook'      => 'nullable|string|max:255',
            'social_links.x'             => 'nullable|string|max:255',
            'social_links.discord'       => 'nullable|string|max:255',
            'social_links.youtube'       => 'nullable|string|max:255',
            'social_links.tiktok'        => 'nullable|string|max:255',
            'social_links.linkedin'      => 'nullable|string|max:255',
            'social_links.snapchat'      => 'nullable|string|max:255',
            'social_links.reddit'        => 'nullable|string|max:255',
            'social_links.twitch'        => 'nullable|string|max:255',
            'visibility'                 => 'nullable|array',
            'visibility.about'           => 'nullable|in:public,private',
            'visibility.contact'         => 'nullable|in:public,private',
            'visibility.social'          => 'nullable|in:public,private',
            'visibility.gear'            => 'nullable|in:public,private',
            'visibility.photos'          => 'nullable|in:public,private',
            'gear'                       => 'nullable|array',
            'gear.items'                 => 'nullable|array',
            'gear.items.*.id'            => 'required|string|max:36',
            'gear.items.*.category'      => 'required|string|in:firearms,archery,ammunition,optics,clothing,boots,pack,electronics,knives,calls,other',
            'gear.items.*.brand'         => 'nullable|string|max:100',
            'gear.items.*.model'         => 'required|string|max:200',
            'gear.items.*.notes'         => 'nullable|string|max:500',
        ]);

        $user->update([
            'phone'              => $data['phone']              ?? null,
            'is_veteran'         => (bool) ($data['is_veteran']         ?? false),
            'is_first_responder' => (bool) ($data['is_first_responder'] ?? false),
        ]);

        $profile = $user->profile ?? UserProfile::firstOrNew(['user_id' => $userId]);
        $profile->fill([
            'first_name'     => $data['first_name'],
            'last_name'      => $data['last_name'],
            'display_name'   => $data['display_name'] ?? null,
            'bio'            => $data['bio']           ?? null,
            'state_code'     => $data['state_code']   ?? null,
            'zip_code'       => $data['zip_code']     ?? null,
            'date_of_birth'  => $data['date_of_birth'] ?? null,
            'gender'         => $data['gender']        ?? null,
            'veteran_branch'                => $data['veteran_branch']                ?? null,
            'veteran_is_active'             => $data['veteran_is_active']             ?? false,
            'veteran_service_start'         => $data['veteran_service_start']         ?? null,
            'veteran_service_end'           => $data['veteran_service_end']           ?? null,
            'veteran_last_rank'             => $data['veteran_last_rank']             ?? null,
            'veteran_bio'                   => $data['veteran_bio']                   ?? null,
            'first_responder_type'          => $data['first_responder_type']          ?? null,
            'first_responder_is_active'     => $data['first_responder_is_active']     ?? false,
            'first_responder_service_start' => $data['first_responder_service_start'] ?? null,
            'first_responder_service_end'   => $data['first_responder_service_end']   ?? null,
            'first_responder_last_rank'     => $data['first_responder_last_rank']     ?? null,
            'first_responder_bio'           => $data['first_responder_bio']           ?? null,
            'hunting_profile' => [
                'species'          => $data['hunting']['species']          ?? [],
                'terrain'          => $data['hunting']['terrain']          ?? [],
                'style'            => $data['hunting']['style']            ?? null,
                'seasons'          => $data['hunting']['seasons']          ?? [],
                'years_hunting'    => isset($data['hunting']['years_hunting'])
                                          ? (int) $data['hunting']['years_hunting']
                                          : null,
                'preferred_states' => $data['hunting']['preferred_states'] ?? [],
            ],
            'social_links' => array_filter(
                $data['social_links'] ?? [],
                fn ($v) => ! empty(trim((string) $v))
            ),
            'profile_visibility' => [
                'about'   => $data['visibility']['about']   ?? 'public',
                'contact' => $data['visibility']['contact'] ?? 'private',
                'social'  => $data['visibility']['social']  ?? 'private',
                'gear'    => $data['visibility']['gear']    ?? 'public',
                'photos'  => $data['visibility']['photos']  ?? 'public',
            ],
            'gear_profile' => [
                'items' => collect($data['gear']['items'] ?? [])->map(fn ($item) => [
                    'id'       => $item['id'],
                    'category' => $item['category'],
                    'brand'    => trim($item['brand'] ?? ''),
                    'model'    => trim($item['model']),
                    'notes'    => trim($item['notes'] ?? ''),
                ])->toArray(),
            ],
        ]);
        $profile->save();

        return redirect()->route('member.profile');
    }

    /**
     * FilePond process: the avatar is instant-uploaded the moment it is dropped
     * (parity with the admin avatar FileUpload). Returns the new document id as
     * plain text; the client reloads the profile prop to show the new photo.
     */
    public function uploadAvatar(Request $request): \Illuminate\Http\Response
    {
        $request->validate([
            'avatar' => 'required|image|max:4096|mimes:jpg,jpeg,png,webp',
        ]);

        $userId = session('auth.user_id');
        $user   = User::findOrFail($userId);
        $file   = $request->file('avatar');
        $ext    = strtolower($file->getClientOriginalExtension());
        $key    = "avatars/{$userId}.{$ext}";

        Storage::disk('local')->putFileAs('avatars', $file, "{$userId}.{$ext}");

        $doc = $this->documents->register(
            ownerUserId:      $userId,
            documentType:     'avatar',
            originalFilename: $file->getClientOriginalName(),
            mimeType:         $file->getMimeType() ?? 'image/jpeg',
            sizeBytes:        $file->getSize(),
            storageBucket:    'local',
            storageKey:       $key,
            storageProvider:  'garage',
        );

        $profile = $user->profile ?? UserProfile::firstOrNew(['user_id' => $userId]);
        $profile->avatar_document_id = $doc->id;
        $profile->save();

        return response($doc->id)->header('Content-Type', 'text/plain');
    }

    public function serveAvatar(string $userId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Only serve your own avatar for now; public profile directory is Phase 7+
        $requestingUser = session('auth.user_id');
        if ($requestingUser !== $userId) {
            abort(403);
        }

        $user    = User::findOrFail($userId);
        $profile = $user->profile;

        if (! $profile?->avatar_document_id) {
            abort(404);
        }

        $doc = Document::find($profile->avatar_document_id);
        if (! $doc || ! Storage::disk('local')->exists($doc->storage_key)) {
            abort(404);
        }

        return Storage::disk('local')->response($doc->storage_key, null, [
            'Content-Type'  => $doc->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * FilePond process: one photo per request, instant-uploaded the moment it is
     * dropped (parity with the admin / property uploaders). Returns the new
     * document id as plain text so FilePond can track it; the gallery refreshes
     * via an Inertia partial reload on the client when the batch finishes.
     */
    public function uploadPhoto(Request $request): \Illuminate\Http\Response
    {
        $request->validate([
            'photo' => 'required|image|max:8192|mimes:jpg,jpeg,png,webp',
        ]);

        $userId   = session('auth.user_id');
        $file     = $request->file('photo');
        $ext      = strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid() . '.' . $ext;
        $dir      = "profile_photos/{$userId}";

        Storage::disk('local')->putFileAs($dir, $file, $filename);

        $doc = $this->documents->register(
            ownerUserId:      $userId,
            documentType:     'profile_photo',
            originalFilename: $file->getClientOriginalName(),
            mimeType:         $file->getMimeType() ?? 'image/jpeg',
            sizeBytes:        $file->getSize(),
            storageBucket:    'local',
            storageKey:       "{$dir}/{$filename}",
            storageProvider:  'garage',
        );

        return response($doc->id)->header('Content-Type', 'text/plain');
    }

    public function servePhoto(string $documentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $requestingUser = session('auth.user_id');

        $doc = Document::where('id', $documentId)->whereNull('deleted_at')->firstOrFail();

        // Public photo viewing is Phase 7+ (public profiles). Owner-only for now.
        if ($doc->owner_user_id !== $requestingUser) {
            abort(403);
        }

        if (! Storage::disk('local')->exists($doc->storage_key)) {
            abort(404);
        }

        return Storage::disk('local')->response($doc->storage_key, null, [
            'Content-Type'  => $doc->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function deletePhoto(string $documentId)
    {
        $userId = session('auth.user_id');

        $doc = Document::where('id', $documentId)->whereNull('deleted_at')->firstOrFail();

        if ($doc->owner_user_id !== $userId) {
            abort(403);
        }

        $doc->delete();

        return redirect()->route('member.profile');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The member's invoices for the membership card, read from the local Stripe
     * invoice projection (Phase 5.7) instead of a live Stripe call per render.
     * The projection is kept current by webhooks + the daily reconcile job;
     * Stripe stays the source of truth. Wrapped so any read hiccup degrades to an
     * empty list rather than 500ing the profile page; free-tier members simply
     * have no rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildInvoices(string $userId): array
    {
        try {
            return StripeInvoiceProjection::displayForUser($userId);
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildActivityProps(string $userId): array
    {
        $events = collect();

        // Check-ins from lease DB
        try {
            CheckIn::on('lease')
                ->where('user_id', $userId)
                ->orderByDesc('checked_in_at')
                ->limit(50)
                ->get()
                ->each(function ($ci) use ($events) {
                    $events->push([
                        'type'       => 'check_in',
                        'occurred_at'=> $ci->checked_in_at?->toISOString(),
                        'date_label' => $ci->checked_in_at?->format('M j, Y'),
                        'time_label' => $ci->checked_in_at?->format('g:i A'),
                        'checked_out'=> $ci->checked_out_at?->format('g:i A'),
                        'notes'      => $ci->notes,
                    ]);
                });
        } catch (\Throwable) {}

        // Harvests from wildlife DB. The wildlife schema is not built yet, so
        // guard on the table existing — otherwise every profile load fires a
        // doomed query that PostgreSQL records as a server-side ERROR.
        if ($this->tableExists('wildlife', 'harvest_logs')) {
            HarvestLog::on('wildlife')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderByDesc('harvest_date')
                ->limit(50)
                ->get()
                ->each(function ($h) use ($events) {
                    $events->push([
                        'type'        => 'harvest',
                        'occurred_at' => $h->harvest_date?->toISOString(),
                        'date_label'  => $h->harvest_date?->format('M j, Y'),
                        'species'     => $h->species_code,
                        'weapon_type' => $h->weapon_type,
                        'weight_lbs'  => $h->weight_lbs,
                        'antler_score'=> $h->antler_score,
                        'notes'       => $h->notes,
                    ]);
                });
        }

        return [
            'events' => $events
                ->sortByDesc('occurred_at')
                ->values()
                ->take(30)
                ->toArray(),
        ];
    }

    /**
     * Whether a table exists on a connection, cached briefly so we don't hit
     * information_schema on every request. The short TTL self-heals once the
     * schema for a not-yet-built domain (e.g. wildlife) is migrated in.
     */
    private function tableExists(string $connection, string $table): bool
    {
        return Cache::store('valkey')->remember(
            "schema_exists:{$connection}:{$table}",
            now()->addMinutes(10),
            fn () => Schema::connection($connection)->hasTable($table),
        );
    }

    private function buildSecurityProps(string $userId): array
    {
        $enabledMethods = DB::connection('platform')
            ->table('mfa_factor_settings')
            ->where('is_enabled', true)
            ->pluck('factor')
            ->values()
            ->toArray();

        $configs = MfaConfiguration::where('user_id', $userId)->get()->keyBy('method');

        $mfa = [];
        foreach (['totp', 'sms', 'email'] as $method) {
            $cfg = $configs->get($method);
            $mfa[$method] = [
                'enabled'     => (bool) ($cfg?->is_enabled ?? false),
                'verified_at' => $cfg?->verified_at?->format('M j, Y') ?? null,
            ];
        }

        $history = LoginHistory::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id'       => $row->id,
                'ip'       => $row->ip_address,
                'ua'       => $row->user_agent,
                'success'  => (bool) $row->success,
                'mfa_used' => (bool) $row->mfa_used,
                'at'       => $row->created_at?->format('M j, Y g:i A') ?? '',
            ])
            ->values()
            ->toArray();

        $user = User::findOrFail($userId);

        return [
            'mfa'                => $mfa,
            'login_history'      => $history,
            'enabled_methods'    => $enabledMethods,
            'suggested_username' => $user->username ?? $this->suggestUsername($user),
        ];
    }

    private function suggestUsername(User $user): string
    {
        $profile = $user->profile;
        $base    = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));

        $slug = Str::slug($base ?: 'hunter', '_');
        $slug = substr($slug, 0, 26);

        if (strlen($slug) < 3) {
            $slug = 'hunter_' . $slug;
        }

        if (! DB::connection('identity')->table('users')->where('username', $slug)->exists()) {
            return $slug;
        }

        for ($i = 0; $i < 10; $i++) {
            $candidate = $slug . '_' . Str::lower(Str::random(4));
            if (! DB::connection('identity')->table('users')->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $slug . '_' . substr((string) time(), -4);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null) return null;
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
    }

    private function resolveAvatarUrl(?UserProfile $profile): ?string
    {
        if (! $profile?->avatar_document_id) {
            return null;
        }

        try {
            $doc = Document::find($profile->avatar_document_id);
            if ($doc && Storage::disk('local')->exists($doc->storage_key)) {
                // The serve URL is keyed by user_id (stable) and the response is
                // cached for an hour, so without a version token a new upload shows
                // the stale cached image. avatar_document_id is a fresh UUID per
                // upload — append it to bust the cache only when the avatar changes.
                return route('member.profile.avatar', $profile->user_id) . '?v=' . $profile->avatar_document_id;
            }
        } catch (\Throwable) {}

        return null;
    }
}
