import { useState, FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';
import AuthInput from '@/Components/Auth/AuthInput';

interface LoginPageProps {
    errors?: Record<string, string>;
    flash?: { success?: string; error?: string };
}

export default function Login() {
    const { errors = {}, flash = {} } = usePage<LoginPageProps>().props;

    const [form, setForm] = useState({ email: '', password: '' });
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post('/login', form, {
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AuthLayout
            chapter="Member Access"
            headline="Welcome<br/>back."
            subheadline="Sign in to your American Headhunter account."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 24 }}>
                Sign In
            </h1>

            {flash.success && (
                <div style={{ marginBottom: 20, padding: '10px 14px', background: '#f4ecdc', border: '1px solid #6b7856', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#142420' }}>
                    {flash.success}
                </div>
            )}

            {flash.error && (
                <div style={{ marginBottom: 20, padding: '10px 14px', background: '#f4ecdc', border: '1px solid #c84c21', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 14, color: '#c84c21' }}>
                    {flash.error}
                </div>
            )}

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
                    label="Password"
                    id="password"
                    type="password"
                    autoComplete="current-password"
                    value={form.password}
                    onChange={e => setForm(f => ({ ...f, password: e.target.value }))}
                    error={errors.password}
                    required
                />

                <div style={{ textAlign: 'right', marginBottom: 24, marginTop: -12 }}>
                    <a href="/forgot-password" style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 10, letterSpacing: '0.12em', color: '#4a5440', textDecoration: 'none', textTransform: 'uppercase' }}>
                        Forgot password?
                    </a>
                </div>

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
                    {processing ? 'Signing In…' : 'Sign In →'}
                </button>
            </form>

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440' }}>
                Don't have an account?{' '}
                <a href="/get-started" style={{ color: '#c84c21', textDecoration: 'none' }}>Get started</a>
            </p>
        </AuthLayout>
    );
}
