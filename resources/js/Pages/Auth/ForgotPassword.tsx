import { useState, FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';
import AuthInput from '@/Components/Auth/AuthInput';

interface ForgotPasswordProps {
    errors?: Record<string, string>;
    flash?: { success?: string };
}

export default function ForgotPassword() {
    const { errors = {}, flash = {} } = usePage<ForgotPasswordProps>().props;

    const [email, setEmail] = useState('');
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post('/forgot-password', { email }, {
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AuthLayout
            chapter="Account Recovery"
            headline="Forgot your<br/>password?"
            subheadline="Enter your email and we'll send you a reset link."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 8 }}>
                Reset Password
            </h1>
            <p style={{ fontSize: 15, color: '#4a5440', marginBottom: 24, fontFamily: "'Crimson Pro', Georgia, serif" }}>
                Enter the email address on your account.
            </p>

            {flash.success && (
                <div style={{ marginBottom: 20, padding: '12px 16px', background: '#f4ecdc', border: '1px solid #6b7856', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#142420' }}>
                    {flash.success}
                </div>
            )}

            <form onSubmit={handleSubmit}>
                <AuthInput
                    label="Email Address"
                    id="email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={e => setEmail(e.target.value)}
                    error={errors.email}
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
                    {processing ? 'Sending…' : 'Send Reset Link →'}
                </button>
            </form>

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440' }}>
                <a href="/login" style={{ color: '#c84c21', textDecoration: 'none' }}>← Back to sign in</a>
            </p>
        </AuthLayout>
    );
}
