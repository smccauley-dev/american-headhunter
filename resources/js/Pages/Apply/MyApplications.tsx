import { Link } from '@inertiajs/react';

interface ApplicationSummary {
    id: string;
    status: string;
    application_type: string;
    desired_hunters: number;
    proposed_start: string | null;
    proposed_end: string | null;
    submitted_at: string | null;
}

interface MyApplicationsProps {
    applications: ApplicationSummary[];
}

const STATUS_LABEL: Record<string, string> = {
    pending:   'Under Review',
    approved:  'Approved',
    rejected:  'Not Selected',
    withdrawn: 'Withdrawn',
    countered: 'Counter Offer',
};

const STATUS_COLOR: Record<string, string> = {
    pending:   'var(--brass)',
    approved:  'var(--sage)',
    rejected:  'var(--parch-dim)',
    withdrawn: 'var(--parch-dim)',
    countered: 'var(--blaze)',
};

function formatDate(d: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export default function MyApplications({ applications }: MyApplicationsProps) {
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
                    </ul>
                </div>
            </nav>

            {/* ── HEADER ──────────────────────────────────────────────────── */}
            <div style={{ paddingTop: 120, background: 'var(--ink)', position: 'relative', overflow: 'hidden' }}>
                <div className="topo-bg-dark" style={{ position: 'absolute', inset: 0, opacity: 0.5 }} />
                <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 40px 56px', position: 'relative', zIndex: 1 }}>
                    <div style={{
                        fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em',
                        color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 16,
                        display: 'flex', alignItems: 'center', gap: 16,
                    }}>
                        <span style={{ display: 'block', width: 32, height: 1, background: 'var(--brass)' }} />
                        My Applications
                    </div>
                    <h1 style={{
                        fontFamily: 'var(--display)', fontSize: 'clamp(32px, 4vw, 48px)',
                        fontWeight: 400, lineHeight: 1.05, color: 'var(--bone)', marginBottom: 0,
                    }}>
                        Lease Applications
                    </h1>
                </div>
            </div>

            {/* ── LIST ────────────────────────────────────────────────────── */}
            <div style={{ maxWidth: 1200, margin: '0 auto', padding: '56px 40px 80px' }}>

                {applications.length === 0 ? (
                    <div style={{
                        textAlign: 'center', padding: '80px 40px',
                        border: '1px solid var(--parch-deep)', background: 'var(--bone)',
                    }}>
                        <div style={{ fontFamily: 'var(--display)', fontSize: 32, fontWeight: 400, color: 'var(--ink)', marginBottom: 16 }}>
                            No Applications Yet
                        </div>
                        <p style={{ fontFamily: 'var(--body)', fontSize: 16, color: 'var(--ink-lift)', fontStyle: 'italic', marginBottom: 32 }}>
                            Browse available properties and apply for your first lease.
                        </p>
                        <Link href="/properties" className="btn-solid">
                            Browse Properties →
                        </Link>
                    </div>
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
                        {/* Table header */}
                        <div style={{
                            display: 'grid',
                            gridTemplateColumns: '160px 1fr 120px 120px 120px 40px',
                            gap: 16, padding: '12px 20px',
                            borderBottom: '2px solid var(--ink)',
                            fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em',
                            textTransform: 'uppercase', color: 'var(--sage-dim)',
                        }}>
                            <span>Application</span>
                            <span>Dates</span>
                            <span>Type</span>
                            <span>Hunters</span>
                            <span>Status</span>
                            <span />
                        </div>

                        {applications.map(app => (
                            <Link
                                key={app.id}
                                href={`/apply/status/${app.id}`}
                                style={{
                                    display: 'grid',
                                    gridTemplateColumns: '160px 1fr 120px 120px 120px 40px',
                                    gap: 16, padding: '18px 20px', textDecoration: 'none',
                                    borderBottom: '1px solid var(--parch-deep)',
                                    background: 'transparent',
                                    transition: 'background 0.1s',
                                    alignItems: 'center',
                                }}
                                onMouseEnter={e => (e.currentTarget.style.background = 'var(--bone)')}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                            >
                                <span style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.1em', color: 'var(--ink)' }}>
                                    AH-{app.id.slice(0, 8).toUpperCase()}
                                </span>
                                <span style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink-lift)' }}>
                                    {formatDate(app.proposed_start)} – {formatDate(app.proposed_end)}
                                </span>
                                <span style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.08em', color: 'var(--ink-lift)', textTransform: 'capitalize' }}>
                                    {app.application_type}
                                </span>
                                <span style={{ fontFamily: 'var(--mono)', fontSize: 11, color: 'var(--ink-lift)' }}>
                                    {app.desired_hunters}
                                </span>
                                <span style={{
                                    fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.12em',
                                    textTransform: 'uppercase', color: STATUS_COLOR[app.status] ?? 'var(--parch-dim)',
                                }}>
                                    {STATUS_LABEL[app.status] ?? app.status}
                                </span>
                                <span style={{ fontFamily: 'var(--mono)', fontSize: 14, color: 'var(--brass)' }}>›</span>
                            </Link>
                        ))}
                    </div>
                )}

                <div style={{ marginTop: 40 }}>
                    <Link href="/properties" className="btn-outline">
                        ← Browse More Properties
                    </Link>
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
