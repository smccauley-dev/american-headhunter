<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Invoice;
use App\Models\Billing\Payment;
use App\Models\Billing\PromoCode;
use App\Models\Billing\Refund;
use App\Models\Billing\Subscription;
use App\Models\Billing\W9Record;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DB 4 (Billing) schema + Eloquent model verification — locks in Phase 5.1.
 *
 * Runs against the real `billing` PostgreSQL connection (not the sqlite test DB)
 * because the assertions cover Postgres-only behaviour: gen_random_uuid()
 * defaults, the trigger_set_updated_at() trigger, CHECK constraints, the partial
 * unique index on subscriptions, JSONB casting, and cross-DB resolution.
 *
 * No DatabaseTransactions: the updated_at trigger uses NOW(), which is frozen for
 * the life of a transaction, so the trigger assertion needs real commits. Rows
 * are force-deleted in tearDown instead (mirrors ScanDocumentForVirusesTest).
 */
class BillingSchemaTest extends TestCase
{
    /** @var array<int,string> */
    private array $invoiceIds = [];
    /** @var array<int,string> */
    private array $paymentIds = [];
    /** @var array<int,string> */
    private array $refundIds = [];
    /** @var array<int,string> */
    private array $subscriptionIds = [];
    /** @var array<int,string> */
    private array $promoCodeIds = [];
    /** @var array<int,string> */
    private array $w9RecordIds = [];

    protected function tearDown(): void
    {
        $conn = DB::connection('billing');
        // Children first to respect the FK chain (refund -> payment -> invoice).
        if ($this->refundIds)       { $conn->table('refunds')->whereIn('id', $this->refundIds)->delete(); }
        if ($this->paymentIds)      { $conn->table('payments')->whereIn('id', $this->paymentIds)->delete(); }
        if ($this->invoiceIds)      { $conn->table('invoices')->whereIn('id', $this->invoiceIds)->delete(); }
        if ($this->subscriptionIds) { $conn->table('subscriptions')->whereIn('id', $this->subscriptionIds)->delete(); }
        if ($this->promoCodeIds)    { $conn->table('promo_codes')->whereIn('id', $this->promoCodeIds)->delete(); }
        if ($this->w9RecordIds)     { $conn->table('w9_records')->whereIn('id', $this->w9RecordIds)->delete(); }

        try { $conn->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        $inv = Invoice::create(array_merge([
            'payer_user_id'      => (string) Str::uuid(),
            'payee_user_id'      => (string) Str::uuid(),
            'status'             => 'open',
            'subtotal_cents'     => 10000,
            'tax_cents'          => 875,
            'platform_fee_cents' => 500,
            'total_cents'        => 11375,
        ], $overrides));

        $this->invoiceIds[] = $inv->id;

        return $inv;
    }

    public function test_create_chain_generates_uuids_and_enforces_fks(): void
    {
        $inv = $this->makeInvoice();
        $this->assertTrue(Str::isUuid($inv->id), 'invoice id should be a server-generated UUID');

        $pay = Payment::create([
            'invoice_id'       => $inv->id,
            'payer_user_id'    => $inv->payer_user_id,
            'amount_cents'     => 11375,
            'status'           => 'succeeded',
            'stripe_charge_id' => 'ch_test123',
            'metadata'         => ['source' => 'lease_deposit'],
        ]);
        $this->paymentIds[] = $pay->id;
        $this->assertTrue(Str::isUuid($pay->id));

        $ref = Refund::create([
            'payment_id'   => $pay->id,
            'amount_cents' => 5000,
            'reason'       => 'partial cancellation',
            'status'       => 'succeeded',
        ]);
        $this->refundIds[] = $ref->id;
        $this->assertTrue(Str::isUuid($ref->id));

        // Relationships
        $this->assertCount(1, $inv->payments);
        $this->assertCount(1, $pay->fresh()->refunds);
        $this->assertSame($inv->id, $pay->invoice->id);
    }

    public function test_payment_payload_is_cast_and_stripe_ids_are_hidden(): void
    {
        $inv = $this->makeInvoice();
        $pay = Payment::create([
            'invoice_id'    => $inv->id,
            'payer_user_id' => $inv->payer_user_id,
            'amount_cents'  => 11375,
            'status'        => 'succeeded',
            'stripe_charge_id' => 'ch_test123',
            'metadata'      => ['source' => 'lease_deposit', 'attempt' => 1],
        ]);
        $this->paymentIds[] = $pay->id;

        $fresh = $pay->fresh();
        $this->assertSame(11375, $fresh->amount_cents, 'cents column should cast to int');
        $this->assertIsArray($fresh->metadata, 'JSONB metadata should cast to array');
        $this->assertSame('lease_deposit', $fresh->metadata['source']);

        $arr = $fresh->toArray();
        $this->assertArrayNotHasKey('stripe_charge_id', $arr);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $arr);
    }

    public function test_get_lease_resolves_cross_database_safely(): void
    {
        // lease_id points at DB 3 via a UUID column (no SQL FK). An unknown id
        // must resolve through LeaseService to null, not error.
        $inv = $this->makeInvoice(['lease_id' => (string) Str::uuid()]);
        $this->assertNull($inv->getLease());
    }

    public function test_updated_at_trigger_overwrites_client_value(): void
    {
        $inv = $this->makeInvoice();

        // The trigger sets NEW.updated_at = NOW() on every UPDATE, so a client
        // value (here, year 2000) must be ignored.
        $inv->update(['status' => 'paid', 'updated_at' => '2000-01-01 00:00:00']);

        $this->assertNotSame(2000, $inv->fresh()->updated_at->year, 'trigger should overwrite updated_at');
    }

    public function test_soft_delete_filters_invoice_but_retains_row(): void
    {
        $inv = $this->makeInvoice();
        $id  = $inv->id;

        $inv->delete();

        $this->assertNull(Invoice::find($id));
        $this->assertNotNull(Invoice::withTrashed()->find($id));
    }

    public function test_invalid_invoice_status_is_rejected_by_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        Invoice::create([
            'payer_user_id' => (string) Str::uuid(),
            'payee_user_id' => (string) Str::uuid(),
            'status'        => 'bogus',
            'total_cents'   => 0,
        ]);
    }

    public function test_user_cannot_hold_two_active_subscriptions(): void
    {
        $userId = (string) Str::uuid();
        $make = fn () => Subscription::create([
            'user_id'              => $userId,
            'plan_version_id'      => (string) Str::uuid(),
            'status'               => 'active',
            'current_period_start' => now()->toDateString(),
            'current_period_end'   => now()->addMonth()->toDateString(),
        ]);

        $first = $make();
        $this->subscriptionIds[] = $first->id;
        $this->assertTrue(Str::isUuid($first->id));

        $this->expectException(QueryException::class); // partial unique index violation
        $make();
    }

    private function makePromoCode(string $code, array $overrides = []): PromoCode
    {
        $pc = PromoCode::create(array_merge([
            'promotional_period_id' => (string) Str::uuid(),
            'code'                  => $code,
            'per_user_limit'        => 1,
        ], $overrides));

        $this->promoCodeIds[] = $pc->id;

        return $pc;
    }

    public function test_promo_code_create_casts_and_partner_attribution(): void
    {
        $owner = (string) Str::uuid();
        $pc = $this->makePromoCode('SAVE10', [
            'owner_user_id'   => $owner,        // outfitter/landowner code
            'max_redemptions' => 100,
            'is_active'       => true,
        ]);

        $fresh = $pc->fresh();
        $this->assertTrue(Str::isUuid($fresh->id));
        $this->assertSame($owner, $fresh->owner_user_id);
        $this->assertSame(0, $fresh->redemption_count, 'redemption_count defaults to 0');
        $this->assertSame(100, $fresh->max_redemptions);
        $this->assertTrue($fresh->is_active, 'is_active should cast to bool');
    }

    public function test_promo_code_is_case_insensitively_unique(): void
    {
        $this->makePromoCode('FALL2026');

        $this->expectException(QueryException::class); // uq_promo_codes_code on LOWER(code)
        $this->makePromoCode('fall2026');
    }

    public function test_promo_code_redemptions_cannot_exceed_max(): void
    {
        $this->expectException(QueryException::class); // chk_promo_codes_redemptions

        PromoCode::create([
            'promotional_period_id' => (string) Str::uuid(),
            'code'                  => 'OVERMAX',
            'max_redemptions'       => 5,
            'redemption_count'      => 6,
        ]);
    }

    private function makeW9(array $overrides = []): W9Record
    {
        $w9 = W9Record::create(array_merge([
            'user_id'            => (string) Str::uuid(),
            'legal_name'         => 'Jane Q. Landowner',
            'tax_classification' => 'individual',
            'tin_type'           => 'ssn',
            'tin'                => '123-45-6789',
            'tin_last_four'      => '6789',
            'address_line1'      => '100 Ridge Rd',
            'city'               => 'Helena',
            'state_code'         => 'MT',
            'postal_code'        => '59601',
            'status'             => 'pending',
        ], $overrides));

        $this->w9RecordIds[] = $w9->id;

        return $w9;
    }

    public function test_w9_tin_is_encrypted_at_rest_and_hidden(): void
    {
        $w9 = $this->makeW9();

        // Round-trips through HasEncryptedFields on read.
        $this->assertSame('123-45-6789', $w9->fresh()->tin);

        // Raw column is base64 ciphertext — never the plaintext TIN.
        $rawTin = DB::connection('billing')->table('w9_records')->where('id', $w9->id)->value('tin');
        $this->assertNotSame('123-45-6789', $rawTin);
        $this->assertStringNotContainsString('123-45-6789', (string) $rawTin);

        // tin is in $hidden — must not serialize.
        $arr = $w9->fresh()->toArray();
        $this->assertArrayNotHasKey('tin', $arr);
        $this->assertSame('6789', $arr['tin_last_four']);
    }

    public function test_w9_one_active_record_per_user(): void
    {
        $userId = (string) Str::uuid();
        $this->makeW9(['user_id' => $userId, 'status' => 'verified']);

        $this->expectException(QueryException::class); // uq_w9_records_user_active
        $this->makeW9(['user_id' => $userId, 'status' => 'pending']);
    }

    public function test_w9_invalid_classification_rejected_by_check(): void
    {
        $this->expectException(QueryException::class);

        $this->makeW9(['tax_classification' => 'bogus_class']);
    }
}
