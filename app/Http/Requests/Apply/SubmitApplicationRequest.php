<?php

namespace App\Http\Requests\Apply;

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
