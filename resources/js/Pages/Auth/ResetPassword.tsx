import { useState, FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';
import AuthInput from '@/Components/Auth/AuthInput';

interface ResetPasswordProps {
    token: string;
    email: string;
    errors?: Record<string, string>;
}

export default function ResetPassword() {
    const { token, email, errors = {} } = usePage<ResetPasswordProps>().props;

    const [form, setForm] = useState({
        email,
        token,
        password: '',
        password_confirmation: '',
    });
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post('/reset-password', form, {
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AuthLayout
            chapter="Account Recovery"
            headline="Choose a new<br/>password."
            subheadline="Minimum 12 characters with uppercase, lowercase, and a number."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 24 }}>
                New Password
            </h1>

            <form onSubmit={handleSubmit}>
                <AuthInput
                    label="Email Address"
                    id="email"
                    type="email"
                    autoComplete="email"
                    value={form.email}
                    onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
                    error={errors.email}
                    required
                />
                <AuthInput
                    label="New Password"
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    value={form.password}
                    onChange={e => setForm(f => ({ ...f, password: e.target.value }))}
                    error={errors.password}
                    required
                />
                <AuthInput
                    label="Confirm New Password"
                    id="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    value={form.password_confirmation}
                    onChange={e => setForm(f => ({ ...f, password_confirmation: e.target.value }))}
                    error={errors.password_confirmation}
                    required
                />

                {errors.token && (
                    <div style={{ marginBottom: 16, padding: '10px 14px', border: '1px solid #c84c21', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#c84c21' }}>
                        {errors.token}
                    </div>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    style={{
                        width: '100%', padding: '13px 24px', background: processing ? '#a89874' : '#c84c21',
                        border: 'none', borderRadius: 0, cursor: processing ? 'not-allowed' : 'pointer',
                        fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                        fontWeight: 600, letterSpacing: '0.15em', color: '#fff',
                        textTransform: 'uppercase',
                    }}
                >
                    {processing ? 'Saving…' : 'Reset Password →'}
                </button>
            </form>
        </AuthLayout>
    );
}
