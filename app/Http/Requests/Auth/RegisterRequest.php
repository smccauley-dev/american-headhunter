<?php

namespace App\Http\Requests\Auth;

use App\Models\Identity\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', 'in:hunter,landowner,club_officer,outfitter,consultant,marketplace_seller'],
            'email'        => ['required', 'email:rfc,dns', 'max:255', Rule::unique(User::class, 'email')],
            'password'     => ['required', 'string', 'min:12', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/'],
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'phone'        => ['required', 'string', 'max:20'],
            'tos_accepted' => ['required', 'accepted'],
            'privacy_accepted' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'   => 'An account with that email address already exists.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'tos_accepted.accepted' => 'You must accept the Terms of Service.',
            'privacy_accepted.accepted' => 'You must accept the Privacy Policy.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}
