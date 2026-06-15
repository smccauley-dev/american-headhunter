# DB 4 — Billing & Payments

**Connection:** `billing`
**Database:** `ah_billing`
**App User:** `ah_app`
**Server:** Dedicated PCI-scoped PostgreSQL instance — isolated network segment, access-logged, SOC 2 compliant
**Encryption Key:** Key D — HSM-backed, rotated monthly via Azure Key Vault
**Extensions:** `pgcrypto`, `uuid-ossp`
**RLS Enabled:** Yes — on `invoices`, `payments`, `payment_methods`, `payouts`, `w9_records`

This database governs all financial transactions: invoices, payments, refunds, Stripe Connect payouts to landowners, membership subscriptions, and tax records (TaxJar calculations and 1099 filing via Tax1099).

---

## Hard Rules — Never Violate

- **Never store raw card numbers, CVVs, or full PANs.** Stripe tokenized IDs only. The `payment_methods` table stores `last_four` and `brand` only.
- **Never log payment method details** — not even Stripe IDs — in general application logs. Use structured logging with field masking.
- **Never store raw bank account numbers.** Use Stripe's `us_bank_account` payment method type for ACH — Stripe tokenizes it.
- **Never write directly to this database from non-billing service classes.** All writes go through `BillingService`, `StripeService`, or `PayoutService`.
- **Tax records (`tax_calculations`, `tax_1099_records`) are never deleted.** Append-only for compliance.

---

## Tables

### `payment_methods`

Stripe payment method references. Stores only tokenized identifiers and display-safe metadata (last four, brand). The actual card details live entirely in Stripe's vault.

```sql
CREATE TABLE payment_methods (
    id                        UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                   UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    stripe_payment_method_id  VARCHAR(100) NOT NULL,
    type                      VARCHAR(20) NOT NULL
                                  CHECK (type IN ('card', 'bank_account', 'us_bank_account')),
    brand                     VARCHAR(20) NULL,   -- visa, mastercard, amex, discover, etc.
    last_four                 CHAR(4)     NULL,
    exp_month                 SMALLINT    NULL CHECK (exp_month BETWEEN 1 AND 12),
    exp_year                  SMALLINT    NULL,
    is_default                BOOLEAN     NOT NULL DEFAULT false,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at                TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX uq_payment_methods_stripe_pm ON payment_methods (stripe_payment_method_id);
CREATE        INDEX idx_payment_methods_user_id  ON payment_methods (user_id);
CREATE        INDEX idx_payment_methods_deleted  ON payment_methods (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_payment_methods_updated_at
    BEFORE UPDATE ON payment_methods
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE payment_methods ENABLE ROW LEVEL SECURITY;

CREATE POLICY payment_methods_own_user ON payment_methods
    FOR ALL TO ah_app
    USING (
        user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `stripe_payment_method_id` has format `pm_XXXX` for cards, `ba_XXXX` for bank accounts.
- At most one row should have `is_default = true` per user. Enforced in `BillingService::setDefaultPaymentMethod()`.
- When a card expires, a job updates `deleted_at` and Stripe removes the payment method via webhook.

---

### `invoices`

A billing record for a lease payment, application deposit, subscription, or marketplace transaction. Mirrors the Stripe invoice where applicable.

```sql
CREATE TABLE invoices (
    id                UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id          UUID         NULL,  -- References DB 3 (Lease) leases.id — null for subscription invoices
    payer_user_id     UUID         NOT NULL,  -- References DB 1 (Identity) users.id
    payee_user_id     UUID         NOT NULL,  -- References DB 1 (Identity) users.id (landowner or platform)
    status            VARCHAR(20)  NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible')),
    subtotal_cents    BIGINT       NOT NULL DEFAULT 0,
    tax_cents         BIGINT       NOT NULL DEFAULT 0,
    platform_fee_cents BIGINT      NOT NULL DEFAULT 0,
    total_cents       BIGINT       NOT NULL DEFAULT 0,
    currency          CHAR(3)      NOT NULL DEFAULT 'USD',
    stripe_invoice_id VARCHAR(100) NULL,
    due_date          DATE         NULL,
    paid_at           TIMESTAMPTZ  NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at        TIMESTAMPTZ  NULL,

    CONSTRAINT chk_invoices_totals CHECK (total_cents >= 0 AND subtotal_cents >= 0)
);

CREATE        INDEX idx_invoices_lease_id      ON invoices (lease_id) WHERE lease_id IS NOT NULL;
CREATE        INDEX idx_invoices_payer_user_id ON invoices (payer_user_id);
CREATE        INDEX idx_invoices_payee_user_id ON invoices (payee_user_id);
CREATE        INDEX idx_invoices_status        ON invoices (status);
CREATE        INDEX idx_invoices_stripe_id     ON invoices (stripe_invoice_id) WHERE stripe_invoice_id IS NOT NULL;
CREATE        INDEX idx_invoices_deleted_at    ON invoices (deleted_at) WHERE deleted_at IS NOT NULL;

CREATE TRIGGER trg_invoices_updated_at
    BEFORE UPDATE ON invoices
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;

CREATE POLICY invoices_parties_and_staff ON invoices
    FOR SELECT TO ah_app
    USING (
        payer_user_id = current_setting('app.current_user_id')::UUID
        OR payee_user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- All monetary amounts are stored in the smallest currency unit (cents for USD). Never store decimal dollars.
- `platform_fee_cents` is the American Headhunter service fee (configured in DB 12 platform settings).
- `stripe_invoice_id` format: `in_XXXX`. May be null for manually created invoices.
- `void` and `uncollectible` are terminal states set by Stripe webhook processing.

---

### `payments`

Individual payment transactions. A single invoice may have multiple payment attempts (retries on failure).

```sql
CREATE TABLE payments (
    id                        UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    invoice_id                UUID        NOT NULL REFERENCES invoices (id),
    payer_user_id             UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    amount_cents              BIGINT      NOT NULL,
    currency                  CHAR(3)     NOT NULL DEFAULT 'USD',
    status                    VARCHAR(15) NOT NULL DEFAULT 'pending'
                                  CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded', 'disputed')),
    payment_method_id         UUID        NULL REFERENCES payment_methods (id),
    stripe_payment_intent_id  VARCHAR(100) NULL,
    stripe_charge_id          VARCHAR(100) NULL,
    failure_reason            VARCHAR(200) NULL,
    metadata                  JSONB       NOT NULL DEFAULT '{}',
    created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE        INDEX idx_payments_invoice_id            ON payments (invoice_id);
CREATE        INDEX idx_payments_payer_user_id         ON payments (payer_user_id);
CREATE        INDEX idx_payments_status                ON payments (status);
CREATE        INDEX idx_payments_stripe_pi_id          ON payments (stripe_payment_intent_id)
    WHERE stripe_payment_intent_id IS NOT NULL;
CREATE        INDEX idx_payments_stripe_charge_id      ON payments (stripe_charge_id)
    WHERE stripe_charge_id IS NOT NULL;

CREATE TRIGGER trg_payments_updated_at
    BEFORE UPDATE ON payments
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE payments ENABLE ROW LEVEL SECURITY;

CREATE POLICY payments_own_user ON payments
    FOR SELECT TO ah_app
    USING (
        payer_user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `stripe_payment_intent_id` format: `pi_XXXX`. This is the Stripe idempotency key for the charge.
- `stripe_charge_id` format: `ch_XXXX`. Set after the payment intent is confirmed.
- `metadata` may contain: `{"source": "lease_deposit", "lease_id": "...", "invoice_number": "..."}` — no PII, no payment method details.
- `failure_reason` comes from Stripe's decline codes (e.g., `insufficient_funds`, `card_declined`) — safe to display to the user.

---

### `refunds`

Partial or full refunds against a payment.

```sql
CREATE TABLE refunds (
    id               UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    payment_id       UUID        NOT NULL REFERENCES payments (id),
    amount_cents     BIGINT      NOT NULL,
    reason           VARCHAR(100) NULL,
    status           VARCHAR(10) NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending', 'succeeded', 'failed')),
    stripe_refund_id VARCHAR(100) NULL,
    processed_at     TIMESTAMPTZ NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_refunds_payment_id      ON refunds (payment_id);
CREATE INDEX idx_refunds_stripe_refund   ON refunds (stripe_refund_id) WHERE stripe_refund_id IS NOT NULL;
CREATE INDEX idx_refunds_status          ON refunds (status);
```

---

### `payouts`

Stripe Connect payouts to landowners. Represents money moving from the platform's Stripe account to the landowner's connected account.

```sql
CREATE TABLE payouts (
    id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    payee_user_id     UUID        NOT NULL,  -- References DB 1 (Identity) users.id (landowner)
    stripe_account_id VARCHAR(100) NOT NULL,  -- The landowner's Stripe Connect account ID
    amount_cents      BIGINT      NOT NULL,
    currency          CHAR(3)     NOT NULL DEFAULT 'USD',
    status            VARCHAR(15) NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'in_transit', 'paid', 'failed', 'cancelled')),
    stripe_payout_id  VARCHAR(100) NULL,
    stripe_transfer_id VARCHAR(100) NULL,
    scheduled_for     DATE        NULL,
    paid_at           TIMESTAMPTZ NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE        INDEX idx_payouts_payee_user_id    ON payouts (payee_user_id);
CREATE        INDEX idx_payouts_status           ON payouts (status);
CREATE        INDEX idx_payouts_stripe_payout    ON payouts (stripe_payout_id)   WHERE stripe_payout_id IS NOT NULL;
CREATE        INDEX idx_payouts_stripe_transfer  ON payouts (stripe_transfer_id) WHERE stripe_transfer_id IS NOT NULL;
CREATE        INDEX idx_payouts_scheduled_for    ON payouts (scheduled_for)      WHERE scheduled_for IS NOT NULL;

CREATE TRIGGER trg_payouts_updated_at
    BEFORE UPDATE ON payouts
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE payouts ENABLE ROW LEVEL SECURITY;

CREATE POLICY payouts_own_user ON payouts
    FOR SELECT TO ah_app
    USING (
        payee_user_id = current_setting('app.current_user_id')::UUID
        OR current_setting('app.current_role') IN ('staff', 'super_admin')
    );
```

**Notes:**
- `stripe_transfer_id` format: `tr_XXXX` — the transfer from the platform account to the connected account.
- `stripe_payout_id` format: `po_XXXX` — the payout from the connected account to the bank.
- Payouts are created by `ProcessLeasePaymentJob` after a successful payment clears the platform hold period (configurable in DB 12).

---

### `stripe_accounts`

Stripe Connect Express account records for landowners. Required before a landowner can receive payouts.

```sql
CREATE TABLE stripe_accounts (
    id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id                 UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    stripe_account_id       VARCHAR(100) NOT NULL,
    charges_enabled         BOOLEAN     NOT NULL DEFAULT false,
    payouts_enabled         BOOLEAN     NOT NULL DEFAULT false,
    details_submitted       BOOLEAN     NOT NULL DEFAULT false,
    onboarding_completed_at TIMESTAMPTZ NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_stripe_accounts_user_id         ON stripe_accounts (user_id);
CREATE UNIQUE INDEX uq_stripe_accounts_stripe_acct_id  ON stripe_accounts (stripe_account_id);

CREATE TRIGGER trg_stripe_accounts_updated_at
    BEFORE UPDATE ON stripe_accounts
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- `stripe_account_id` format: `acct_XXXX`.
- `charges_enabled` and `payouts_enabled` are updated via Stripe `account.updated` webhook events.
- A landowner cannot activate a property listing until `charges_enabled = true` and `payouts_enabled = true`.

---

### `subscriptions`

Membership plan subscriptions. References `plan_version_id` from DB 12 (Platform) — never store plan details here.

```sql
CREATE TABLE subscriptions (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id               UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    plan_version_id       UUID        NOT NULL,  -- References DB 12 (Platform) plan_versions.id
    stripe_subscription_id VARCHAR(100) NULL,
    stripe_customer_id    VARCHAR(100) NULL,
    status                VARCHAR(15) NOT NULL DEFAULT 'active'
                              CHECK (status IN ('active', 'trialing', 'past_due', 'cancelled', 'unpaid')),
    current_period_start  DATE        NOT NULL,
    current_period_end    DATE        NOT NULL,
    trial_ends_at         TIMESTAMPTZ NULL,
    cancelled_at          TIMESTAMPTZ NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_subscriptions_user_active ON subscriptions (user_id)
    WHERE status IN ('active', 'trialing', 'past_due');
CREATE        INDEX idx_subscriptions_user_id          ON subscriptions (user_id);
CREATE        INDEX idx_subscriptions_plan_version_id  ON subscriptions (plan_version_id);
CREATE        INDEX idx_subscriptions_status           ON subscriptions (status);
CREATE        INDEX idx_subscriptions_stripe_sub_id    ON subscriptions (stripe_subscription_id)
    WHERE stripe_subscription_id IS NOT NULL;
CREATE        INDEX idx_subscriptions_period_end       ON subscriptions (current_period_end);

CREATE TRIGGER trg_subscriptions_updated_at
    BEFORE UPDATE ON subscriptions
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- A user should have at most one non-cancelled subscription (enforced by the partial unique index).
- `plan_version_id` points to DB 12 `plan_versions.id` — use `EntitlementService` to resolve entitlements; never store plan details in this database.
- `stripe_subscription_id` format: `sub_XXXX`. May be null for manually-managed or legacy accounts.
- When status transitions to `cancelled`, `EntitlementService` invalidates the user's entitlement cache in Valkey Cluster 2.

---

### `tax_calculations`

TaxJar calculation results, cached per transaction. Used for invoice line items and 1099 reporting.

```sql
CREATE TABLE tax_calculations (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    payment_id            UUID        NOT NULL REFERENCES payments (id),
    taxjar_transaction_id VARCHAR(100) NULL,
    state_code            CHAR(2)     NOT NULL,
    tax_rate              NUMERIC(6,4) NOT NULL,  -- e.g., 0.0875 for 8.75%
    amount_taxable_cents  BIGINT      NOT NULL,
    tax_cents             BIGINT      NOT NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- Append-only — no updated_at, no deleted_at
);

CREATE UNIQUE INDEX uq_tax_calculations_payment ON tax_calculations (payment_id);
CREATE        INDEX idx_tax_calculations_state  ON tax_calculations (state_code);
```

**Notes:**
- Append-only — never update or delete tax calculation records. If a correction is needed, create a new record with an amended `payment_id` and note the correction in `metadata` on the payment.
- `tax_rate` stores the combined state + county + city rate returned by TaxJar.
- TaxJar is called by `TaxService::calculateTax()` before payment confirmation. The result is stored here immediately.

---

### `tax_1099_records`

Annual 1099-NEC and 1099-K records generated for landowners and sellers who receive payments above IRS thresholds. Filed via Tax1099 API.

```sql
CREATE TABLE tax_1099_records (
    id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    payee_user_id     UUID        NOT NULL,  -- References DB 1 (Identity) users.id
    tax_year          SMALLINT    NOT NULL,
    form_type         VARCHAR(10) NOT NULL
                          CHECK (form_type IN ('1099_nec', '1099_k')),
    gross_amount_cents BIGINT     NOT NULL,
    status            VARCHAR(15) NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'filed', 'corrected')),
    tax1099_record_id VARCHAR(100) NULL,  -- Tax1099 API record ID
    filed_at          TIMESTAMPTZ NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
    -- No deleted_at — tax records are permanent compliance documents
);

CREATE UNIQUE INDEX uq_tax_1099_payee_year_type ON tax_1099_records (payee_user_id, tax_year, form_type);
CREATE        INDEX idx_tax_1099_payee_user_id  ON tax_1099_records (payee_user_id);
CREATE        INDEX idx_tax_1099_tax_year       ON tax_1099_records (tax_year);
CREATE        INDEX idx_tax_1099_status         ON tax_1099_records (status);

CREATE TRIGGER trg_tax_1099_records_updated_at
    BEFORE UPDATE ON tax_1099_records
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
```

**Notes:**
- Never delete 1099 records. A correction creates a new row with `status = 'corrected'` referencing a new `tax1099_record_id`.
- `Generate1099RecordsJob` runs annually in January, aggregating `payouts` from the prior year and filing via Tax1099.
- IRS threshold for 1099-NEC: $600+ in a calendar year. Tax1099 API handles the threshold logic.

---

## Extended Billing Tables

> These four tables were named in the build roadmap (Phase 5.1) but had **no schema definition anywhere** until they were designed here. Build status as of 2026-06-15:
> - ✅ **`promo_codes`** — BUILT (migration `2026_06_15_000001`)
> - ✅ **`w9_records`** — BUILT (migration `2026_06_15_000002`); adds `w9_records` to the RLS list
> - ⏳ **`security_deposits`** — proposed, pending review (will add to RLS list when built)
> - ⏳ **`tax_nexus_tracking`** — proposed, pending review
>
> **Identifier note:** unlike the tables above (which still show the stale `set_updated_at()` / `app.current_role`), these use the **actual codebase identifiers**: trigger function `trigger_set_updated_at()` and RLS settings `app.current_user_id` + `app.user_role` (the `,true` "missing ok" flag is set by `InjectDatabaseContext`).

### `w9_records` ✅ *(BUILT 2026-06-15)*

IRS Form W-9 collected from every payee (landowner, seller, outfitter) before any 1099 can be filed. The TIN is the only highly-sensitive field and is **encrypted at rest** via the `HasEncryptedFields` trait — `pgp_sym_encrypt` base64-encoded into a `TEXT` column (Key D, `ENCRYPTION_KEY_BILLING`), exactly like `lease_applications.message`. A display-safe `tin_last_four` is kept for the UI. Permanent compliance record — no `deleted_at`.

```sql
CREATE TABLE w9_records (
    id                  UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id             UUID         NOT NULL,  -- References DB 1 (Identity) users.id (the payee)
    legal_name          VARCHAR(200) NOT NULL,
    business_name       VARCHAR(200) NULL,
    tax_classification  VARCHAR(20)  NOT NULL
                            CHECK (tax_classification IN
                                ('individual','sole_proprietor','c_corp','s_corp',
                                 'partnership','trust_estate','llc')),
    tin_type            VARCHAR(3)   NOT NULL CHECK (tin_type IN ('ssn','ein')),
    tin                 TEXT         NOT NULL,  -- pgp_sym_encrypt base64 (Key D) via HasEncryptedFields; in $hidden — never exposed
    tin_last_four       CHAR(4)      NOT NULL,  -- display-safe only
    address_line1       VARCHAR(200) NOT NULL,
    address_line2       VARCHAR(200) NULL,
    city                VARCHAR(100) NOT NULL,
    state_code          CHAR(2)      NOT NULL,
    postal_code         VARCHAR(10)  NOT NULL,
    backup_withholding  BOOLEAN      NOT NULL DEFAULT false,
    status              VARCHAR(15)  NOT NULL DEFAULT 'pending'
                            CHECK (status IN ('pending','verified','invalid','superseded')),
    certified_at        TIMESTAMPTZ  NULL,  -- when the payee e-signed/certified the W-9
    verified_at         TIMESTAMPTZ  NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
    -- No deleted_at — tax compliance record; a re-collected W-9 supersedes the old one (status='superseded')
);

CREATE UNIQUE INDEX uq_w9_records_user_active ON w9_records (user_id)
    WHERE status IN ('pending','verified');
CREATE        INDEX idx_w9_records_user_id    ON w9_records (user_id);
CREATE        INDEX idx_w9_records_status     ON w9_records (status);

CREATE TRIGGER trg_w9_records_updated_at
    BEFORE UPDATE ON w9_records
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE w9_records ENABLE ROW LEVEL SECURITY;

CREATE POLICY w9_records_own_user ON w9_records
    FOR SELECT TO ah_app
    USING (
        user_id = current_setting('app.current_user_id', true)::UUID
        OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
    );
```

**Notes:**
- The model uses `HasEncryptedFields` with `encryptedFields = ['tin']`, so assigning a plaintext TIN to `$model->tin` transparently encrypts on write and decrypts on read. `tin` is also in `$hidden` so it never serializes into API/array output.
- At most one `pending`/`verified` W-9 per user (partial unique index); re-collection sets the prior row to `superseded`.
- `tin_last_four` is the only TIN fragment ever returned to the UI. The decrypted TIN is read **only** at 1099-filing time inside `Generate1099RecordsJob`.
- **Certification flow (decided 2026-06-15 — in-app attestation, *not* Dropbox Sign).** The W-9 "signature" is a certification under penalties of perjury, and the IRS explicitly permits an electronic W-9. Rationale for in-app over Dropbox Sign: it keeps the TIN inside this isolated, PCI-scoped, encrypted-at-rest billing boundary instead of transmitting SSN/EIN to a third party; it carries no per-envelope cost across every payee; and it is the industry norm for self-certification forms. Dropbox Sign stays reserved for negotiated two-party lease contracts (Phase 4.5.5). Phase 5.2+ `TaxService::certifyW9()` must, to satisfy IRS electronic-W-9 requirements:
  1. present the full IRS penalties-of-perjury statement and the same content as the paper form;
  2. require an explicit affirmative certification act — typed legal name + an "Under penalties of perjury, I certify…" checkbox (payee is already authenticated, ideally behind MFA, which covers submitter-identity assurance);
  3. write an audit event to DB 9 capturing user_id, timestamp, IP, user-agent, and the exact certification-text version;
  4. generate a filled W-9 PDF snapshot stored in DB 11 (the IRS "produce a hard copy on request" requirement);
  5. only then set `certified_at` and advance `status`.
  No schema/model change is required — `w9_records` already has `certified_at`/`status`. (Note for 5.x: confirm the 1099-NEC vs Stripe-Connect-collected-1099-K split so we don't double-collect tax info.)

---

### `security_deposits` ⏳ *(proposed — pending review)*

Refundable damage deposit held for the life of a lease, then released, partially withheld, or forfeited at lease end. Funded by a dedicated `payments` row; forfeited funds are disbursed to the landowner via a `payouts` row.

```sql
CREATE TABLE security_deposits (
    id                     UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    lease_id               UUID        NOT NULL,  -- References DB 3 (Lease) leases.id
    payer_user_id          UUID        NOT NULL,  -- References DB 1 (Identity) users.id (lessee)
    payee_user_id          UUID        NOT NULL,  -- References DB 1 (Identity) users.id (landowner)
    payment_id             UUID        NULL REFERENCES payments (id),  -- the charge that funded the hold
    amount_cents           BIGINT      NOT NULL,
    refunded_amount_cents  BIGINT      NOT NULL DEFAULT 0,
    forfeited_amount_cents BIGINT      NOT NULL DEFAULT 0,
    currency               CHAR(3)     NOT NULL DEFAULT 'USD',
    status                 VARCHAR(20) NOT NULL DEFAULT 'pending'
                               CHECK (status IN
                                   ('pending','held','partially_released',
                                    'released','forfeited','refunded')),
    forfeit_reason         VARCHAR(200) NULL,
    stripe_payment_intent_id VARCHAR(100) NULL,  -- the hold/charge
    stripe_refund_id         VARCHAR(100) NULL,  -- the release refund
    held_at                TIMESTAMPTZ NULL,
    released_at            TIMESTAMPTZ NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT chk_security_deposits_amounts
        CHECK (refunded_amount_cents >= 0
               AND forfeited_amount_cents >= 0
               AND refunded_amount_cents + forfeited_amount_cents <= amount_cents)
);

CREATE        INDEX idx_security_deposits_lease_id   ON security_deposits (lease_id);
CREATE        INDEX idx_security_deposits_payer      ON security_deposits (payer_user_id);
CREATE        INDEX idx_security_deposits_payee      ON security_deposits (payee_user_id);
CREATE        INDEX idx_security_deposits_status     ON security_deposits (status);
CREATE        INDEX idx_security_deposits_payment_id ON security_deposits (payment_id) WHERE payment_id IS NOT NULL;

CREATE TRIGGER trg_security_deposits_updated_at
    BEFORE UPDATE ON security_deposits
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

**RLS Policy:**
```sql
ALTER TABLE security_deposits ENABLE ROW LEVEL SECURITY;

CREATE POLICY security_deposits_parties_and_staff ON security_deposits
    FOR SELECT TO ah_app
    USING (
        payer_user_id = current_setting('app.current_user_id', true)::UUID
        OR payee_user_id = current_setting('app.current_user_id', true)::UUID
        OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
    );
```

**Notes:**
- No `deleted_at` — a deposit is a financial record; it resolves via `status`, never deletes.
- Forfeiture is a disputed/adjudicated action: `forfeited_amount_cents` should only be set after an incident/dispute resolution (DB 10) and triggers a `payouts` row to the landowner. Partial releases set both `refunded_amount_cents` and `forfeited_amount_cents`.
- **Open decision:** hold mechanism — a separate captured charge (simplest, funds sit in platform balance) vs. a Stripe manual-capture authorization (cardholder funds held, but max 7-day auth window makes it unsuitable for season-long leases). Recommend a **separate charge + refund-on-release**; the auth-hold path does not fit lease durations.

---

### `promo_codes` ✅ *(BUILT 2026-06-15)*

> **Confirmed needed (2026-06-15):** the platform will run ad-hoc discounts, and **partners can be issued their own codes** — an outfitter code, a landowner code, etc. That requires many distinct code strings with per-code limits and partner attribution, which `promotional_periods` alone cannot express.
>
> Division of responsibility: the linked DB 12 `promotional_period` defines **what the discount is** (benefit, target audience, validity rules); `promo_codes` holds **the actual code strings**, their redemption limits, and **who the code is attributed to** (`owner_user_id`).

```sql
CREATE TABLE promo_codes (
    id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    promotional_period_id UUID        NOT NULL,  -- References DB 12 (Platform) promotional_periods.id (the benefit definition)
    code                  VARCHAR(50) NOT NULL,
    owner_user_id         UUID        NULL,  -- References DB 1 (Identity) users.id — partner the code is attributed to (outfitter/landowner); null = platform-wide
    max_redemptions       INTEGER     NULL,  -- null = unlimited
    redemption_count      INTEGER     NOT NULL DEFAULT 0,
    per_user_limit        SMALLINT    NOT NULL DEFAULT 1,
    starts_at             TIMESTAMPTZ NULL,
    expires_at            TIMESTAMPTZ NULL,
    is_active             BOOLEAN     NOT NULL DEFAULT true,
    created_by_user_id    UUID        NULL,  -- References DB 1 (Identity) users.id (admin who created it)
    notes                 TEXT        NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at            TIMESTAMPTZ NULL,

    CONSTRAINT chk_promo_codes_redemptions
        CHECK (redemption_count >= 0
               AND (max_redemptions IS NULL OR redemption_count <= max_redemptions))
);

CREATE UNIQUE INDEX uq_promo_codes_code     ON promo_codes (LOWER(code)) WHERE deleted_at IS NULL;
CREATE        INDEX idx_promo_codes_period  ON promo_codes (promotional_period_id);
CREATE        INDEX idx_promo_codes_owner   ON promo_codes (owner_user_id) WHERE owner_user_id IS NOT NULL;
CREATE        INDEX idx_promo_codes_active  ON promo_codes (is_active) WHERE is_active = true AND deleted_at IS NULL;

CREATE TRIGGER trg_promo_codes_updated_at
    BEFORE UPDATE ON promo_codes
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

**Notes:**
- No RLS — admin/partner-managed reference data, validated server-side at checkout (same posture as `refunds`/`stripe_accounts`). Partner-facing visibility of *their own* codes is enforced at the service/portal layer via `owner_user_id`, not RLS.
- `owner_user_id` is attribution only — it records which partner the code belongs to for reporting and partner dashboards. What the code *applies to* (e.g. only that outfitter's bookings) is expressed by the DB 12 `promotional_period`'s target, not here.
- `redemption_count` is incremented atomically inside `BillingService` when a `promotion_claims` row is created carrying this code; `promotion_claims.promo_code_used` stores the code string for the audit trail.
- Per-user enforcement (`per_user_limit`) is checked against existing `promotion_claims` for the user, not stored here.
- Code uniqueness is case-insensitive (`LOWER(code)`), scoped to non-deleted rows.

---

### `tax_nexus_tracking` ⏳ *(proposed — belongs with 5.5 TaxService)*

State-by-state economic-nexus tracking so the platform knows where it is obligated to collect sales tax. One row per US state; updated by a job that rolls up `tax_calculations`/`payments` by destination state (or syncs TaxJar's nexus API). This is operational reference data, not a per-user record.

```sql
CREATE TABLE tax_nexus_tracking (
    id                          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
    state_code                  CHAR(2)     NOT NULL,
    threshold_amount_cents      BIGINT      NULL,  -- e.g., 10000000 = $100k
    threshold_transaction_count INTEGER     NULL,  -- e.g., 200
    measurement_period          VARCHAR(25) NOT NULL DEFAULT 'previous_or_current_year'
                                    CHECK (measurement_period IN
                                        ('calendar_year','rolling_12_months','previous_or_current_year')),
    current_amount_cents        BIGINT      NOT NULL DEFAULT 0,
    current_transaction_count   INTEGER     NOT NULL DEFAULT 0,
    nexus_status                VARCHAR(15) NOT NULL DEFAULT 'none'
                                    CHECK (nexus_status IN ('none','approaching','met','registered')),
    registered_at               TIMESTAMPTZ NULL,  -- when AH registered to collect in this state
    taxjar_managed              BOOLEAN     NOT NULL DEFAULT true,
    last_evaluated_at           TIMESTAMPTZ NULL,
    notes                       TEXT        NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX uq_tax_nexus_state  ON tax_nexus_tracking (state_code);
CREATE        INDEX idx_tax_nexus_status ON tax_nexus_tracking (nexus_status);

CREATE TRIGGER trg_tax_nexus_tracking_updated_at
    BEFORE UPDATE ON tax_nexus_tracking
    FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
```

**Notes:**
- No RLS — internal/admin reference data.
- When `nexus_status` transitions to `met` while `registered_at IS NULL`, the evaluation job alerts admin to register in that state.
- This is the data backing `TaxService` (Phase 5.5); recommend building it **with** the tax-integration work rather than now, since nothing consumes it until TaxJar is wired up.

---

## Eloquent Models

### `App\Models\Billing\Invoice`

```php
<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $connection = 'billing';
    protected $table      = 'invoices';

    public $timestamps   = false;
    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'lease_id',
        'payer_user_id',
        'payee_user_id',
        'status',
        'subtotal_cents',
        'tax_cents',
        'platform_fee_cents',
        'total_cents',
        'currency',
        'stripe_invoice_id',
        'due_date',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date'   => 'date',
            'paid_at'    => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    // Cross-DB: resolved via LeaseService
    public function getLease(): ?\App\Models\Lease\Lease
    {
        if (! $this->lease_id) {
            return null;
        }
        return app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);
    }
}
```

### `App\Models\Billing\Payment`

```php
protected $connection = 'billing';
protected $table      = 'payments';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected $hidden     = ['stripe_payment_intent_id', 'stripe_charge_id'];  // Never log

protected function casts(): array
{
    return [
        'metadata'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

### `App\Models\Billing\PaymentMethod`

```php
protected $connection = 'billing';
protected $table      = 'payment_methods';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

// stripe_payment_method_id is in $hidden — never expose in API responses
protected $hidden     = ['stripe_payment_method_id'];

protected function casts(): array
{
    return [
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
```

### `App\Models\Billing\Subscription`

```php
protected $connection = 'billing';
protected $table      = 'subscriptions';
public $timestamps    = false;
public $incrementing  = false;
protected $keyType    = 'string';

protected $hidden     = ['stripe_subscription_id', 'stripe_customer_id'];

protected function casts(): array
{
    return [
        'current_period_start' => 'date',
        'current_period_end'   => 'date',
        'trial_ends_at'        => 'datetime',
        'cancelled_at'         => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];
}
```

---

## Service Notes

- **`BillingService`** — orchestrates invoice creation, payment collection, and status transitions. At `App\Services\Billing\BillingService`.
- **`StripeService`** — wraps Stripe SDK calls. Handles payment intent creation, webhook verification, and refund processing. At `App\Services\Billing\StripeService`.
- **`PayoutService`** — manages Stripe Connect transfer creation and payout scheduling for landowners. At `App\Services\Billing\PayoutService`.
- **`TaxService`** — calls TaxJar API and stores `tax_calculations`. At `App\Services\Billing\TaxService`.
- **Queue jobs:** `ProcessStripeWebhook` (priority queue), `ProcessLeasePayment`, `ScheduleLandowherPayout`, `Generate1099RecordsJob`, `SendPaymentReceiptJob`.
- Stripe webhook secret is validated by `StripeService::validateWebhookSignature()` before any webhook is processed. Invalid signatures result in a `403` with no data logged.
