# Stripe Test-Mode Manual Test — Connect lease-rent collection

A no-real-money walkthrough for the Phase 5.5 destination-charge flow (lease rent +
booking deposit). Everything here runs against Stripe **test mode**; no live key is
involved and no real money can move.

---

## 0. Confirm you are in test mode (safety first)

Test keys always start with `…_test_`. A live charge is impossible without an
`sk_live_` key, which must never be in your dev `.env`.

```
STRIPE_KEY=pk_test_…          # publishable (safe to expose; used in the browser)
STRIPE_SECRET=sk_test_…       # secret (server-side only)
STRIPE_WEBHOOK_SECRET=whsec_… # see step 2 — depends on CLI vs dashboard endpoint
```

Quick auth + mode check (read-only — creates nothing):

```bash
wsl -e bash -c "cd /home/zeroaccess/projects/AmericanHeadhunter && docker compose exec -T app sh -lc 'cd /var/www/html && php artisan tinker --execute=\"\Stripe\Stripe::setApiKey(config(\\\"services.stripe.secret\\\")); echo (\Stripe\Balance::retrieve()->livemode ? \\\"LIVE\\\" : \\\"TEST\\\");\"'"
```

Expect `TEST`. (If you prefer, the same check the team ran lists Connect accounts and
their `charges_enabled` flags.)

---

## 1. Test cards & onboarding test values

**Card numbers** (any future expiry, any 3-digit CVC, any ZIP):

| Card | Behavior |
|---|---|
| `4242 4242 4242 4242` | Succeeds immediately |
| `4000 0025 0000 3155` | Requires 3-D Secure authentication |
| `4000 0000 0000 9995` | Declined (insufficient funds) |

**Express onboarding test identity** (when onboarding a landowner to `charges_enabled`):

| Field | Test value |
|---|---|
| SSN / Tax ID | `000-00-0000` |
| Routing number | `110000000` |
| Account number | `000123456789` |
| Phone / OTP | use Stripe's "skip phone verification" test link, or any number → OTP `000000` |
| DOB / address | any valid-looking values |

> **Shortcut:** the test account already has Connect accounts with
> `charges_enabled=Y`. You can point a test lease's landowner at one of those instead
> of onboarding a fresh account. The two ready ones at last check were
> `acct_1TmbQyAgyeRaJqXp` and `acct_1Tjp3ZAmDFHRlYub` — re-list with the read-only
> check above to get the current set.

---

## 2. Forward webhooks to your local app (Stripe CLI)

The destination charge records the `lease_payments` row via
`POST /api/webhooks/stripe`. The `db.system` success-return reconciles it too, so the
flow works even if the webhook is slightly delayed — but for a faithful test, forward
events with the CLI:

```bash
# Authenticate the CLI once (uses the CLI key, not your sk_test_):
stripe login

# Forward live test events to the local app (app runs on http://localhost:80):
stripe listen --forward-to http://localhost/api/webhooks/stripe
```

`stripe listen` prints its **own** signing secret:

```
> Ready! Your webhook signing secret is whsec_xxxxxxxx
```

While that CLI session is running, set `STRIPE_WEBHOOK_SECRET` in `.env` to **that**
value, then recreate the app container so it loads (env changes are inert until then):

```bash
wsl -e bash -c "cd /home/zeroaccess/projects/AmericanHeadhunter && docker compose up -d --force-recreate app"
```

> If instead you test against a webhook endpoint registered in the Stripe **dashboard**
> (Developers → Webhooks), use that endpoint's signing secret and skip the CLI. The
> `whsec_…` already in `.env` is from a previously-registered endpoint.

---

## 3. Map a test lease to a charges-enabled landowner

The pay-lease-balance button only enables when the landowner's connected account has
`charges_enabled=true`. To test:

1. Pick a lease whose `lessor_user_id` is a landowner you control, **or** seed one.
2. Ensure that landowner has a `stripe_accounts` row whose `stripe_account_id` is a
   test Connect account with `charges_enabled=Y` (either one you onboarded in step 1,
   or one of the ready-made accounts above).
3. Confirm `total_price` on the lease is set so there is a non-zero balance due
   (`rent_balance = total_price − booking deposit collected − prior collected
   lease_payments`).

---

## 4. Run the flow

1. Sign in to the **Member portal** as the lessee and open that lease
   (`/member/leases/{lease}`).
2. The **Pay lease balance** card shows the amount due plus the (optional) processing
   surcharge. If it reads "Awaiting landowner payout setup," the landowner is not
   `charges_enabled` — fix step 3.
3. Click pay → you land on Stripe **hosted Checkout**. Pay with `4242 4242 4242 4242`.
4. You're redirected to the success return
   (`/member/leases/{lease}/lease-payment/return`), which reconciles the row and shows
   `?payment=paid`.

---

## 5. Verify the result

**In the app — admin Lease Payments** (`/admin`, Billing → Lease Payments):

- A row with `status = collected`, and `gross / application_fee / net` matching the
  money model: `application_fee = tier_fee + surcharge`,
  `net = gross − application_fee`.

**In the Stripe test dashboard** (Payments / Connect → Transfers):

- A PaymentIntent on the **platform** account for the gross amount.
- An `application_fee` retained by the platform.
- A **destination transfer** to the landowner's connected account for the net.
- The charge attributed to the landowner via `on_behalf_of` (check the charge's
  "On behalf of" field).

> **Resolved (verified 2026-06-28, test mode):** under `on_behalf_of` with the charge
> created on the platform, **the platform bears the Stripe processing fee** — not the
> connected account. On a $225 charge the landowner's own balance transaction deducted
> only the $11.25 application fee (net $213.75, matching the recorded `net_cents`); the
> $6.83 Stripe card fee came out of the platform's balance, netting the platform $4.42.
> The `FeeService` processing surcharge is the lever to pass that Stripe cost back to
> the customer (it was $0 here only because no `lease_payment` fee rule is seeded for
> the property's state).

> **Two prerequisites learned the hard way (both now fixed in code):**
> 1. The connected account must hold the **`card_payments`** capability, not just
>    `transfers` — `on_behalf_of` makes it the settlement merchant. Accounts onboarded
>    with transfers-only must re-onboard (card_payments needs full KYC).
> 2. The lessee views the lease as `ah_runtime`, so the landowner's identity row,
>    Connect account, and fee tier are all RLS-hidden from them; the pay-flow resolves
>    those reads under `ah_system`. (The test suite runs as the table owner and bypasses
>    RLS, so this only surfaces in a real browser request.)

---

## 6. Refund (admin)

On the admin Lease Payment **View** page, use the **Refund** action (full or partial).
It calls `refundDestinationCharge` with `reverse_transfer=true` +
`refund_application_fee=true`, so the net is clawed back from the landowner and the
platform fee is returned. The row flips to `refunded` / `partially_refunded`. Confirm
the reversal appears on the connected account in the dashboard.

---

## 7. Booking deposit (same model)

The booking-deposit flow now uses the identical destination charge. Collecting one
records the `booking_deposits` row as `disbursed` (net transferred at charge time) with
`stripe_transfer_id` captured — verify the same way as steps 4–5.

---

## Going live (later, production only)

When you're ready to take real money, put the `sk_live_` / `pk_live_` keys and a live
webhook signing secret **only in the production server's secrets** (Key Vault / env on
Azure or on-prem) — never in dev. No application code changes; it's an env-only switch.
The landowner's **1099-K** is issued by **Stripe Connect Tax Forms** (Stripe is the PSE
under `on_behalf_of`); enable it in the Connect dashboard at that point — no app code.
