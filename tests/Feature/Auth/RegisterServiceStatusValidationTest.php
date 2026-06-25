<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Covers the optional service-status step added to signup. The fields must be
 * skippable (absent is valid) but, when present, constrained: only the two
 * known statuses, and only a reasonably-sized PDF/JPG/PNG proof.
 *
 * Rules are exercised in isolation (not the whole RegisterRequest) on purpose:
 * the email rule uses `dns` validation, which would otherwise tie these tests
 * to live DNS resolution — matching RegisterStateCodeValidationTest.
 */
class RegisterServiceStatusValidationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function serviceRules(): array
    {
        $rules = (new RegisterRequest())->rules();

        return [
            'service_status' => $rules['service_status'],
            'service_proof'  => $rules['service_proof'],
        ];
    }

    public function test_omitting_the_step_is_valid(): void
    {
        $validator = Validator::make([], $this->serviceRules());

        $this->assertTrue($validator->passes());
    }

    public function test_known_statuses_pass(): void
    {
        foreach (['veteran', 'first_responder'] as $status) {
            $validator = Validator::make(['service_status' => $status], $this->serviceRules());
            $this->assertTrue($validator->passes(), "{$status} should be accepted");
        }
    }

    public function test_unknown_status_is_rejected(): void
    {
        $validator = Validator::make(['service_status' => 'astronaut'], $this->serviceRules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('service_status', $validator->errors()->toArray());
    }

    public function test_pdf_proof_passes(): void
    {
        $validator = Validator::make(
            ['service_status' => 'veteran', 'service_proof' => UploadedFile::fake()->create('dd214.pdf', 200, 'application/pdf')],
            $this->serviceRules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_disallowed_mime_is_rejected(): void
    {
        $validator = Validator::make(
            ['service_status' => 'first_responder', 'service_proof' => UploadedFile::fake()->create('badge.exe', 50, 'application/octet-stream')],
            $this->serviceRules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('service_proof', $validator->errors()->toArray());
    }

    public function test_oversize_proof_is_rejected(): void
    {
        // Cap is 10 MB (max:10240 KB); 11 MB must fail.
        $validator = Validator::make(
            ['service_status' => 'veteran', 'service_proof' => UploadedFile::fake()->create('dd214.pdf', 11 * 1024, 'application/pdf')],
            $this->serviceRules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('service_proof', $validator->errors()->toArray());
    }
}
