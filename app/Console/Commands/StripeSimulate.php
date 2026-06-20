<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\StripeWebhookController;
use App\Services\Billing\StripeService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Local dev tool — fires a properly-signed Stripe webhook at the local
 * endpoint without needing the Stripe CLI or ngrok. The payload is signed
 * with STRIPE_WEBHOOK_SECRET using Stripe's scheme so it passes real
 * signature verification end-to-end.
 *
 * Usage:
 *   php artisan stripe:simulate customer.subscription.updated --id=sub_123 --status=active
 *   php artisan stripe:simulate invoice.payment_failed --id=sub_123
 *   php artisan stripe:simulate payment_intent.succeeded --id=pi_123
 *   php artisan stripe:simulate account.updated --account=acct_123
 */
class StripeSimulate extends Command
{
    protected $signature = 'stripe:simulate
        {type : Stripe event type (e.g. customer.subscription.updated)}
        {--id= : Stripe object id (subscription id, payment intent id, …)}
        {--account= : Stripe connected account id (for account.updated)}
        {--status=active : Subscription status (for customer.subscription.updated)}';

    protected $description = 'Fire a signed Stripe webhook event at the local endpoint for dev testing';

    public function handle(StripeService $stripe): int
    {
        $type = $this->argument('type');

        $object = match ($type) {
            'customer.subscription.updated' => [
                'id'                   => $this->option('id') ?: 'sub_simulated',
                'status'               => $this->option('status'),
                'current_period_start' => now()->timestamp,
                'current_period_end'   => now()->addMonth()->timestamp,
            ],
            'invoice.payment_failed' => [
                'id'           => 'in_simulated',
                'subscription' => $this->option('id') ?: 'sub_simulated',
            ],
            'payment_intent.succeeded' => [
                'id'            => $this->option('id') ?: 'pi_simulated',
                'latest_charge' => 'ch_simulated',
            ],
            'account.updated' => [
                'id'                => $this->option('account') ?: 'acct_simulated',
                'charges_enabled'   => true,
                'payouts_enabled'   => true,
                'details_submitted' => true,
            ],
            default => null,
        };

        if ($object === null) {
            $this->error("Unsupported event type for simulation: {$type}");
            return self::FAILURE;
        }

        $payload = json_encode([
            'id'      => 'evt_' . bin2hex(random_bytes(8)),
            'type'    => $type,
            'data'    => ['object' => $object],
            'object'  => 'event',
        ]);

        $timestamp = time();
        $secret    = (string) config('services.stripe.webhook_secret');
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $sigHeader = "t={$timestamp},v1={$signature}";

        $request = Request::create('/api/webhooks/stripe', 'POST', [], [], [], [], $payload);
        $request->headers->set('Stripe-Signature', $sigHeader);

        $response = app(StripeWebhookController::class)->handle($request, $stripe);

        $this->info("Event: {$type}");
        $this->info("Webhook response: {$response->getStatusCode()} — {$response->getContent()}");
        $this->info('Job dispatched to the priority queue. Run `php artisan queue:work --queue=priority` to process it.');

        return self::SUCCESS;
    }
}
