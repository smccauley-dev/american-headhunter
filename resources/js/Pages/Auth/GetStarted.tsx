import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';

interface SelectedPlan {
    plan_key: string;
    display_name: string;
    account_type: string;
    is_paid: boolean;
}

interface GetStartedProps {
    plan?: SelectedPlan | null;
    interval?: 'monthly' | 'annual';
    service?: 'veteran' | 'first_responder' | null;
}

const ACCOUNT_TYPES = [
    { value: 'hunter',             label: 'Hunt',                       description: 'Find and lease hunting land' },
    { value: 'landowner',          label: 'Lease out my land',           description: 'List your property for hunting' },
    { value: 'club',               label: 'Start a hunting club',         description: 'Create and manage a club and its members' },
    { value: 'outfitter',          label: 'Offer guided hunts',           description: 'List outfitter packages and bookings' },
    { value: 'consultant',         label: 'Offer land consulting',        description: 'Wildlife biology, habitat management' },
    { value: 'seller',             label: 'Sell gear',                   description: 'List hunting equipment and apparel' },
] as const;

export default function GetStarted() {
    const { plan, interval = 'monthly', service } = usePage<GetStartedProps>().props;
    const [selected, setSelected] = useState<string>(plan?.account_type ?? 'hunter');

    function handleContinue() {
        // Carry the chosen plan only while the selected role still matches it —
        // switching roles away from the plan drops it. Interval rides along so the
        // register step (and Stripe) bill the cycle picked on the pricing page.
        // A veteran / first-responder flag carries through to preselect that step.
        const carryPlan = plan && plan.account_type === selected;
        router.get('/register', {
            type: selected,
            ...(carryPlan ? { plan: plan.plan_key, interval } : {}),
            ...(service ? { service } : {}),
        });
    }

    return (
        <AuthLayout
            chapter="Account Setup"
            headline="Join <em>American</em><br/>Headhunter"
            subheadline="The platform that connects serious hunters with the land they need."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 8 }}>
                I want to…
            </h1>
            <p style={{ fontSize: 15, color: '#4a5440', marginBottom: plan ? 16 : 28, fontFamily: "'Crimson Pro', Georgia, serif" }}>
                Choose your primary role. You can add more later.
            </p>

            {plan && (
                <div style={{ marginBottom: 28, padding: '12px 14px', background: '#0a1512', borderLeft: '4px solid #b8934a' }}>
                    <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.15em', textTransform: 'uppercase', color: '#b8934a' }}>
                        Continuing with{' '}
                    </span>
                    <span style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 16, color: '#f4ecdc' }}>
                        {plan.display_name}
                    </span>
                </div>
            )}

            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 28 }}>
                {ACCOUNT_TYPES.map(({ value, label, description }) => (
                    <button
                        key={value}
                        onClick={() => setSelected(value)}
                        style={{
                            display: 'flex', alignItems: 'center', gap: 14,
                            padding: '14px 16px', background: selected === value ? '#0a1512' : '#f4ecdc',
                            border: `1px solid ${selected === value ? '#0a1512' : '#a89874'}`,
                            borderRadius: 0, cursor: 'pointer', textAlign: 'left', width: '100%',
                            transition: 'all 0.15s',
                        }}
                    >
                        <span style={{
                            width: 18, height: 18, borderRadius: '50%',
                            border: `2px solid ${selected === value ? '#c84c21' : '#a89874'}`,
                            background: selected === value ? '#c84c21' : 'transparent',
                            flexShrink: 0, transition: 'all 0.15s',
                        }} />
                        <div>
                            <div style={{
                                fontFamily: "'JetBrains Mono', monospace", fontSize: 12,
                                fontWeight: 500, letterSpacing: '0.08em',
                                color: selected === value ? '#e8dcc4' : '#0a1512',
                            }}>{label}</div>
                            <div style={{
                                fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 13,
                                color: selected === value ? '#a89874' : '#6b7856', marginTop: 2,
                            }}>{description}</div>
                        </div>
                    </button>
                ))}
            </div>

            <button
                onClick={handleContinue}
                style={{
                    width: '100%', padding: '13px 24px', background: '#c84c21',
                    border: 'none', borderRadius: 0, cursor: 'pointer',
                    fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                    fontWeight: 600, letterSpacing: '0.15em', color: '#fff',
                    textTransform: 'uppercase', marginBottom: 20,
                }}
            >
                Continue →
            </button>

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440' }}>
                Already have an account?{' '}
                <a href="/login" style={{ color: '#c84c21', textDecoration: 'none' }}>Sign in</a>
            </p>
        </AuthLayout>
    );
}
