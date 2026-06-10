import { useState, FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';
import AuthInput from '@/Components/Auth/AuthInput';

interface MfaVerifyProps {
    errors?: Record<string, string>;
}

export default function MfaVerify() {
    const { errors = {} } = usePage<MfaVerifyProps>().props;

    const [code, setCode] = useState('');
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post('/mfa/verify', { code }, {
            onFinish: () => setProcessing(false),
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
                Enter your authentication code or a backup code.
            </p>

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

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#4a5440' }}>
                <a href="/login" style={{ color: '#c84c21', textDecoration: 'none' }}>← Back to sign in</a>
            </p>
        </AuthLayout>
    );
}
