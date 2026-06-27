import { useState } from 'react';
import { router } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';

interface Plan {
    plan_key: string;
    display_name: string;
    tagline?: string | null;
    monthly_price_cents: number;
    annual_price_cents: number;
    monthly_enabled: boolean;
    annual_enabled: boolean;
    badge_label?: string | null;
    perks?: { label: string; description?: string | null }[];
}

interface ReactivateProps {
    plans: Plan[];
    // 'cancel' = backed out of Stripe; 'processing' = paid, webhook still catching up.
    checkout?: string | null;
}

function dollars(cents: number): string {
    return `$${(cents / 100).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

export default function Reactivate({ plans, checkout }: ReactivateProps) {
    const [interval, setInterval] = useState<'monthly' | 'annual'>('monthly');
    const [submitting, setSubmitting] = useState<string | null>(null);

    function subscribe(planKey: string) {
        setSubmitting(planKey);
        router.post('/reactivate/checkout', { plan_key: planKey, interval }, {
            onFinish: () => setSubmitting(null),
        });
    }

    const toggleBtn = (value: 'monthly' | 'annual', label: string) => (
        <button
            onClick={() => setInterval(value)}
            style={{
                flex: 1, padding: '10px 16px', borderRadius: 0, cursor: 'pointer',
                fontFamily: "'JetBrains Mono', monospace", fontSize: 11, fontWeight: 600,
                letterSpacing: '0.15em', textTransform: 'uppercase',
                border: '1px solid #0a1512',
                background: interval === value ? '#0a1512' : 'transparent',
                color: interval === value ? '#f4ecdc' : '#0a1512',
            }}
        >
            {label}
        </button>
    );

    return (
        <AuthLayout
            chapter="Membership"
            headline="Welcome<br/>back."
            subheadline="Resume your membership to pick up where you left off."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 8 }}>
                Your account is paused
            </h1>
            <p style={{ fontSize: 16, color: '#4a5440', marginBottom: 24, fontFamily: "'Crimson Pro', Georgia, serif", lineHeight: 1.6 }}>
                Your promotional period has ended. Choose a plan below to reactivate your
                membership — you'll be back in your account the moment payment clears.
            </p>

            {checkout === 'processing' && (
                <div style={{ marginBottom: 20, padding: '12px 16px', background: '#0a1512', borderLeft: '4px solid #b8934a', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#f4ecdc' }}>
                    Payment received — we're reactivating your account. This page will let you
                    in as soon as it's ready; refresh in a moment if it doesn't update.
                </div>
            )}

            {checkout === 'cancel' && (
                <div style={{ marginBottom: 20, padding: '12px 16px', background: '#f4ecdc', border: '1px solid #a89874', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#142420', lineHeight: 1.5 }}>
                    No payment was taken — your account is still paused. Choose a plan below
                    whenever you're ready.
                </div>
            )}

            {plans.length === 0 ? (
                <div style={{ padding: '20px', background: '#f4ecdc', border: '1px solid #a89874', marginBottom: 24 }}>
                    <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440', margin: 0 }}>
                        No plans are available to reactivate right now. Please contact support.
                    </p>
                </div>
            ) : (
                <>
                    <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
                        {toggleBtn('monthly', 'Monthly')}
                        {toggleBtn('annual', 'Annual')}
                    </div>

                    {plans.map((plan) => {
                        const cents = interval === 'annual' ? plan.annual_price_cents : plan.monthly_price_cents;
                        const enabled = interval === 'annual' ? plan.annual_enabled : plan.monthly_enabled;

                        return (
                            <div key={plan.plan_key} style={{ padding: '20px', border: '1px solid #a89874', marginBottom: 16 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 6 }}>
                                    <span style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 20, color: '#0a1512' }}>
                                        {plan.display_name}
                                    </span>
                                    {enabled && (
                                        <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 14, color: '#0a1512' }}>
                                            {dollars(cents)}/{interval === 'annual' ? 'yr' : 'mo'}
                                        </span>
                                    )}
                                </div>
                                {plan.tagline && (
                                    <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440', margin: '0 0 12px' }}>
                                        {plan.tagline}
                                    </p>
                                )}
                                {plan.perks && plan.perks.length > 0 && (
                                    <ul style={{ listStyle: 'none', padding: 0, margin: '0 0 16px' }}>
                                        {plan.perks.map((perk, i) => (
                                            <li key={i} style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440', marginBottom: 4 }}>
                                                — {perk.label}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <button
                                    onClick={() => subscribe(plan.plan_key)}
                                    disabled={!enabled || submitting !== null}
                                    style={{
                                        width: '100%', padding: '13px 24px', borderRadius: 0,
                                        cursor: !enabled || submitting !== null ? 'not-allowed' : 'pointer',
                                        fontFamily: "'JetBrains Mono', monospace", fontSize: 11, fontWeight: 600,
                                        letterSpacing: '0.15em', textTransform: 'uppercase',
                                        border: '1px solid #0a1512',
                                        background: enabled ? '#0a1512' : 'transparent',
                                        color: enabled ? '#f4ecdc' : '#a89874',
                                    }}
                                >
                                    {submitting === plan.plan_key ? 'Redirecting…' : enabled ? 'Reactivate with this plan' : 'Not available'}
                                </button>
                            </div>
                        );
                    })}
                </>
            )}

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440', marginTop: 8 }}>
                <a href="/logout" style={{ color: '#c84c21', textDecoration: 'none' }}
                    onClick={e => { e.preventDefault(); router.post('/logout'); }}>
                    Sign out
                </a>
            </p>
        </AuthLayout>
    );
}
