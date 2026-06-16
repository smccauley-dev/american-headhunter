import { useState, FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';
import AuthInput from '@/Components/Auth/AuthInput';

interface MfaVerifyProps {
    errors?: Record<string, string>;
    methods?: string[];
    canResendCode?: boolean;
    flash?: { success?: string | null };
}

const METHOD_LABELS: Record<string, string> = {
    totp:  'authenticator app',
    email: 'email',
    sms:   'SMS',
};

export default function MfaVerify() {
    const { errors = {}, methods = [], canResendCode = false, flash } =
        usePage<MfaVerifyProps>().props;

    const [code, setCode] = useState('');
    const [processing, setProcessing] = useState(false);
    const [resending, setResending] = useState(false);

    const sources = methods.map(m => METHOD_LABELS[m] ?? m);
    const sourceText = sources.length
        ? `Enter the code from your ${sources.join(' or ')}, or a backup code.`
        : 'Enter your authentication code or a backup code.';

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post('/mfa/verify', { code }, {
            onFinish: () => setProcessing(false),
        });
    }

    function handleResend() {
        setResending(true);
        router.post('/mfa/resend', {}, {
            preserveState: true,
            onFinish: () => setResending(false),
        });
    }

    return (
        <AuthLayout
            chapter="Two-Factor Verification"
            headline="Verify<br/>your identity."
            subheadline="Enter the code from your authenticator app, SMS, or email."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 8 }}>
                Two-Factor Verification
            </h1>
            <p style={{ fontSize: 15, color: '#4a5440', marginBottom: 24, fontFamily: "'Crimson Pro', Georgia, serif" }}>
                {sourceText}
            </p>

            {flash?.success && (
                <div style={{
                    background: '#e8f3ec', border: '1px solid #4a7c59', padding: '10px 16px', marginBottom: 16,
                    fontFamily: "'JetBrains Mono', monospace", fontSize: 11, color: '#2d5a3d', letterSpacing: '.04em',
                }}>
                    {flash.success}
                </div>
            )}

            <form onSubmit={handleSubmit}>
                <AuthInput
                    label="Verification Code"
                    id="code"
                    type="text"
                    autoComplete="one-time-code"
                    inputMode="numeric"
                    placeholder="000000"
                    value={code}
                    onChange={e => setCode(e.target.value.trim())}
                    error={errors.code}
                    required
                />

                <button
                    type="submit"
                    disabled={processing}
                    style={{
                        width: '100%', padding: '13px 24px', background: processing ? '#a89874' : '#c84c21',
                        border: 'none', borderRadius: 0, cursor: processing ? 'not-allowed' : 'pointer',
                        fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                        fontWeight: 600, letterSpacing: '0.15em', color: '#fff',
                        textTransform: 'uppercase', marginBottom: 20,
                    }}
                >
                    {processing ? 'Verifying…' : 'Verify →'}
                </button>
            </form>

            {canResendCode && (
                <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440', marginBottom: 8 }}>
                    Didn't get a code?{' '}
                    <button
                        type="button"
                        onClick={handleResend}
                        disabled={resending}
                        style={{
                            background: 'none', border: 'none', padding: 0, cursor: resending ? 'not-allowed' : 'pointer',
                            color: '#c84c21', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14,
                        }}
                    >
                        {resending ? 'Sending…' : 'Resend code'}
                    </button>
                </p>
            )}

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440' }}>
                <a href="/login" style={{ color: '#c84c21', textDecoration: 'none' }}>← Back to sign in</a>
            </p>
        </AuthLayout>
    );
}
