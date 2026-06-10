<?php

namespace App\Http\Resources\Lease;

use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'status'           => $this->status,
            'application_type' => $this->application_type,
            'desired_hunters'  => $this->desired_hunters,
            'proposed_start'   => $this->proposed_start?->toDateString(),
            'proposed_end'     => $this->proposed_end?->toDateString(),
            // Decrypted via HasEncryptedFields — hunter's own message, entitled to see it.
            'message'          => $this->message,
            'submitted_at'     => $this->created_at?->toIso8601String(),
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),
            // Only present when rejected — avoids surfacing the key for non-rejected statuses.
            'rejection_reason' => $this->when(
                $this->status === 'rejected',
                $this->rejection_reason
            ),
            // Snapshot fields written at submit time — survive listing archival.
            'listing' => [
                'id'           => $this->listing_id,
                'property_id'  => $this->property_id_snapshot,
                'property_title'    => $this->property_title_snapshot,
                'property_slug'     => $this->property_slug_snapshot,
                'property_location' => $this->property_location_snapshot,
                'season_start'      => $this->listing_season_start_snap?->toDateString(),
                'season_end'        => $this->listing_season_end_snap?->toDateString(),
            ],
            // Active lease if the application was approved and a lease was created.
            'lease' => $this->whenLoaded('lease', fn() =>
                $this->lease ? [
                    'id'           => $this->lease->id,
                    'status'       => $this->lease->status,
                    'start_date'   => $this->lease->start_date?->toDateString(),
                    'end_date'     => $this->lease->end_date?->toDateString(),
                    'total_price'  => $this->lease->total_price,
                    'deposit_paid' => $this->lease->deposit_paid,
                    'auto_renew'   => $this->lease->auto_renew,
                ] : null
            ),
        ];
    }
}
