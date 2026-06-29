import { Link, router, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

interface Application {
    id: string;
    status: string;
    application_type: string;
    desired_hunters: number;
    proposed_start: string | null;
    proposed_end: string | null;
    message: string | null;
    rejection_reason: string | null;
    closed_reason: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
}

interface BookingFee {
    amount: string;
    status: 'held' | 'disbursed' | 'forfeited' | 'refunded' | null;
    window_open: boolean;
    deadline: string | null;
    can_pay: boolean;
    pay_url: string;
    lease_status: string | null;
    completion_deadline: string | null;
}

interface Message {
    id: string;
    sender_role: string;
    message: string;
    is_mine: boolean;
    created_at: string | null;
}

interface Listing {
    id: string;
    listing_type: string;
    price_per_hunter: string | null;
    price_total: string | null;
    season_start: string | null;
    season_end: string | null;
}

interface Property {
    title: string;
    slug: string;
    state_code: string;
    county: string;
    total_acres: string;
}

interface StatusProps {
    application: Application;
    messages: Message[];
    listing: Listing | null;
    property: Property | null;
    sign_url: string | null;
    booking_fee: BookingFee | null;
}

const STATUS_CONFIG: Record<string, { label: string; color: string; description: string }> = {
    pending:   { label: 'Under Review',  color: 'var(--brass)',  description: 'Your application is being reviewed by the landowner.' },
    approved:  { label: 'Approved',      color: 'var(--sage)',   description: 'Congratulations — the landowner has approved your application. Pay the booking fee to claim your spot.' },
    rejected:  { label: 'Not Selected',  color: 'var(--sage-dim)', description: 'The landowner did not select your application for this listing.' },
    withdrawn: { label: 'Withdrawn',     color: 'var(--parch-dim)', description: 'You withdrew this application.' },
    countered: { label: 'Counter Offer', color: 'var(--blaze)', description: 'The landowner has sent a counter-offer. Check your messages to review.' },
    closed:    { label: 'Closed',        color: 'var(--parch-dim)', description: 'This application is closed.' },
};

/** Live "Xd Yh Zm Ws" remaining until `deadline`, ticking each second. */
function useCountdown(deadline: string | null): { text: string; expired: boolean } {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!deadline) return;
        const t = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(t);
    }, [deadline]);

    if (!deadline) return { text: '—', expired: true };

    const ms = new Date(deadline).getTime() - now;
    if (ms <= 0) return { text: 'Expired', expired: true };

    const s = Math.floor(ms / 1000);
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const parts = d > 0 ? [`${d}d`, `${h}h`, `${m}m`] : [`${h}h`, `${m}m`, `${sec}s`];

    return { text: parts.join(' '), expired: false };
}

const ROLE_LABEL: Record<string, string> = {
    admin:     'American Headhunter',
    landowner: 'Landowner',
    applicant: 'You',
};

function formatDate(d: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatDateTime(d: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatType(type: string): string {
    const map: Record<string, string> = {
        annual_lease: 'Annual Lease', seasonal_lease: 'Seasonal Lease',
        day_hunt: 'Day Hunt', auction: 'Auction',
    };
    return map[type] ?? type;
}

function formatPrice(listing: Listing): string {
    if (listing.price_total) return `$${parseInt(listing.price_total).toLocaleString()}`;
    if (listing.price_per_hunter) return `$${parseInt(listing.price_per_hunter).toLocaleString()} / hunter`;
    return '—';
}

function MessageBubble({ msg }: { msg: Message }) {
    const label = ROLE_LABEL[msg.sender_role] ?? msg.sender_role;
    const isMe  = msg.is_mine;

    return (
        <div style={{
            display: 'flex', flexDirection: 'column',
            alignItems: isMe ? 'flex-end' : 'flex-start',
            marginBottom: 16,
        }}>
            <div style={{ maxWidth: '72%' }}>
                <div style={{
                    display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4,
                    justifyContent: isMe ? 'flex-end' : 'flex-start',
                }}>
                    <span style={{
                        fontFamily: 'var(--mono)', fontSize: 10, fontWeight: 700,
                        color: isMe ? 'var(--brass)' : 'var(--sage)',
                        textTransform: 'uppercase', letterSpacing: '0.1em',
                    }}>
                        {label}
                    </span>
                    <span style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-dim)' }}>
                        {formatDateTime(msg.created_at)}
                    </span>
                </div>
                <div style={{
                    background: isMe ? 'var(--bone)' : '#f0f4f0',
                    border: `1px solid ${isMe ? 'var(--brass)' : 'var(--sage-dim)'}`,
                    borderRadius: 4,
                    padding: '12px 16px',
                    fontFamily: 'var(--body)',
                    fontSize: 15,
                    lineHeight: 1.6,
                    color: 'var(--ink)',
                    whiteSpace: 'pre-wrap',
                }}>
                    {msg.message}
                </div>
            </div>
        </div>
    );
}

function BookingFeePanel({ fee, applicationId }: { fee: BookingFee; applicationId: string }) {
    const [paying, setPaying] = useState(false);
    const bookingClock     = useCountdown(fee.deadline);
    const completionClock  = useCountdown(fee.completion_deadline);

    function handlePay() {
        if (paying) return;
        setPaying(true);
        router.post(fee.pay_url, {}, { onError: () => setPaying(false) });
    }

    // Won — fee held, lease created. Show the 7-day completion countdown; the
    // separate Sign-lease CTA (sign_url) drives the actual signing.
    if (fee.status === 'held') {
        return (
            <div style={{ padding: '24px 28px', marginBottom: 48, border: '1px solid var(--sage)', background: 'var(--bone)' }}>
                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em', color: 'var(--sage)', textTransform: 'uppercase', marginBottom: 8 }}>
                    Booking Fee · Held
                </div>
                <div style={{ fontFamily: 'var(--display)', fontSize: 24, fontWeight: 500, color: 'var(--ink)', marginBottom: 8 }}>
                    ${fee.amount} — Spot Claimed
                </div>
                <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', margin: 0 }}>
                    Your booking fee is held and credited toward your lease total. Complete signing
                    {fee.completion_deadline && !completionClock.expired
                        ? <> within <strong style={{ fontFamily: 'var(--mono)', color: 'var(--blaze)' }}>{completionClock.text}</strong></>
                        : ' soon'} to lock in your lease — otherwise the fee is forfeited to the landowner.
                </p>
            </div>
        );
    }

    if (fee.status === 'refunded') {
        return (
            <div style={{ padding: '24px 28px', marginBottom: 48, border: '1px solid var(--parch-deep)', background: 'var(--bone)' }}>
                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em', color: 'var(--parch-dim)', textTransform: 'uppercase', marginBottom: 8 }}>
                    Booking Fee · Refunded
                </div>
                <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', margin: 0 }}>
                    Another applicant claimed this listing first, so your ${fee.amount} booking fee has been refunded in full.
                </p>
            </div>
        );
    }

    if (fee.status === 'forfeited') {
        return (
            <div style={{ padding: '24px 28px', marginBottom: 48, border: '1px solid var(--parch-deep)', background: 'var(--bone)' }}>
                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em', color: 'var(--parch-dim)', textTransform: 'uppercase', marginBottom: 8 }}>
                    Booking Fee · Forfeited
                </div>
                <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', margin: 0 }}>
                    The 7-day window to complete your lease lapsed, so your ${fee.amount} booking fee was forfeited to the landowner.
                </p>
            </div>
        );
    }

    // Unpaid. Offer payment while the window is open; otherwise show it lapsed.
    if (!fee.can_pay) {
        return null;
    }

    return (
        <div style={{ padding: '24px 28px', marginBottom: 48, border: '1px solid var(--blaze)', background: 'var(--bone)' }}>
            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em', color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 8 }}>
                Action Required · Pay Booking Fee
            </div>
            <div style={{ fontFamily: 'var(--display)', fontSize: 28, fontWeight: 500, color: 'var(--ink)', marginBottom: 8 }}>
                ${fee.amount}
            </div>
            <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', margin: '0 0 16px' }}>
                You've been approved — pay the booking fee to claim your spot. The first approved applicant
                to pay wins the listing; if you're beaten to it, your fee is refunded in full. Your fee credits
                toward your lease total.
            </p>
            <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
                <button
                    type="button"
                    onClick={handlePay}
                    disabled={paying}
                    className="btn-solid"
                    style={{ opacity: paying ? 0.5 : 1, cursor: paying ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 8 }}
                >
                    {paying ? 'Redirecting…' : `Pay $${fee.amount} →`}
                </button>
                {fee.deadline && (
                    <span style={{ fontFamily: 'var(--mono)', fontSize: 12, color: 'var(--ink-lift)' }}>
                        Time left: <strong style={{ color: 'var(--blaze)' }}>{bookingClock.text}</strong>
                    </span>
                )}
            </div>
        </div>
    );
}

export default function Status({ application, messages, listing, property, sign_url, booking_fee }: StatusProps) {
    const status = STATUS_CONFIG[application.status] ?? { label: application.status, color: 'var(--parch-dim)', description: '' };
    const page   = usePage<{ flash: { success?: string } }>();
    const flash  = page.props.flash;

    const [draft, setDraft]       = useState('');
    const [sending, setSending]   = useState(false);

    function handleSend(e: FormEvent) {
        e.preventDefault();
        if (!draft.trim() || sending) return;
        setSending(true);
        router.post(
            `/apply/status/${application.id}/message`,
            { message: draft },
            {
                onSuccess: () => { setDraft(''); setSending(false); },
                onError:   () => setSending(false),
            }
        );
    }

    return (
        <div className="ah-page">

            {/* ── NAV ─────────────────────────────────────────────────────── */}
            <nav className="ah-nav">
                <div className="nav-strip">
                    <div className="nav-strip-left">
                        <span><span className="strip-dot" />Hunting Lease Marketplace</span>
                    </div>
                    <div className="nav-strip-right">
                        <Link href="/member">My Account</Link>
                    </div>
                </div>
                <div className="nav-main">
                    <Link href="/" className="logo">
                        <div className="logo-mark">
                            <span className="logo-mark-letters">AH</span>
                        </div>
                        <div className="logo-text">
                            <span className="logo-name">American Headhunter</span>
                            <span className="logo-tag">Est. 2025 · Hunting Leases</span>
                        </div>
                    </Link>
                    <ul className="nav-links">
                        <li><a href="/properties">Find Land</a></li>
                        <li><a href="/apply/my-applications">My Applications</a></li>
                    </ul>
                </div>
            </nav>

            {/* ── HEADER ──────────────────────────────────────────────────── */}
            <div style={{ paddingTop: 120, background: 'var(--ink)', position: 'relative', overflow: 'hidden' }}>
                <div className="topo-bg-dark" style={{ position: 'absolute', inset: 0, opacity: 0.6 }} />
                <div style={{
                    maxWidth: 1200, margin: '0 auto', padding: '0 40px 24px',
                    position: 'relative', zIndex: 1,
                    fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em',
                    color: 'var(--brass)', textTransform: 'uppercase',
                    display: 'flex', gap: 12, alignItems: 'center',
                }}>
                    <Link href="/" style={{ color: 'var(--parch-dim)', textDecoration: 'none' }}>Home</Link>
                    <span>›</span>
                    <Link href="/apply/my-applications" style={{ color: 'var(--parch-dim)', textDecoration: 'none' }}>My Applications</Link>
                    <span>›</span>
                    <span style={{ color: 'var(--brass)' }}>AH-{application.id.slice(0, 8).toUpperCase()}</span>
                </div>
                <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 40px 64px', position: 'relative', zIndex: 1 }}>
                    <div style={{
                        fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em',
                        color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 16,
                        display: 'flex', alignItems: 'center', gap: 16,
                    }}>
                        <span style={{ display: 'block', width: 32, height: 1, background: 'var(--brass)' }} />
                        Chapter II — Application Status
                    </div>
                    <h1 style={{
                        fontFamily: 'var(--display)', fontSize: 'clamp(32px, 4vw, 56px)',
                        fontWeight: 400, lineHeight: 1.05, color: 'var(--bone)', marginBottom: 12,
                    }}>
                        {property ? property.title : 'Application'}
                    </h1>
                    {property && (
                        <p style={{ fontFamily: 'var(--body)', fontSize: 16, fontStyle: 'italic', color: 'var(--parch-dim)', margin: 0 }}>
                            {property.county} County, {property.state_code} · {parseFloat(property.total_acres).toLocaleString()} acres
                        </p>
                    )}
                </div>
            </div>

            {/* ── CONTENT ─────────────────────────────────────────────────── */}
            <div style={{ maxWidth: 1200, margin: '0 auto', padding: '72px 40px 80px', display: 'grid', gridTemplateColumns: '1fr 340px', gap: 72, alignItems: 'start' }}>

                {/* Left */}
                <div>
                    {/* Flash message */}
                    {flash?.success && (
                        <div style={{
                            padding: '16px 20px', marginBottom: 40,
                            background: 'var(--bone)', borderLeft: '3px solid var(--sage)',
                            fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink)',
                        }}>
                            {flash.success}
                        </div>
                    )}

                    {/* Status banner */}
                    <div style={{
                        padding: '24px 28px', marginBottom: 48,
                        border: `1px solid ${status.color}`,
                        background: 'var(--bone)',
                    }}>
                        <div style={{
                            fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em',
                            color: status.color, textTransform: 'uppercase', marginBottom: 8,
                        }}>
                            Status
                        </div>
                        <div style={{ fontFamily: 'var(--display)', fontSize: 28, fontWeight: 500, color: 'var(--ink)', marginBottom: 8 }}>
                            {status.label}
                        </div>
                        <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', margin: 0 }}>
                            {status.description}
                        </p>
                        {application.rejection_reason && (
                            <p style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink-lift)', fontStyle: 'italic', marginTop: 12, paddingTop: 12, borderTop: '1px dotted var(--parch-deep)', marginBottom: 0 }}>
                                "{application.rejection_reason}"
                            </p>
                        )}
                        {application.status === 'closed' && application.closed_reason && (
                            <p style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink-lift)', fontStyle: 'italic', marginTop: 12, paddingTop: 12, borderTop: '1px dotted var(--parch-deep)', marginBottom: 0 }}>
                                {application.closed_reason}
                            </p>
                        )}

                        {/* Sign-lease CTA — shown once a lease awaits the applicant's signature */}
                        {sign_url && (
                            <div style={{ marginTop: 20, paddingTop: 20, borderTop: '1px dotted var(--parch-deep)' }}>
                                <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink)', margin: '0 0 14px' }}>
                                    Your lease agreement is ready. Review and sign it to activate your access.
                                </p>
                                <a
                                    href={sign_url}
                                    className="btn-solid"
                                    style={{ display: 'inline-flex', alignItems: 'center', gap: 8, textDecoration: 'none' }}
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M12 20h9" />
                                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
                                    </svg>
                                    Sign Lease →
                                </a>
                            </div>
                        )}
                    </div>

                    {/* Booking fee — pay-to-claim, held, refunded, or forfeited */}
                    {booking_fee && <BookingFeePanel fee={booking_fee} applicationId={application.id} />}

                    {/* Messages */}
                    <div style={{ marginBottom: 48 }}>
                        <div style={{
                            fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                            color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                            display: 'flex', alignItems: 'center', gap: 12,
                        }}>
                            <span style={{ width: 20, height: 1, background: 'var(--blaze)', display: 'block' }} />
                            Messages
                            {messages.length > 0 && (
                                <span style={{
                                    fontFamily: 'var(--mono)', fontSize: 10,
                                    background: 'var(--blaze)', color: '#fff',
                                    borderRadius: 10, padding: '1px 7px',
                                }}>
                                    {messages.length}
                                </span>
                            )}
                        </div>

                        {messages.length === 0 ? (
                            <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', fontStyle: 'italic' }}>
                                No messages yet. Use the form below to ask the landowner a question about this application.
                            </p>
                        ) : (
                            <div style={{ marginBottom: 24 }}>
                                {messages.map(m => <MessageBubble key={m.id} msg={m} />)}
                            </div>
                        )}

                        {/* Compose */}
                        <form onSubmit={handleSend} style={{ marginTop: 16 }}>
                            <div style={{ marginBottom: 8 }}>
                                <label style={{
                                    display: 'block', fontFamily: 'var(--mono)', fontSize: 10,
                                    letterSpacing: '0.15em', textTransform: 'uppercase',
                                    color: 'var(--sage-dim)', marginBottom: 6,
                                }}>
                                    Send a Message to the Landowner
                                </label>
                                <textarea
                                    value={draft}
                                    onChange={e => setDraft(e.target.value)}
                                    rows={4}
                                    maxLength={2000}
                                    placeholder="Type your question or message here…"
                                    style={{
                                        width: '100%', boxSizing: 'border-box',
                                        padding: '12px 16px',
                                        border: '1px solid var(--parch-deep)',
                                        background: 'var(--bone)',
                                        fontFamily: 'var(--body)', fontSize: 15,
                                        color: 'var(--ink)', resize: 'vertical',
                                        outline: 'none',
                                    }}
                                />
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-dim)', textAlign: 'right', marginTop: 4 }}>
                                    {draft.length} / 2000
                                </div>
                            </div>
                            <button
                                type="submit"
                                disabled={sending || !draft.trim()}
                                className="btn-solid"
                                style={{ opacity: (sending || !draft.trim()) ? 0.5 : 1, cursor: (sending || !draft.trim()) ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 8 }}
                            >
                                {sending ? (
                                    <>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ animation: 'spin 1s linear infinite' }}>
                                            <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                                        </svg>
                                        Sending…
                                    </>
                                ) : (
                                    <>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <line x1="22" y1="2" x2="11" y2="13" />
                                            <polygon points="22 2 15 22 11 13 2 9 22 2" />
                                        </svg>
                                        Send Message
                                    </>
                                )}
                            </button>
                            <p style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-dim)', marginTop: 8, letterSpacing: '0.05em' }}>
                                The landowner will receive an email notification. You cannot reply via email — they will respond here.
                            </p>
                        </form>
                    </div>

                    {/* Timeline */}
                    <div style={{ marginBottom: 48 }}>
                        <div style={{
                            fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                            color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                            display: 'flex', alignItems: 'center', gap: 12,
                        }}>
                            <span style={{ width: 20, height: 1, background: 'var(--blaze)', display: 'block' }} />
                            Timeline
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
                            {[
                                { label: 'Application Submitted', date: application.submitted_at, done: true },
                                { label: 'Under Landowner Review', date: null, done: application.status !== 'pending' },
                                { label: 'Decision Made', date: application.reviewed_at, done: !!application.reviewed_at },
                            ].map((step, i) => (
                                <div key={i} style={{ display: 'flex', gap: 20, paddingBottom: 24, position: 'relative' }}>
                                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }}>
                                        <div style={{
                                            width: 12, height: 12,
                                            border: `2px solid ${step.done ? 'var(--blaze)' : 'var(--parch-deep)'}`,
                                            background: step.done ? 'var(--blaze)' : 'transparent',
                                            borderRadius: '50%',
                                        }} />
                                        {i < 2 && <div style={{ width: 1, flex: 1, background: 'var(--parch-deep)', minHeight: 24 }} />}
                                    </div>
                                    <div style={{ paddingTop: 0 }}>
                                        <div style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.1em', color: step.done ? 'var(--ink)' : 'var(--parch-dim)', textTransform: 'uppercase', marginBottom: 4 }}>
                                            {step.label}
                                        </div>
                                        {step.date && (
                                            <div style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink-lift)' }}>
                                                {formatDateTime(step.date)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Your application details */}
                    <div>
                        <div style={{
                            fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                            color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                            display: 'flex', alignItems: 'center', gap: 12,
                        }}>
                            <span style={{ width: 20, height: 1, background: 'var(--blaze)', display: 'block' }} />
                            Your Application
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
                            {[
                                { label: 'Type', value: application.application_type === 'individual' ? 'Individual' : 'Hunting Club' },
                                { label: 'Party Size', value: `${application.desired_hunters} hunter${application.desired_hunters !== 1 ? 's' : ''}` },
                                { label: 'Proposed Start', value: formatDate(application.proposed_start) },
                                { label: 'Proposed End', value: formatDate(application.proposed_end) },
                            ].map(row => (
                                <div key={row.label} className="field-row" style={{ padding: '12px 0', borderBottom: '1px dotted var(--parch-deep)' }}>
                                    <span className="field-row-label">{row.label}</span>
                                    <span className="field-row-value">{row.value}</span>
                                </div>
                            ))}
                        </div>

                        {application.message && (
                            <div style={{ marginTop: 24, padding: '16px 20px', background: 'var(--bone)', border: '1px solid var(--parch-deep)' }}>
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--sage-dim)', textTransform: 'uppercase', marginBottom: 8 }}>
                                    Your Original Message
                                </div>
                                <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', lineHeight: 1.6, margin: 0, fontStyle: 'italic' }}>
                                    "{application.message}"
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Right sidebar */}
                <div style={{ position: 'sticky', top: 120 }}>
                    {listing && (
                        <div className="field-card">
                            <div className="field-card-header">
                                <div>
                                    <div className="field-card-label">Listing Details</div>
                                    <div className="field-card-id">AH-{listing.id.slice(0, 8).toUpperCase()}</div>
                                </div>
                            </div>
                            <div className="field-rows">
                                <div className="field-row">
                                    <span className="field-row-label">Type</span>
                                    <span className="field-row-value">{formatType(listing.listing_type)}</span>
                                </div>
                                {listing.season_start && (
                                    <div className="field-row">
                                        <span className="field-row-label">Season</span>
                                        <span className="field-row-value">
                                            {formatDate(listing.season_start)} – {formatDate(listing.season_end)}
                                        </span>
                                    </div>
                                )}
                            </div>
                            <div className="field-footer">
                                <div className="field-price">{formatPrice(listing)}</div>
                            </div>
                            {property && (
                                <Link href={`/properties/${property.slug}`} className="btn-outline" style={{ marginTop: 16, textAlign: 'center', justifyContent: 'center' }}>
                                    View Property →
                                </Link>
                            )}
                        </div>
                    )}

                    <div style={{ marginTop: 20 }}>
                        <Link href="/apply/my-applications" className="btn-outline" style={{ width: '100%', textAlign: 'center', justifyContent: 'center', boxSizing: 'border-box' }}>
                            ← All My Applications
                        </Link>
                    </div>
                </div>
            </div>

            {/* ── FOOTER ──────────────────────────────────────────────────── */}
            <footer className="ah-footer">
                <div className="footer-topo topo-bg-dark" />
                <div className="footer-bot" style={{ maxWidth: 1400, margin: '0 auto', paddingTop: 32, position: 'relative', zIndex: 1 }}>
                    <span className="footer-copy">© {new Date().getFullYear()} American Headhunter, LLC · All Rights Reserved</span>
                    <div className="footer-legal">
                        <a href="/privacy">Privacy</a>
                        <a href="/terms">Terms</a>
                    </div>
                </div>
            </footer>
        </div>
    );
}
