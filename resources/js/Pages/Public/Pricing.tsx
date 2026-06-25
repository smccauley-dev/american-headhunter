import { useState, useEffect, useRef } from 'react'
import { Link, usePage, Head, router } from '@inertiajs/react'
import PublicNav from '@/Components/Public/PublicNav'

// ── Types ────────────────────────────────────────────────────────────────────

interface Perk {
    label: string
    description: string | null
}

interface PromoCode {
    code: string
    label: string | null
    discount_summary: string | null
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
    promo_codes: PromoCode[]
}

interface CalloutButton {
    label: string
    url: string
}

interface CalloutPlan {
    monthly_price_cents: number | null
    annual_price_cents: number | null
    is_default_free: boolean
}

interface Callout {
    id: string
    eyebrow: string | null
    body: string
    features: Perk[]
    buttons: CalloutButton[]
    accent_color: string | null
    // Optional linked plan — present only to display its live price.
    plan: CalloutPlan | null
}

interface Props {
    groups: Record<string, Plan[]>
    callouts: Record<string, Callout[]>
    current_account_type: string | null
    // The member's current paid plan key (null when free / no subscription).
    current_plan_key: string | null
    has_active_subscription: boolean
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
    // Whole-dollar prices stay clean ($100); any cents show two digits ($99.90,
    // not $99.9) so the card matches the signup page's currency formatting.
    const fractionDigits = cents % 100 === 0 ? 0 : 2
    const amount = `$${dollars.toLocaleString(undefined, { minimumFractionDigits: fractionDigits, maximumFractionDigits: fractionDigits })}`
    return { amount, suffix: cycle === 'annual' ? '/yr' : '/mo' }
}

// The live price of a callout's linked plan, formatted like the plan cards and
// honoring the monthly/annual toggle. Null when there's no displayable price.
function calloutPrice(plan: CalloutPlan, cycle: Cycle): string | null {
    const cents = cycle === 'annual' ? plan.annual_price_cents : plan.monthly_price_cents
    if (plan.is_default_free && (!cents || cents === 0)) return 'Free'
    if (cents === null || cents === 0) return null
    const dollars = cents / 100
    const fractionDigits = cents % 100 === 0 ? 0 : 2
    return `$${dollars.toLocaleString(undefined, { minimumFractionDigits: fractionDigits, maximumFractionDigits: fractionDigits })}${cycle === 'annual' ? '/yr' : '/mo'}`
}

// ── Main component ───────────────────────────────────────────────────────────

export default function Pricing({ groups, callouts, current_account_type, current_plan_key, has_active_subscription }: Props) {
    const { auth } = usePage<{ auth?: { authenticated: boolean } }>().props

    const availableTypes = ACCOUNT_ORDER.filter(t => (groups[t]?.length ?? 0) > 0)

    // A ?plan= deep link (e.g. after signing up with a chosen plan) opens this page
    // on that plan's tab with the card highlighted, so the choice carries through.
    const [highlightKey] = useState<string | null>(() => new URLSearchParams(window.location.search).get('plan'))
    const initialType = (highlightKey && ACCOUNT_ORDER.find(t => (groups[t] ?? []).some(p => p.plan_key === highlightKey)))
        || availableTypes[0] || 'hunter'

    const [activeType, setActiveType] = useState(initialType)
    const [cycle, setCycle] = useState<Cycle>('monthly')

    const plans = groups[activeType] ?? []
    const activeCallouts = callouts[activeType] ?? []

    return (
        <>
            <Head title="Membership Plans — American Headhunter" />
            <div className="ah-page">

                {/* ── NAV ─────────────────────────────────────────────────── */}
                <PublicNav />

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
                                    canSubscribe={(auth?.authenticated ?? false) && current_account_type === activeType}
                                    isCurrentPlan={has_active_subscription && current_account_type === activeType && plan.plan_key === current_plan_key}
                                    hasActiveSubscription={has_active_subscription && current_account_type === activeType}
                                    highlight={plan.plan_key === highlightKey}
                                />
                            ))}
                        </div>
                    )}

                    {activeCallouts.map(callout => (
                        <div key={callout.id} style={{
                            marginTop: 40, padding: '28px 32px',
                            background: 'var(--ink, #0a1512)', color: '#f4ecdc',
                            display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between',
                            gap: 40, flexWrap: 'wrap',
                            borderLeft: `4px solid ${callout.accent_color || 'var(--blaze)'}`,
                        }}>
                            {/* Left column — copy, price, and CTAs */}
                            <div style={{ flex: '1 1 340px', maxWidth: 560 }}>
                                {callout.eyebrow && (
                                    <p style={{
                                        fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                                        letterSpacing: '0.18em', textTransform: 'uppercase',
                                        color: callout.accent_color || 'var(--blaze)', margin: '0 0 8px',
                                    }}>
                                        {callout.eyebrow}
                                    </p>
                                )}
                                <p style={{ fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 18, margin: 0, lineHeight: 1.45 }}>
                                    {callout.body}
                                </p>
                                {callout.plan && calloutPrice(callout.plan, cycle) && (
                                    <p style={{
                                        fontFamily: "'JetBrains Mono', monospace", fontSize: 24,
                                        fontWeight: 600, color: callout.accent_color || 'var(--blaze)',
                                        margin: '14px 0 0',
                                    }}>
                                        {calloutPrice(callout.plan, cycle)}
                                    </p>
                                )}
                                {callout.buttons.length > 0 && (
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 20 }}>
                                        {callout.buttons.map((button, i) => (
                                            <a
                                                key={i}
                                                href={button.url}
                                                style={{
                                                    padding: '13px 26px',
                                                    background: callout.accent_color || 'var(--blaze)',
                                                    color: '#fff', textDecoration: 'none',
                                                    fontFamily: "'JetBrains Mono', monospace", fontSize: 11,
                                                    fontWeight: 600, letterSpacing: '0.15em', textTransform: 'uppercase',
                                                }}
                                            >
                                                {button.label} →
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </div>
                            {/* Right column — feature bullets fill the space beside the copy */}
                            {callout.features.length > 0 && (
                                <ul style={{
                                    flex: '1 1 320px', listStyle: 'none', padding: 0, margin: 0,
                                    display: 'grid', gridAutoFlow: 'column',
                                    gridTemplateRows: 'repeat(5, auto)', gap: '8px 36px',
                                    justifyContent: 'start',
                                }}>
                                    {callout.features.map((f, i) => (
                                        <li key={i} style={{ display: 'flex', gap: 10, alignItems: 'baseline', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: 15 }}>
                                            <span style={{ color: callout.accent_color || 'var(--blaze)', flexShrink: 0 }}>✓</span>
                                            <span>
                                                {f.label}
                                                {f.description && <span style={{ opacity: 0.7 }}> — {f.description}</span>}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    )
}

// ── Sub-components ────────────────────────────────────────────────────────────

function PlanCard({ plan, cycle, authenticated, canSubscribe, isCurrentPlan, hasActiveSubscription, highlight }: { plan: Plan; cycle: Cycle; authenticated: boolean; canSubscribe: boolean; isCurrentPlan: boolean; hasActiveSubscription: boolean; highlight: boolean }) {
    const accent = plan.accent_color || 'var(--blaze)'
    const price = formatPrice(plan, cycle)
    const [submitting, setSubmitting] = useState(false)
    const [promoOpen, setPromoOpen] = useState(false)
    const [promoInput, setPromoInput] = useState('')
    const cardRef = useRef<HTMLDivElement>(null)

    // When deep-linked via ?plan=, bring this card into view once on mount.
    useEffect(() => {
        if (highlight && cardRef.current) {
            cardRef.current.scrollIntoView({ behavior: 'smooth', block: 'center' })
        }
    }, [highlight])

    const isPaid = !plan.is_default_free && ((priceCents(plan, cycle) ?? 0) > 0)
    // An existing subscriber switches plans (immediate + prorated); a logged-in
    // member of this account type with no subscription checks out directly; the
    // free plan and everyone else follow the marketing/get-started path.
    const showSwitch   = hasActiveSubscription && isPaid && !isCurrentPlan
    const showCheckout = canSubscribe && isPaid && !hasActiveSubscription
    const ctaHref = authenticated ? `/member` : `/get-started?plan=${encodeURIComponent(plan.plan_key)}&interval=${cycle}`

    const startCheckout = () => {
        setSubmitting(true)
        // An explicitly typed code overrides auto-apply; when blank, the server
        // auto-applies any code advertised on this plan's card.
        const code = promoInput.trim()
        router.post('/member/membership/checkout',
            { plan_key: plan.plan_key, interval: cycle, ...(code ? { promo_code: code } : {}) },
            { onFinish: () => setSubmitting(false) },
        )
    }

    const switchPlan = () => {
        if (! window.confirm(
            `Switch to ${plan.display_name}? The change takes effect immediately and ` +
            `your next invoice is prorated for the difference. Your billing interval ` +
            `stays the same.`
        )) return
        setSubmitting(true)
        router.post('/member/membership/change',
            { plan_key: plan.plan_key },
            { onFinish: () => setSubmitting(false) },
        )
    }

    const ctaStyle: React.CSSProperties = {
        marginTop: 'auto',
        textAlign: 'center',
        fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.12em',
        textTransform: 'uppercase', textDecoration: 'none',
        padding: '12px 16px',
        background: plan.is_featured ? accent : 'var(--ink)',
        color: 'var(--bone)',
    }

    return (
        <div ref={cardRef} style={{
            background: 'var(--bone)',
            border: (highlight || plan.is_featured) ? `2px solid ${accent}` : '1px solid var(--parch-dim)',
            display: 'flex', flexDirection: 'column', height: '100%',
            boxShadow: highlight
                ? `0 0 0 3px ${accent}55, 0 8px 24px rgba(10,21,18,0.18)`
                : plan.is_featured ? '0 8px 24px rgba(10,21,18,0.12)' : 'none',
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

                {/* Advertised promo codes — auto-applied at checkout */}
                {plan.promo_codes.length > 0 && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                        {plan.promo_codes.map(promo => (
                            <div
                                key={promo.code}
                                style={{
                                    display: 'flex', alignItems: 'baseline', gap: 8, flexWrap: 'wrap',
                                    border: `1px dashed ${accent}`, background: 'var(--parch)',
                                    padding: '8px 10px',
                                }}
                            >
                                <span style={{
                                    fontFamily: 'var(--mono)', fontSize: 11, fontWeight: 700,
                                    letterSpacing: '.08em', textTransform: 'uppercase', color: accent,
                                }}>
                                    {promo.code}
                                </span>
                                <span style={{ fontFamily: 'var(--body)', fontSize: 12, color: 'var(--ink)' }}>
                                    {promo.discount_summary
                                        ? `${promo.discount_summary} — applied automatically`
                                        : (promo.label ?? 'applied automatically')}
                                </span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Optional manual promo code (for codes not advertised on the card) */}
                {showCheckout && (
                    promoOpen ? (
                        <input
                            type="text"
                            value={promoInput}
                            onChange={e => setPromoInput(e.target.value.toUpperCase())}
                            placeholder="Promo code"
                            autoFocus
                            style={{
                                fontFamily: 'var(--mono)', fontSize: 12, letterSpacing: '.06em',
                                textTransform: 'uppercase', color: 'var(--ink)',
                                padding: '9px 10px', background: 'var(--bone)',
                                border: '1px solid var(--parch-dim)', outline: 'none',
                            }}
                        />
                    ) : (
                        <button
                            type="button"
                            onClick={() => setPromoOpen(true)}
                            style={{
                                alignSelf: 'flex-start', background: 'none', border: 'none', padding: 0,
                                fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '.1em',
                                textTransform: 'uppercase', color: 'var(--sage-dim)',
                                cursor: 'pointer', textDecoration: 'underline',
                            }}
                        >
                            Have a promo code?
                        </button>
                    )
                )}

                {/* CTA */}
                {isCurrentPlan ? (
                    <div style={{ ...ctaStyle, background: 'var(--parch-dim)', color: 'var(--sage-dim)', cursor: 'default' }}>
                        Current Plan
                    </div>
                ) : showSwitch ? (
                    <button
                        type="button"
                        onClick={switchPlan}
                        disabled={submitting}
                        style={{ ...ctaStyle, border: 'none', cursor: submitting ? 'wait' : 'pointer', opacity: submitting ? 0.7 : 1 }}
                    >
                        {submitting ? 'Switching…' : 'Switch to this plan'}
                    </button>
                ) : showCheckout ? (
                    <button
                        type="button"
                        onClick={startCheckout}
                        disabled={submitting}
                        style={{ ...ctaStyle, border: 'none', cursor: submitting ? 'wait' : 'pointer', opacity: submitting ? 0.7 : 1 }}
                    >
                        {submitting ? 'Redirecting…' : 'Subscribe →'}
                    </button>
                ) : hasActiveSubscription ? (
                    // Subscribers manage downgrade-to-free (cancel) from the membership panel.
                    <Link href="/member" style={{ ...ctaStyle, background: 'var(--ink)' }}>
                        Manage Membership →
                    </Link>
                ) : (
                    <Link href={ctaHref} style={ctaStyle}>
                        {plan.is_default_free ? 'Start Free →' : 'Get Started →'}
                    </Link>
                )}
            </div>
        </div>
    )
}
