import { useState, FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';
import AuthInput from '@/Components/Auth/AuthInput';
import { US_STATES } from '@/lib/usStates';
import { formatPhoneInput } from '@/lib/phone';

interface LegalUrls {
    tos_url: string;
    privacy_url: string;
    ccpa_url: string;
}

interface SignupPromo {
    headline: string;
    detail: string;
}

interface SignupPlan {
    plan_key: string;
    display_name: string;
    account_type: string;
    is_paid: boolean;
    monthly_price_cents: number;
    annual_price_cents: number;
}

interface RegisterProps {
    accountType: string;
    legalUrls: LegalUrls;
    signupPromo?: SignupPromo | null;
    signupPlan?: SignupPlan | null;
    signupInterval?: 'monthly' | 'annual';
    // Preselects the veteran / first-responder step when a pricing callout link
    // carried a ?service= flag through get-started.
    signupService?: 'veteran' | 'first_responder' | null;
    serviceMethods?: { veteran: string; first_responder: string };
    errors?: Record<string, string>;
}

type ServiceStatus = '' | 'veteran' | 'first_responder';

const SERVICE_LABELS: Record<Exclude<ServiceStatus, ''>, string> = {
    veteran:         'Veteran',
    first_responder: 'First Responder',
};

// What proof each path accepts, shown under the upload control.
const SERVICE_PROOF_HINT: Record<Exclude<ServiceStatus, ''>, string> = {
    veteran:         'DD-214, military ID, or VA card (PDF, JPG, or PNG).',
    first_responder: 'Department ID, badge, or certification (PDF, JPG, or PNG).',
};

function dollars(cents: number): string {
    return (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: cents % 100 === 0 ? 0 : 2 });
}

const ACCOUNT_TYPE_LABELS: Record<string, string> = {
    hunter:     'Hunter',
    landowner:  'Landowner',
    club:       'Hunting Club',
    outfitter:  'Outfitter',
    consultant: 'Land Consultant',
    seller:     'Marketplace Seller',
};

export default function Register() {
    const { accountType, legalUrls, signupPromo, signupPlan, signupInterval = 'monthly', signupService, serviceMethods, errors = {} } = usePage<RegisterProps>().props;

    const paid       = signupPlan?.is_paid ?? false;
    const hasMonthly = (signupPlan?.monthly_price_cents ?? 0) > 0;
    const hasAnnual  = (signupPlan?.annual_price_cents ?? 0) > 0;

    const [form, setForm] = useState({
        account_type:      accountType,
        first_name:        '',
        last_name:         '',
        email:             '',
        password:          '',
        password_confirmation: '',
        date_of_birth:     '',
        state_code:        '',
        phone:             '',
        tos_accepted:      false,
        privacy_accepted:  false,
        plan:              signupPlan?.plan_key ?? '',
        // Billing cycle for a paid plan — defaults to the cycle picked on the
        // pricing page, falling back to whichever the plan actually offers.
        interval:          (signupInterval === 'annual' && hasAnnual) ? 'annual' : (hasMonthly ? 'monthly' : 'annual'),
        // Optional service-status step (empty = skipped). A File here makes the
        // POST multipart so the proof reaches the server. Preselected when a
        // pricing callout deep-linked a veteran / first-responder flag.
        service_status:    (signupService ?? '') as ServiceStatus,
        service_proof:     null as File | null,
    });
    const [processing, setProcessing] = useState(false);
    // When a pricing callout deep-linked a service flag, lock the step to that
    // choice and hide the other options — the "Skip this" link unlocks the full
    // toggle for anyone who clicked the wrong button or doesn't qualify.
    const [serviceLocked, setServiceLocked] = useState<boolean>(!!signupService);

    function set<K extends keyof typeof form>(field: K, value: (typeof form)[K]) {
        setForm(f => ({ ...f, [field]: value }));
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post('/register', form, {
            onFinish: () => setProcessing(false),
        });
    }

    const typeLabel = ACCOUNT_TYPE_LABELS[accountType] ?? accountType;

    return (
        <AuthLayout
            chapter={`${typeLabel} — Step 1 of 3`}
            headline="Create your<br/><em>account</em>."
            subheadline="Start with the basics — name, email, and a secure password."
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 28 }}>
                <div style={{ padding: '8px 12px', background: '#0a1512', flexShrink: 0 }}>
                    <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.15em', color: '#b8934a', textTransform: 'uppercase' }}>
                        {typeLabel}
                    </span>
                </div>
                {signupPlan && (
                    <div style={{ padding: '8px 12px', border: '1px solid #b8934a', flexShrink: 0 }}>
                        <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.12em', color: '#8a6d2f', textTransform: 'uppercase' }}>
                            Plan:{' '}
                        </span>
                        <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.12em', color: '#0a1512' }}>
                            {signupPlan.display_name}
                        </span>
                    </div>
                )}
                <a
                    href="/get-started"
                    style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.1em', color: '#a89874', textDecoration: 'none', textTransform: 'uppercase', whiteSpace: 'nowrap' }}
                >
                    ← Wrong account type?
                </a>
            </div>

            {paid && signupPlan && (
                <div style={{ marginBottom: 24, padding: 16, background: '#f4ecdc', border: '1px solid #a89874' }}>
                    <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                        <div>
                            <span style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 30, fontWeight: 400, color: '#0a1512' }}>
                                {dollars(form.interval === 'annual' ? signupPlan.annual_price_cents : signupPlan.monthly_price_cents)}
                            </span>
                            <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 11, letterSpacing: '0.1em', color: '#6b7856', marginLeft: 6 }}>
                                {form.interval === 'annual' ? '/ year' : '/ month'}
                            </span>
                        </div>
                        {hasMonthly && hasAnnual && (
                            <div style={{ display: 'inline-flex', border: '1px solid #a89874' }}>
                                {(['monthly', 'annual'] as const).map(cycle => (
                                    <button
                                        key={cycle}
                                        type="button"
                                        onClick={() => set('interval', cycle)}
                                        style={{
                                            padding: '6px 14px', border: 'none', cursor: 'pointer',
                                            background: form.interval === cycle ? '#0a1512' : 'transparent',
                                            color: form.interval === cycle ? '#e8dcc4' : '#4a5440',
                                            fontFamily: "'JetBrains Mono', monospace", fontSize: 10,
                                            letterSpacing: '0.12em', textTransform: 'uppercase',
                                        }}
                                    >
                                        {cycle}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440', margin: '10px 0 0', lineHeight: 1.4 }}>
                        After creating your account you'll continue to secure checkout to start your {signupPlan.display_name} membership.
                    </p>
                </div>
            )}

            {signupPromo && (
                <div style={{
                    marginBottom: 24, padding: '14px 16px',
                    background: '#0a1512', borderLeft: '4px solid #b8934a',
                }}>
                    <p style={{
                        fontFamily: "'JetBrains Mono', monospace", fontSize: 10,
                        letterSpacing: '0.18em', textTransform: 'uppercase',
                        color: '#b8934a', margin: '0 0 6px',
                    }}>
                        {signupPromo.headline}
                    </p>
                    <p style={{
                        fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 16,
                        color: '#f4ecdc', margin: 0, lineHeight: 1.4,
                    }}>
                        {signupPromo.detail}
                    </p>
                </div>
            )}

            {Object.keys(errors).length > 0 && (
                <div style={{
                    marginBottom: 24, padding: '14px 16px',
                    background: '#f4ecdc', border: '1px solid #c84c21',
                    borderLeft: '4px solid #c84c21',
                }}>
                    {errors.email ? (
                        <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#c84c21', margin: 0 }}>
                            {errors.email}{' '}
                            <a href="/login" style={{ color: '#c84c21', fontWeight: 600 }}>Sign in instead?</a>
                        </p>
                    ) : (
                        <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#c84c21', margin: 0 }}>
                            Please correct the errors below and try again.
                        </p>
                    )}
                </div>
            )}

            <form onSubmit={handleSubmit} style={{ marginTop: 16 }}>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 16px' }}>
                    <AuthInput
                        label="First Name"
                        id="first_name"
                        type="text"
                        autoComplete="given-name"
                        value={form.first_name}
                        onChange={e => set('first_name', e.target.value)}
                        error={errors.first_name}
                        required
                    />
                    <AuthInput
                        label="Last Name"
                        id="last_name"
                        type="text"
                        autoComplete="family-name"
                        value={form.last_name}
                        onChange={e => set('last_name', e.target.value)}
                        error={errors.last_name}
                        required
                    />
                </div>

                <AuthInput
                    label="Email Address"
                    id="email"
                    type="email"
                    autoComplete="email"
                    value={form.email}
                    onChange={e => set('email', e.target.value)}
                    error={errors.email}
                    required
                />

                <AuthInput
                    label="Phone Number"
                    id="phone"
                    type="tel"
                    autoComplete="tel"
                    value={form.phone}
                    onChange={e => set('phone', formatPhoneInput(e.target.value))}
                    placeholder="(555) 123-4567"
                    error={errors.phone}
                    required
                />

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 16px' }}>
                    <AuthInput
                        label="Date of Birth"
                        id="date_of_birth"
                        type="date"
                        autoComplete="bday"
                        value={form.date_of_birth}
                        onChange={e => set('date_of_birth', e.target.value)}
                        error={errors.date_of_birth}
                        required
                    />

                    {/* Home state gatekeeps which listings a member may apply to, so it
                        is a select (clean 2-letter code) rather than free text. */}
                    <div style={{ marginBottom: 20 }}>
                        <label htmlFor="state_code" style={{
                            display: 'block', fontFamily: "'JetBrains Mono', monospace",
                            fontSize: 10, letterSpacing: '0.18em', textTransform: 'uppercase',
                            color: '#4a5440', marginBottom: 6,
                        }}>
                            Current Home State
                        </label>
                        <select
                            id="state_code"
                            value={form.state_code}
                            onChange={e => set('state_code', e.target.value)}
                            required
                            style={{
                                width: '100%', padding: '10px 14px',
                                background: '#f4ecdc', border: `1px solid ${errors.state_code ? '#c84c21' : '#a89874'}`,
                                borderRadius: 0, fontSize: 16, fontFamily: "'Crimson Pro', Georgia, serif",
                                color: form.state_code ? '#0a1512' : '#8a7a5a', outline: 'none',
                                appearance: 'none', cursor: 'pointer',
                            }}
                            onFocus={e => (e.target.style.borderColor = '#0a1512')}
                            onBlur={e => (e.target.style.borderColor = errors.state_code ? '#c84c21' : '#a89874')}
                        >
                            <option value="" disabled>Select your state</option>
                            {US_STATES.map(([code, name]) => (
                                <option key={code} value={code} style={{ color: '#0a1512' }}>{name}</option>
                            ))}
                        </select>
                        {errors.state_code && (
                            <p style={{ marginTop: 4, fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.1em', color: '#c84c21' }}>
                                {errors.state_code}
                            </p>
                        )}
                    </div>
                </div>

                <AuthInput
                    label="Password"
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    value={form.password}
                    onChange={e => set('password', e.target.value)}
                    error={errors.password}
                    required
                />
                <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 9, letterSpacing: '0.1em', color: '#6b7856', textTransform: 'uppercase', marginTop: -14, marginBottom: 20 }}>
                    Min 12 chars · uppercase · lowercase · number
                </p>

                <AuthInput
                    label="Confirm Password"
                    id="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    value={form.password_confirmation}
                    onChange={e => set('password_confirmation', e.target.value)}
                    error={errors.password_confirmation}
                    required
                />

                {/* Optional service-status step — skippable. Declaring veteran /
                    first responder and attaching proof opens a verification the
                    team reviews; the benefit unlocks on approval. */}
                <div style={{ marginBottom: 24, padding: 16, background: '#f4ecdc', border: '1px solid #a89874' }}>
                    <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.18em', textTransform: 'uppercase', color: '#4a5440', margin: '0 0 6px' }}>
                        Veteran or First Responder? <span style={{ color: '#8a7a5a' }}>· Optional</span>
                    </p>
                    <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440', margin: '0 0 12px', lineHeight: 1.45 }}>
                        Verify your service to unlock your member benefit. You can skip this and add it later from your profile.
                    </p>

                    {serviceLocked && form.service_status !== '' ? (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
                            <span style={{
                                padding: '7px 14px', background: '#0a1512', color: '#e8dcc4',
                                fontFamily: "'JetBrains Mono', monospace", fontSize: 10,
                                letterSpacing: '0.1em', textTransform: 'uppercase',
                            }}>
                                Verifying as {SERVICE_LABELS[form.service_status]}
                            </span>
                            <button
                                type="button"
                                onClick={() => {
                                    setServiceLocked(false);
                                    set('service_status', '');
                                    set('service_proof', null);
                                }}
                                style={{
                                    background: 'none', border: 'none', padding: 0, cursor: 'pointer',
                                    fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14,
                                    color: '#8a5a2a', textDecoration: 'underline',
                                }}
                            >
                                Not a {SERVICE_LABELS[form.service_status].toLowerCase()}? Skip this
                            </button>
                        </div>
                    ) : (
                        <div style={{ display: 'inline-flex', flexWrap: 'wrap', border: '1px solid #a89874' }}>
                            {([['', 'No, skip'], ['veteran', 'Veteran'], ['first_responder', 'First Responder']] as const).map(([value, label]) => {
                                const active = form.service_status === value;
                                return (
                                    <button
                                        key={value || 'none'}
                                        type="button"
                                        onClick={() => {
                                            set('service_status', value);
                                            if (value === '') set('service_proof', null);
                                        }}
                                        style={{
                                            padding: '7px 14px', border: 'none', cursor: 'pointer',
                                            borderRight: value === 'first_responder' ? 'none' : '1px solid #a89874',
                                            background: active ? '#0a1512' : 'transparent',
                                            color: active ? '#e8dcc4' : '#4a5440',
                                            fontFamily: "'JetBrains Mono', monospace", fontSize: 10,
                                            letterSpacing: '0.1em', textTransform: 'uppercase',
                                        }}
                                    >
                                        {label}
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    {form.service_status !== '' && serviceMethods?.[form.service_status] === 'id_me' ? (
                        <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440', margin: '14px 0 0', lineHeight: 1.45 }}>
                            We verify {SERVICE_LABELS[form.service_status].toLowerCase()} status through ID.me — you'll be prompted to verify after signing in.
                        </p>
                    ) : form.service_status !== '' ? (
                        <div style={{ marginTop: 14 }}>
                            <label
                                htmlFor="service_proof"
                                style={{
                                    display: 'inline-block', padding: '9px 16px', cursor: 'pointer',
                                    background: '#0a1512', color: '#e8dcc4',
                                    fontFamily: "'JetBrains Mono', monospace", fontSize: 10,
                                    letterSpacing: '0.12em', textTransform: 'uppercase',
                                }}
                            >
                                {form.service_proof ? 'Change file' : 'Upload proof'}
                            </label>
                            <input
                                id="service_proof"
                                type="file"
                                accept="application/pdf,image/jpeg,image/png"
                                onChange={e => set('service_proof', e.target.files?.[0] ?? null)}
                                style={{ display: 'none' }}
                            />
                            {form.service_proof && (
                                <span style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#0a1512', marginLeft: 12 }}>
                                    {form.service_proof.name}
                                </span>
                            )}
                            <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 9, letterSpacing: '0.08em', color: '#6b7856', textTransform: 'uppercase', margin: '8px 0 0' }}>
                                {SERVICE_PROOF_HINT[form.service_status]}
                            </p>
                            {errors.service_proof && (
                                <p style={{ marginTop: 4, fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.1em', color: '#c84c21' }}>
                                    {errors.service_proof}
                                </p>
                            )}
                        </div>
                    ) : null}
                </div>

                {/* Consent checkboxes */}
                <div style={{ marginBottom: 24, display: 'flex', flexDirection: 'column', gap: 12 }}>
                    <div>
                        <label style={{ display: 'flex', alignItems: 'flex-start', gap: 10, cursor: 'pointer' }}>
                            <input
                                type="checkbox"
                                checked={form.tos_accepted}
                                onChange={e => set('tos_accepted', e.target.checked)}
                                style={{ marginTop: 3, accentColor: '#c84c21', width: 16, height: 16, flexShrink: 0 }}
                            />
                            <span style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#142420', lineHeight: 1.5 }}>
                                I agree to the{' '}
                                <a href={legalUrls.tos_url} target="_blank" rel="noopener noreferrer" style={{ color: '#c84c21', textDecoration: 'underline' }}>
                                    Terms of Service
                                </a>
                            </span>
                        </label>
                        {errors.tos_accepted && (
                            <p style={{ marginTop: 2, marginLeft: 26, fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.1em', color: '#c84c21' }}>
                                {errors.tos_accepted}
                            </p>
                        )}
                    </div>

                    <div>
                        <label style={{ display: 'flex', alignItems: 'flex-start', gap: 10, cursor: 'pointer' }}>
                            <input
                                type="checkbox"
                                checked={form.privacy_accepted}
                                onChange={e => set('privacy_accepted', e.target.checked)}
                                style={{ marginTop: 3, accentColor: '#c84c21', width: 16, height: 16, flexShrink: 0 }}
                            />
                            <span style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#142420', lineHeight: 1.5 }}>
                                I agree to the{' '}
                                <a href={legalUrls.privacy_url} target="_blank" rel="noopener noreferrer" style={{ color: '#c84c21', textDecoration: 'underline' }}>
                                    Privacy Policy
                                </a>
                                {' '}&amp;{' '}
                                <a href={legalUrls.ccpa_url} target="_blank" rel="noopener noreferrer" style={{ color: '#c84c21', textDecoration: 'underline' }}>
                                    CCPA Notice
                                </a>
                            </span>
                        </label>
                        {errors.privacy_accepted && (
                            <p style={{ marginTop: 2, marginLeft: 26, fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.1em', color: '#c84c21' }}>
                                {errors.privacy_accepted}
                            </p>
                        )}
                    </div>
                </div>

                <button
                    type="submit"
                    disabled={processing || !form.tos_accepted || !form.privacy_accepted}
                    style={{
                        width: '100%', padding: '13px 24px',
                        background: (processing || !form.tos_accepted || !form.privacy_accepted) ? '#a89874' : '#c84c21',
                        border: 'none', borderRadius: 0,
                        cursor: (processing || !form.tos_accepted || !form.privacy_accepted) ? 'not-allowed' : 'pointer',
                        fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                        fontWeight: 600, letterSpacing: '0.15em', color: '#fff',
                        textTransform: 'uppercase', marginBottom: 20,
                    }}
                >
                    {processing
                        ? (paid ? 'Redirecting to Checkout…' : 'Creating Account…')
                        : (paid ? 'Continue to Payment →' : 'Create Account →')}
                </button>
            </form>

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440' }}>
                Already have an account?{' '}
                <a href="/login" style={{ color: '#c84c21', textDecoration: 'none' }}>Sign in</a>
            </p>
        </AuthLayout>
    );
}
