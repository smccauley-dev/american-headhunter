# Phase 5 Completion Plan — Stripe Billing Pipeline

> Status as of 2026-06-27. Branch: `feature/phase5-stripe-completion`.
> Most of Phase 5 (5.1–5.7) is **already built but mocked**; Stripe **test** keys
> are now in `.env`, which was the long-standing blocker. This plan is therefore
> mostly *verify-and-close-gaps*, not build-from-scratch, sequenced by risk.

---

## Guiding fact

Nearly everything in 5.1–5.7 exists in code with mocked tests. The genuine work is
(a) proving the built pipeline works against real Stripe test mode, (b) one fee /
refund money-movement gap, (c) a couple of small missing jobs, and (d) tax.

What is already built (roadmap checkboxes for 5.3/5.4/5.5 are stale):

| Capability | State |
|---|---|
| `/api/webhooks/stripe` route + `StripeWebhookController` (signature-verified) | built |
| `ProcessStripeWebhook` — checkout.completed, subscription.updated/deleted, invoice.payment_failed (dunning→past_due), payment_intent.succeeded, account.updated, invoice.* projection, charge.refunded | built |
| `StripeService` — subscription Checkout, customer, product/price/coupon sync, plan change, payment-method update, Connect (account/link/transfer), deposit checkout, refunds | built |
| Subscription Checkout flow (`MembershipCheckoutService`) | built |
| `/pricing` page + `PricingController` | built |
| Promo auto-apply (`PromotionAutoApplyService`: signup = Founding Landowner, first-listing = Honeymoon) | built |
| Connect onboarding (`PayoutController`) | built |
| Invoice projection read model (5.7) | built |
| Admin pricing/promo config (5.6) | complete |

Stripe config present in `.env`: `STRIPE_KEY` (pk_test), `STRIPE_SECRET` (sk_test),
`STRIPE_WEBHOOK_SECRET` (whsec).

---

## Sequence

1. **Slice 1** — Live verification of the built pipeline (de-risk first).
2. **Slice 1.5** — Fee model & refund cost allocation (changes money movement).
3. **Slice 2** — `ExpirePromotionClaims` job.
4. **Slice 3** — Tax & 1099 (gated on TaxJar/Tax1099 creds + 1099 classification).
5. **Deferred** — `DisburseLandownerPayout` (needs lease-rent collection upstream).

---

## Slice 1 — Live verification of the built pipeline

**Goal:** every built flow round-trips against Stripe test mode; fix whatever the
mocks hid. No new schema. Verification log appended here as it runs.

Automatable from the container (no browser):
1. **Config loads** — `config('services.stripe')` resolves all three keys.
2. **Live API connectivity** — a real test-mode call (retrieve account / list
   products) confirms `sk_test` works end-to-end.
3. **Create-path checks** — `createSubscriptionCheckoutSession`,
   `createDepositCheckoutSession`, `createConnectAccount` + `createAccountLink`
   actually return live test-mode objects/URLs.
4. **Webhook signature verification** — `constructWebhookEvent` validates a
   correctly-signed payload and rejects a bad one.

Human-in-the-loop (browser / Stripe CLI):
5. **Webhook delivery** — `stripe listen --forward-to .../api/webhooks/stripe`;
   confirm dispatch to the `priority` queue. `stripe trigger checkout.session.completed`.
6. **Subscription Checkout end-to-end** — `/pricing` → plan → hosted Checkout
   (test card `4242…`) → `checkout.session.completed` → subscription written + plan
   version locked → `EntitlementService` flips to paid.
7. **Dunning** — `stripe trigger invoice.payment_failed` → subscription `past_due`,
   user notified.
8. **Connect onboarding round-trip** — landowner onboarding → `account.updated`
   syncs `charges_enabled`/`payouts_enabled` → `canReceivePayouts()` true.
9. **Refund → projection** — admin refund → `charge.refunded` → projection
   `refund_status` updates; daily reconcile heals a dropped event.
10. **Promo auto-apply live** — landowner signup (Founding Landowner) + first
    listing publish (Honeymoon) create claim rows + Stripe coupon.

**Output:** verification log below; ticks on the Phase 5 Milestone; bug-fix commits
for anything that fails.

### Slice 1 verification log

**2026-06-27 — automatable checks (container, test mode): all green.**

| # | Check | Result |
|---|---|---|
| 1 | Config loads | ✓ `key`/`secret`/`webhook_secret`/`connect_client_id` present (pk_test, sk_test, whsec) |
| 2 | Live API connectivity | ✓ authenticated as `acct_1TjfAsAmDFq96cgK` (US); `Product::all` → 5 products (plans synced to test mode) |
| 3 | Create paths | ✓ `createDepositCheckoutSession` minted `cs_test_…` with hosted URL; `hunter_pro` → `prod_Uji5SWEkn9df4q` (subscription price available) |
| 4 | Webhook signature | ✓ valid signature accepted (`ping`); tampered signature rejected (`SignatureVerificationException`) |

**Flag:** platform account `charges_enabled=false` — expected in test mode (no activation needed), but must be resolved before go-live.

**Remaining — human-in-the-loop (needs Stripe CLI `stripe listen` + browser test card `4242…`):**
- [ ] 5. Webhook delivery round-trip → `priority` queue (queue worker running + `stripe listen --forward-to <app>/api/webhooks/stripe`)
- [ ] 6. Subscription Checkout completion → subscription written + plan version locked → entitlement flips
- [ ] 7. Dunning (`stripe trigger invoice.payment_failed` → `past_due`)
- [ ] 8. Connect onboarding round-trip (`account.updated` → `payouts_enabled`)
- [ ] 9. Refund → `charge.refunded` → projection `refund_status`
- [ ] 10. Promo auto-apply (Founding Landowner on signup, Honeymoon on first listing)

Handoff commands for the manual steps are in the status report; these gate the Phase 5 Milestone but not Slice 1.5.

---

## Slice 1.5 — Fee model & refund cost allocation

**Branch note:** folded into `feature/phase5-stripe-completion`.

### Why
- Stripe charges **2.9% + $0.30** (US) and **keeps its fee on refunds**.
- Charges use the **separate charges & transfers** model → American Headhunter is
  merchant of record and pays the Stripe fee, then `createTransfer`s net to the
  landowner.
- **Latent bug:** refunds (`refundInvoice` / `refundPaymentIntent`) do **no**
  `Transfer::createReversal`, so refunding an already-disbursed payment leaves the
  landowner overpaid and AH eating both the refund and the fee. **Fix this first.**

### Decisions (locked 2026-06-27)
- **Processing fee scope:** transaction category **×** state/region (NULL state =
  all; specific row wins). Account tier stays separate (existing `platform_fee_pct`).
- **Processing fee payer:** **customer surcharge** — visible checkout line item;
  landowner net unaffected.
- **Refund Stripe-fee recovery:** **landowner bears it.** Partial refund → claw
  back the **full fixed $0.30** on any refund + pro-rate the 2.9% by refund
  fraction, via `Transfer::createReversal` of (landowner net portion + allocated
  Stripe fee). Read the actual fee from `balance_transaction.fee`, don't estimate.

### Build
- **`fee_schedules`** table (DB 4, admin-editable, no RLS): `transaction_category`
  (`lease|auction|outfitter_booking|security_deposit|marketplace`), `state_code`
  NULL, `pct` NULL, `flat_cents` NULL, `payer` (default `customer`), `is_active`,
  `effective_from`/`effective_to`, timestamps + soft delete. Most-specific match wins.
- **`FeeService`** — `processingFee(category, stateCode, baseCents)`; Valkey-cached
  (Cluster 2), invalidated on admin edit.
- **Checkout** — add processing fee as a second line item (customer-visible);
  metadata carries `processing_fee_cents`.
- **`PayoutService::quote`** — record processing fee for reporting (not deducted
  from landowner net since customer-surcharged).
- **Refund path rebuilt** — add `Transfer::createReversal`; reversal = landowner
  refunded net + allocated Stripe fee (full $0.30 + pro-rated %); read actual fee
  from `balance_transaction.fee`; write a `refund_allocations` record (who bore what).
- **`FeeScheduleResource`** (Filament, Billing group, `canManagePricing`); flush
  FeeService cache on save (+ `filament:clear-cached-components && filament:cache-components`).

### Verify (test keys)
- Checkout shows processing-fee line; customer charged base + fee.
- Refund a disbursed lease payment → transfer reversed by net + full $0.30 +
  pro-rated %; AH out $0.
- Partial refund → fixed fee fully recovered, % pro-rated.
- `FeeScheduleTest`, refund-allocation tests, RLS/admin gate test, `npm run build`.

---

## Slice 2 — `ExpirePromotionClaims` job

The one missing 5.4 piece.
- `App\Jobs\Billing\ExpirePromotionClaims` — daily (`default` queue, `ah_system`).
  Reads `promotion_claims.expires_at`; transitions per the claim's `on_expiration`
  (→ paid tier vs downgrade); invalidates Valkey entitlement cache for affected users.
- Warning emails at **30d / 7d / 1d** before expiry (reuse Phase-1 email infra).
- Schedule in `routes/console.php` alongside the 04:00 jobs.
- Tests: expiry→downgrade, expiry→paid, warning-window dispatch, idempotency.

---

## Slice 3 — Tax & 1099 (5.5)

Largest greenfield; **gate on credentials + the 1099-NEC vs Connect 1099-K decision**
(roadmap line 710 open).
- `tax_nexus_tracking` table (DB 4, no RLS, one row/state) — migrate with the service.
- `App\Services\Billing\TaxService` — `calculate()` via TaxJar at checkout;
  `certifyW9()` in-app attestation (decided 2026-06-15).
- `App\Jobs\Billing\Generate1099` — January; qualifying recipients; files via Tax1099;
  writes `tax_1099_records`.
- Tests against mocked TaxJar/Tax1099.

---

## Deferred — `DisburseLandownerPayout` (lease rent)

No lease-rent **collection** flow exists upstream (`BillingService` is
subscription-only). Build with the lease-rent collection milestone, not now.
