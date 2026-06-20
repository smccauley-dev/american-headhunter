<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Covers the home-state rule added to signup. Home state gatekeeps which
 * listings a member may apply to, so the stored value must be a valid
 * two-letter USPS code.
 *
 * The rule is exercised in isolation (not the whole RegisterRequest) on
 * purpose: the email rule uses `dns` validation, which would otherwise make
 * these tests depend on live DNS resolution.
 */
class RegisterStateCodeValidationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function stateRule(): array
    {
        return ['state_code' => (new RegisterRequest())->rules()['state_code']];
    }

    public function test_valid_two_letter_state_passes(): void
    {
        $validator = Validator::make(['state_code' => 'TX'], $this->stateRule());

        $this->assertTrue($validator->passes());
    }

    public function test_missing_state_is_rejected(): void
    {
        $validator = Validator::make([], $this->stateRule());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('state_code', $validator->errors()->toArray());
    }

    public function test_unknown_state_code_is_rejected(): void
    {
        $validator = Validator::make(['state_code' => 'ZZ'], $this->stateRule());

        $this->assertTrue($validator->fails());
    }

    public function test_lowercase_code_is_rejected(): void
    {
        // The signup select submits uppercase USPS codes; lowercase is not a key.
        $validator = Validator::make(['state_code' => 'tx'], $this->stateRule());

        $this->assertTrue($validator->fails());
    }
}
