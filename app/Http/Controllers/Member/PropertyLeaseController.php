<?php

namespace App\Http\Controllers\Member;

use App\Database\ConnectionRole;
use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Property\PropertyService;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * All leases ever written against one of the landowner's properties — past and
 * current — so they can be reviewed from the property hub (parity with the
 * applications list, which only covers the pre-lease stage).
 *
 * Authorization is service-layer (PropertyService::userCanManageProperty — the
 * properties table has no RLS policy). Lessee names are resolved in one cross-DB
 * read under ah_system: a landowner request is ah_runtime, where the identity
 * `users` RLS default-denies other users' rows (SEC-047).
 */
class PropertyLeaseController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
    ) {}

    private const STATUS_LABELS = [
        'pending_signatures' => 'Awaiting Signatures',
        'pending_payment'    => 'Awaiting Payment',
        'active'             => 'Active',
        'expired'            => 'Expired',
        'terminated'         => 'Terminated',
        'cancelled'          => 'Cancelled',
    ];

    public function index(string $property): Response
    {
        $record = $this->authorizeManage($property);

        $leases = Lease::on('lease')
            ->where('property_id', $property)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        $names = $this->resolveLesseeNames($leases->pluck('lessee_user_id'));

        $rows = $leases->map(fn (Lease $l) => [
            'id'            => $l->id,
            'ref'           => 'AH-' . strtoupper(substr($l->id, 0, 8)),
            'status'        => $l->status,
            'status_label'  => self::STATUS_LABELS[$l->status] ?? ucfirst(str_replace('_', ' ', $l->status)),
            'lessee_name'   => $names[$l->lessee_user_id] ?? '—',
            'start_date'    => $l->start_date?->format('M j, Y'),
            'end_date'      => $l->end_date?->format('M j, Y'),
            'total_price'   => $l->total_price !== null ? (float) $l->total_price : null,
            'created_at'    => $l->created_at?->format('M j, Y'),
            'terminated_at' => $l->terminated_at?->format('M j, Y'),
        ])->values()->all();

        return Inertia::render('Member/Properties/Leases/Index', [
            'property' => [
                'id'          => $record->id,
                'title'       => $record->title,
                'status'      => $record->status,
                'state_code'  => $record->state_code,
                'county'      => $record->county,
                'total_acres' => $record->total_acres !== null ? (float) $record->total_acres : null,
            ],
            'leases' => $rows,
        ]);
    }

    /**
     * Map of user_id => display name from the identity DB. Runs under ah_system —
     * the identity `users` RLS default-denies other users' rows under ah_runtime
     * (SEC-047).
     *
     * @param  \Illuminate\Support\Collection<int, string>  $userIds
     * @return array<string, string>
     */
    private function resolveLesseeNames(Collection $userIds): array
    {
        $ids = $userIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $users = ConnectionRole::asSystem(
            fn () => User::on('identity')->with('profile')->whereIn('id', $ids)->get()
        );

        return $users
            ->mapWithKeys(fn (User $u) => [$u->id => $u->profile?->full_name ?: $u->email])
            ->all();
    }

    /** Resolve a property the current user owns or actively manages, or 404. */
    private function authorizeManage(string $propertyId): \App\Models\Property\Property
    {
        $userId = session('auth.user_id');
        abort_unless($this->properties->userCanManageProperty($userId, $propertyId), 404);

        return $this->properties->find($propertyId) ?? abort(404);
    }
}
