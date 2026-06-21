import { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import AuthLayout from '@/Components/Auth/AuthLayout';

interface VerifyEmailProps {
    flash?: { success?: string; error?: string };
}

export default function VerifyEmail() {
    const { flash = {} } = usePage<VerifyEmailProps>().props;
    const [resending, setResending] = useState(false);
    const [verified, setVerified] = useState(false);

    // Poll for verification so this screen advances on its own the moment the
    // link is clicked — even from a different device or browser.
    useEffect(() => {
        let active = true;
        const tick = async () => {
            try {
                const res = await fetch('/email/verification-status', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();
                if (active && data.verified) {
                    setVerified(true);
                    clearInterval(id);
                    window.location.href = data.redirect ?? '/member/profile';
                }
            } catch {
                // transient network error — the next tick retries
            }
        };
        const id = setInterval(tick, 4000);
        tick();
        return () => { active = false; clearInterval(id); };
    }, []);

    function handleResend() {
        setResending(true);
        router.post('/email/verify/resend', {}, {
            onFinish: () => setResending(false),
        });
    }

    return (
        <AuthLayout
            chapter="Account Setup"
            headline="Check your<br/>email."
            subheadline="One more step before you're in."
        >
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: 28, fontWeight: 400, color: '#0a1512', marginBottom: 8 }}>
                Verify Your Email
            </h1>
            <p style={{ fontSize: 16, color: '#4a5440', marginBottom: 28, fontFamily: "'Crimson Pro', Georgia, serif", lineHeight: 1.6 }}>
                We sent a verification link to your email address. Click it to activate your account. The link expires in 24 hours.
            </p>

            {verified && (
                <div style={{ marginBottom: 20, padding: '12px 16px', background: '#0a1512', borderLeft: '4px solid #6b7856', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#f4ecdc' }}>
                    Email verified — taking you to the next step…
                </div>
            )}

            {flash.success && (
                <div style={{ marginBottom: 20, padding: '12px 16px', background: '#f4ecdc', border: '1px solid #6b7856', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#142420' }}>
                    {flash.success}
                </div>
            )}

            {flash.error && (
                <div style={{ marginBottom: 20, padding: '12px 16px', background: '#f4ecdc', border: '1px solid #c84c21', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#c84c21' }}>
                    {flash.error}
                </div>
            )}

            <div style={{ padding: '20px', background: '#f4ecdc', border: '1px solid #a89874', marginBottom: 24 }}>
                <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440', margin: 0 }}>
                    Didn't receive it? Check your spam folder, or request a new link below.
                </p>
            </div>

            <button
                onClick={handleResend}
                disabled={resending}
                style={{
                    width: '100%', padding: '13px 24px', background: 'transparent',
                    border: '1px solid #0a1512', borderRadius: 0,
                    cursor: resending ? 'not-allowed' : 'pointer',
                    fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                    fontWeight: 600, letterSpacing: '0.15em', color: '#0a1512',
                    textTransform: 'uppercase', marginBottom: 16,
                }}
            >
                {resending ? 'Sending…' : 'Resend Verification Email'}
            </button>

            <p style={{ textAlign: 'center', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15, color: '#4a5440' }}>
                <a href="/logout" style={{ color: '#c84c21', textDecoration: 'none' }}
                    onClick={e => { e.preventDefault(); router.post('/logout'); }}>
                    Sign out
                </a>
            </p>
        </AuthLayout>
    );
}
