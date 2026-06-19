import { useState, useEffect } from 'react'
import { Link, usePage, Head } from '@inertiajs/react'

// ── Types ────────────────────────────────────────────────────────────────────

interface Perk {
    label: string
    description: string | null
}

interface Plan {
    id: string
    plan_key: string
    display_name: string
    tagline: string | null
    description: string | null
    monthly_price_cents: number | null
    annual_price_cents: number | null
    monthly_enabled: boolean
    annual_enabled: boolean
    is_default_free: boolean
    header_image_url: string | null
    accent_color: string | null
    badge_label: string | null
    is_featured: boolean
    perks: Perk[]
}

interface Props {
    groups: Record<string, Plan[]>
}

// ── Constants ────────────────────────────────────────────────────────────────

const ACCOUNT_LABELS: Record<string, string> = {
    hunter:     'Hunters',
    landowner:  'Landowners',
    club:       'Clubs',
    outfitter:  'Outfitters',
    consultant: 'Consultants',
    seller:     'Sellers',
}

const ACCOUNT_ORDER = ['hunter', 'landowner', 'club', 'outfitter', 'consultant', 'seller']

type Cycle = 'monthly' | 'annual'

// ── Helpers ──────────────────────────────────────────────────────────────────

function priceCents(plan: Plan, cycle: Cycle): number | null {
    return cycle === 'annual' ? plan.annual_price_cents : plan.monthly_price_cents
}

function formatPrice(plan: Plan, cycle: Cycle): { amount: string; suffix: string } {
    const cents = priceCents(plan, cycle)
    if (plan.is_default_free && (!cents || cents === 0)) return { amount: 'Free', suffix: '' }
    if (cents === null || cents === 0)                   return { amount: 'Contact', suffix: '' }
    const dollars = cents / 100
    const amount = `$${dollars.toLocaleString(undefined, { maximumFractionDigits: dollars % 1 === 0 ? 0 : 2 })}`
    return { amount, suffix: cycle === 'annual' ? '/yr' : '/mo' }
}

// ── Main component ───────────────────────────────────────────────────────────

export default function Pricing({ groups }: Props) {
    const [scrolled, setScrolled] = useState(false)
    const { auth } = usePage<{ auth?: { authenticated: boolean } }>().props

    const availableTypes = ACCOUNT_ORDER.filter(t => (groups[t]?.length ?? 0) > 0)
    const [activeType, setActiveType] = useState(availableTypes[0] ?? 'hunter')
    const [cycle, setCycle] = useState<Cycle>('monthly')

    useEffect(() => {
        const handler = () => setScrolled(window.scrollY > 10)
        window.addEventListener('scroll', handler)
        return () => window.removeEventListener('scroll', handler)
    }, [])

    const plans = groups[activeType] ?? []

    return (
        <>
            <Head title="Membership Plans — American Headhunter" />
            <div className="ah-page">

                {/* ── NAV ─────────────────────────────────────────────────── */}
                <nav className={`ah-nav${scrolled ? ' scrolled' : ''}`}>
                    <div className="nav-strip">
                        <div className="nav-strip-left">
                            <span><span className="strip-dot" />Hunting Lease Marketplace</span>
                        </div>
                        <div className="nav-strip-right">
                            <span>Hunters</span>
                            <span>Landowners</span>
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
                            <li><a href="/auctions">Auctions</a></li>
                            <li><a href="/outfitters">Outfitters</a></li>
                            <li><a href="/pricing" style={{ color: 'var(--blaze)' }}>Pricing</a></li>
                        </ul>
                        <div className="nav-actions">
                            {auth?.authenticated
                                ? <Link href="/member" className="nav-link-text">My Leases</Link>
                                : <Link href="/login" className="nav-link-text">Sign In</Link>
                            }
                            <Link href="/get-started" className="nav-cta">Get Started →</Link>
                        </div>
                    </div>
                </nav>

                {/* ── PAGE HERO ───────────────────────────────────────────── */}
                <div className="topo-bg-dark" style={{ background: 'var(--ink)', paddingTop: 120, paddingBottom: 48, position: 'relative' }}>
                    <div className="reg-mark reg-tl" />
                    <div className="reg-mark reg-tr" />
                    <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 40px' }}>
                        <div className="section-num" style={{ marginBottom: 16 }}>Membership</div>
                        <h1 style={{ fontFamily: 'var(--display)', fontSize: 52, fontWeight: 400, color: 'var(--bone)', margin: '0 0 12px', letterSpacing: '-0.02em', lineHeight: 1.1 }}>
                            Plans &amp; Pricing
                        </h1>
                        <p style={{ fontFamily: 'var(--body)', fontSize: 18, color: 'var(--parch-deep)', margin: 0 }}>
                            Pick the membership that fits how you hunt, lease, or list.
                        </p>
                    </div>
                </div>

                {/* ── CONTROLS ─────────────────────────────────────────────── */}
                <div style={{ maxWidth: 1200, margin: '0 auto', padding: '40px 40px 0' }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 16 }}>

                        {/* Account-type tabs */}
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                            {availableTypes.map(type => {
                                const active = type === activeType
                                return (
                                    <button
                                        key={type}
                                        onClick={() => setActiveType(type)}
                                        style={{
                                            fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.1em',
                                            textTransform: 'uppercase', cursor: 'pointer',
                                            padding: '9px 16px',
                                            background: active ? 'var(--ink)' : 'transparent',
                                            color: active ? 'var(--bone)' : 'var(--ink)',
                                            border: `1px solid ${active ? 'var(--ink)' : 'var(--parch-dim)'}`,
                                        }}
                                    >
                                        {ACCOUNT_LABELS[type] ?? type}
                                    </button>
                                )
                            })}
                        </div>

                        {/* Billing-cycle toggle */}
                        <div style={{ display: 'flex', border: '1px solid var(--parch-dim)' }}>
                            {(['monthly', 'annual'] as Cycle[]).map(c => {
                                const active = c === cycle
                                return (
                                    <button
                                        key={c}
                                        onClick={() => setCycle(c)}
                                        style={{
                                            fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.1em',
                                            textTransform: 'uppercase', cursor: 'pointer',
                                            padding: '9px 18px',
                                            background: active ? 'var(--blaze)' : 'transparent',
                                            color: active ? 'var(--bone)' : 'var(--ink)',
                                            border: 'none',
                                        }}
                                    >
                                        {c === 'monthly' ? 'Monthly' : 'Annual'}
                                    </button>
                                )
                            })}
                        </div>
                    </div>
                </div>

                {/* ── CARDS ────────────────────────────────────────────────── */}
                <div style={{ maxWidth: 1200, margin: '0 auto', padding: '32px 40px 80px' }}>
                    {plans.length === 0 ? (
                        <div style={{ background: 'var(--bone)', border: '1px solid var(--parch-dim)', padding: '64px 32px', textAlign: 'center' }}>
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.15em', textTransform: 'uppercase', color: 'var(--blaze)', marginBottom: 12 }}>
                                Coming Soon
                            </div>
                            <p style={{ fontFamily: 'var(--body)', fontSize: 17, color: 'var(--ink)', margin: 0 }}>
                                Plans for this membership type aren&apos;t published yet.
                            </p>
                        </div>
                    ) : (
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 24, alignItems: 'stretch' }}>
                            {plans.map(plan => (
                                <PlanCard
                                    key={plan.id}
                                    plan={plan}
                                    cycle={cycle}
                                    authenticated={auth?.authenticated ?? false}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    )
}

// ── Sub-components ────────────────────────────────────────────────────────────

function PlanCard({ plan, cycle, authenticated }: { plan: Plan; cycle: Cycle; authenticated: boolean }) {
    const accent = plan.accent_color || 'var(--blaze)'
    const price = formatPrice(plan, cycle)
    const ctaHref = authenticated ? `/member` : `/get-started?plan=${encodeURIComponent(plan.plan_key)}`

    return (
        <div style={{
            background: 'var(--bone)',
            border: plan.is_featured ? `2px solid ${accent}` : '1px solid var(--parch-dim)',
            display: 'flex', flexDirection: 'column', height: '100%',
            boxShadow: plan.is_featured ? '0 8px 24px rgba(10,21,18,0.12)' : 'none',
        }}>

            {/* ── Header image / accent banner ──────────────────────────── */}
            <div style={{
                position: 'relative',
                height: 140,
                background: plan.header_image_url
                    ? `linear-gradient(180deg, rgba(10,21,18,0) 40%, rgba(10,21,18,0.55) 100%), url(${plan.header_image_url}) center/cover`
                    : 'var(--ink)',
                overflow: 'hidden',
            }}>
                {/* Accent stripe for image-less cards */}
                {!plan.header_image_url && (
                    <div style={{ position: 'absolute', inset: 0, background: `linear-gradient(135deg, ${accent}22, transparent 60%)` }} />
                )}

                {plan.badge_label && (
                    <span style={{
                        position: 'absolute', top: 12, left: 12,
                        fontFamily: 'var(--mono)', fontSize: 9, fontWeight: 700,
                        letterSpacing: '.12em', textTransform: 'uppercase',
                        color: 'var(--bone)', background: accent,
                        padding: '4px 9px',
                    }}>
                        {plan.badge_label}
                    </span>
                )}

                <div style={{ position: 'absolute', bottom: 14, left: 16, right: 16 }}>
                    <div style={{ fontFamily: 'var(--display)', fontSize: 22, fontWeight: 500, color: 'var(--bone)', lineHeight: 1.15 }}>
                        {plan.display_name}
                    </div>
                    {plan.tagline && (
                        <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-deep)', letterSpacing: '.06em', marginTop: 4 }}>
                            {plan.tagline}
                        </div>
                    )}
                </div>
            </div>

            {/* ── Body ──────────────────────────────────────────────────── */}
            <div style={{ padding: '20px 18px', flex: 1, display: 'flex', flexDirection: 'column', gap: 16 }}>

                {/* Price */}
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 4 }}>
                    <span style={{ fontFamily: 'var(--display)', fontSize: 32, fontWeight: 600, color: 'var(--ink)', lineHeight: 1 }}>
                        {price.amount}
                    </span>
                    {price.suffix && (
                        <span style={{ fontFamily: 'var(--mono)', fontSize: 12, color: 'var(--parch-deep)', letterSpacing: '.06em' }}>
                            {price.suffix}
                        </span>
                    )}
                </div>

                {plan.description && (
                    <p style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--sage-dim)', margin: 0, lineHeight: 1.5 }}>
                        {plan.description}
                    </p>
                )}

                {/* Perks checklist */}
                {plan.perks.length > 0 && (
                    <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 9 }}>
                        {plan.perks.map((perk, i) => (
                            <li key={i} style={{ display: 'flex', gap: 9, alignItems: 'flex-start' }}>
                                <span style={{ color: accent, fontFamily: 'var(--mono)', fontSize: 13, lineHeight: 1.4, flexShrink: 0 }}>✓</span>
                                <span style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink)', lineHeight: 1.4 }}>
                                    {perk.label}
                                    {perk.description && (
                                        <span style={{ display: 'block', fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-deep)', letterSpacing: '.04em', marginTop: 2 }}>
                                            {perk.description}
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {/* CTA */}
                <Link
                    href={ctaHref}
                    style={{
                        marginTop: 'auto',
                        textAlign: 'center',
                        fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.12em',
                        textTransform: 'uppercase', textDecoration: 'none',
                        padding: '12px 16px',
                        background: plan.is_featured ? accent : 'var(--ink)',
                        color: 'var(--bone)',
                    }}
                >
                    {plan.is_default_free ? 'Start Free →' : 'Get Started →'}
                </Link>
            </div>
        </div>
    )
}
