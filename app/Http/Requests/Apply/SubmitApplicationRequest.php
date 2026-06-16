<?php

namespace App\Http\Requests\Apply;

use App\Services\Property\PropertyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SubmitApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->session()->get('auth.user_id');
    }

    public function rules(): array
    {
        return [
            'application_type' => ['required', 'in:individual,club'],
            'proposed_start'   => ['required', 'date', 'after_or_equal:today'],
            'proposed_end'     => ['required', 'date', 'after:proposed_start'],
            'message'          => ['nullable', 'string', 'max:1000'],

            // Hunter roster — at least 1 hunter required
            'hunters'                    => ['required', 'array', 'min:1'],
            'hunters.*.hunter_type'      => ['required', 'in:primary,guest'],
            'hunters.*.first_name'       => ['required', 'string', 'max:100'],
            'hunters.*.last_name'        => ['required', 'string', 'max:100'],
            'hunters.*.date_of_birth'    => ['required', 'date'],
            'hunters.*.email'            => ['required', 'email', 'max:255'],
            'hunters.*.home_phone'       => ['nullable', 'string', 'max:30'],
            'hunters.*.cell_phone'       => ['required', 'string', 'max:30'],
            'hunters.*.address_line1'    => ['required', 'string', 'max:255'],
            'hunters.*.address_line2'    => ['nullable', 'string', 'max:255'],
            'hunters.*.city'             => ['required', 'string', 'max:100'],
            'hunters.*.state_code'       => ['required', 'string', 'size:2'],
            'hunters.*.zip_code'         => ['required', 'string', 'max:10'],
            'hunters.*.emergency_contact_name'         => ['required', 'string', 'max:200'],
            'hunters.*.emergency_contact_phone'        => ['required', 'string', 'max:30'],
            'hunters.*.emergency_contact_relationship' => ['required', 'string', 'max:50'],
            'hunters.*.medical_conditions'             => ['nullable', 'string', 'max:2000'],
            'hunters.*.dl_number'        => ['required', 'string', 'max:50'],
            'hunters.*.dl_state'         => ['required', 'string', 'size:2'],
            'hunters.*.dl_expiry'        => ['required', 'date'],
            'hunters.*.dl_photo'         => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'hunters.*.dl_confirmed_current'             => ['nullable'],
            'hunters.*.hunting_license_number'           => ['required', 'string', 'max:100'],
            'hunters.*.hunting_license_state'            => ['required', 'string', 'size:2'],
            'hunters.*.hunting_license_expiry'           => ['required', 'date'],
            'hunters.*.hunting_license_photo'            => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'hunters.*.hunting_license_confirmed_current' => ['nullable'],

            'certification_accepted' => ['required', 'accepted'],
        ];
    }

    /**
     * Listing-type-aware checks that the static rules above cannot express:
     * the proposed term must respect the listing's nature, and every hunter's
     * hunting license must be issued by the state the property sits in. These
     * mirror the front-end gates so a hand-crafted POST can't bypass them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $listing = app(PropertyService::class)->findListing((string) $this->route('listing'));
            if (! $listing) {
                return;
            }

            $propertyState = $listing->property?->state_code;
            $seasonStart   = $listing->season_start?->toDateString();
            $seasonEnd     = $listing->season_end?->toDateString();
            $today         = now()->toDateString();
            $start         = (string) $this->input('proposed_start');
            $end           = (string) $this->input('proposed_end');

            // 1. Hunting license state must match the property's state.
            if ($propertyState) {
                foreach ((array) $this->input('hunters', []) as $i => $hunter) {
                    if (($hunter['hunting_license_state'] ?? null) !== $propertyState) {
                        $validator->errors()->add(
                            "hunters.{$i}.hunting_license_state",
                            "Hunting license must be issued by {$propertyState} — the state this property is in.",
                        );
                    }
                }
            }

            // 2. Term must respect the listing type.
            if (in_array($listing->listing_type, ['annual_lease', 'seasonal_lease'], true)) {
                // Fixed-term: the season is the term. Reject an ended season and
                // any attempt to propose dates other than the listing's season.
                if ($seasonEnd && $seasonEnd < $today) {
                    $validator->errors()->add('proposed_start', 'This listing\'s season has ended and is no longer accepting applications.');
                } elseif (($seasonStart && $start !== $seasonStart) || ($seasonEnd && $end !== $seasonEnd)) {
                    $validator->errors()->add('proposed_start', 'The lease term is fixed to this listing\'s season and cannot be changed.');
                }
            } elseif ($listing->listing_type === 'day_hunt') {
                // Day hunt: the chosen range must sit inside the season window and
                // must not overlap any booked / blocked / maintenance range.
                if ($seasonStart && $start && $start < $seasonStart) {
                    $validator->errors()->add('proposed_start', 'The start date must fall within the listing\'s available season.');
                }
                if ($seasonEnd && $end && $end > $seasonEnd) {
                    $validator->errors()->add('proposed_end', 'The end date must fall within the listing\'s available season.');
                }

                if ($start && $end) {
                    foreach (app(PropertyService::class)->getUnavailableRanges($listing->id) as $range) {
                        // Inclusive ranges overlap when each starts on/before the other ends.
                        if ($start <= $range['end'] && $end >= $range['start']) {
                            $validator->errors()->add('proposed_start', 'Those dates are not available — part of the range is already booked or blocked. Please pick open dates.');
                            break;
                        }
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'proposed_start.after_or_equal'    => 'The proposed start date must be today or later.',
            'proposed_end.after'               => 'The proposed end date must be after the start date.',
            'hunters.required'                 => 'At least one hunter is required.',
            'hunters.min'                      => 'At least one hunter is required.',
            'hunters.*.first_name.required'    => 'First name is required.',
            'hunters.*.last_name.required'     => 'Last name is required.',
            'hunters.*.date_of_birth.required' => 'Date of birth is required.',
            'hunters.*.date_of_birth.date'     => 'Enter a valid date of birth.',
            'hunters.*.email.required'         => 'Email address is required.',
            'hunters.*.email.email'            => 'Enter a valid email address.',
            'hunters.*.cell_phone.required'    => 'Cell phone is required.',
            'hunters.*.address_line1.required' => 'Street address is required.',
            'hunters.*.city.required'          => 'City is required.',
            'hunters.*.state_code.required'    => 'State is required.',
            'hunters.*.zip_code.required'      => 'Zip code is required.',
            'hunters.*.emergency_contact_name.required'         => 'Emergency contact name is required.',
            'hunters.*.emergency_contact_phone.required'        => 'Emergency contact phone is required.',
            'hunters.*.emergency_contact_relationship.required' => 'Relationship to emergency contact is required.',
            'hunters.*.dl_number.required'     => 'Driver\'s license number is required.',
            'hunters.*.dl_state.required'      => 'DL issuing state is required.',
            'hunters.*.dl_expiry.required'     => 'DL expiry date is required.',
            'hunters.*.dl_expiry.date'         => 'Enter a valid DL expiry date.',
            'hunters.*.hunting_license_number.required' => 'Hunting license number is required.',
            'hunters.*.hunting_license_state.required'  => 'License issuing state is required.',
            'hunters.*.hunting_license_expiry.required' => 'Hunting license expiry date is required.',
            'hunters.*.hunting_license_expiry.date'     => 'Enter a valid license expiry date.',
            'certification_accepted.required'  => 'You must certify the accuracy of the hunter information before submitting.',
            'certification_accepted.accepted'  => 'You must certify the accuracy of the hunter information before submitting.',
        ];
    }
}
