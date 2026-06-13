import { useState, useEffect } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { Head } from '@inertiajs/react'
import { US_STATES } from '@/lib/usStates'

// ── Types ────────────────────────────────────────────────────────────────────

interface Listing {
    id: string
    listing_type: string
    season_start: string | null
    season_end: string | null
    price_per_hunter: string | null
    price_total: string | null
    max_hunters: number | null
    property: {
        id: string
        title: string
        slug: string
        state_code: string
        county: string
        total_acres: number
        huntable_acres: number | null
        species: string[]
    }
}

interface Paginator {
    data: Listing[]
    current_page: number
    last_page: number
    total: number
    from: number | null
    to: number | null
}

interface Filters {
    state_code?: string
    listing_type?: string
    species?: string[]
    min_price?: string
    max_price?: string
}

interface Props {
    listings: Paginator
    filters: Filters
}

// ── Constants ────────────────────────────────────────────────────────────────

const SPECIES_OPTIONS = [
    { code: 'whitetail_deer', label: 'Whitetail Deer' },
    { code: 'turkey',         label: 'Wild Turkey' },
    { code: 'hog',            label: 'Wild Hog' },
    { code: 'waterfowl',      label: 'Waterfowl' },
    { code: 'elk',            label: 'Elk' },
    { code: 'dove',           label: 'Dove' },
    { code: 'pheasant',       label: 'Pheasant' },
    { code: 'bear',           label: 'Bear' },
    { code: 'mule_deer',      label: 'Mule Deer' },
    { code: 'quail',          label: 'Quail' },
]

const SPECIES_NAMES: Record<string, string> = Object.fromEntries(
    SPECIES_OPTIONS.map(s => [s.code, s.label])
)

const TYPE_LABELS: Record<string, string> = {
    annual_lease:   'Annual Lease',
    seasonal_lease: 'Seasonal',
    day_hunt:       'Day Hunt',
    auction:        'Auction',
}

const TYPE_COLORS: Record<string, string> = {
    annual_lease:   '#0A1512',
    seasonal_lease: '#4a5440',
    day_hunt:       '#C84C21',
    auction:        '#b8934a',
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatPrice(listing: Listing): string {
    if (listing.price_total)      return `$${parseInt(listing.price_total).toLocaleString()}`
    if (listing.price_per_hunter) return `$${parseInt(listing.price_per_hunter).toLocaleString()}/hunter`
    return 'Contact for pricing'
}

function formatAcres(listing: Listing): string {
    const acres = listing.property.huntable_acres ?? listing.property.total_acres
    return `${acres.toLocaleString()} ac`
}

// ── Main component ───────────────────────────────────────────────────────────

export default function Properties({ listings, filters }: Props) {
    const [scrolled, setScrolled] = useState(false)
    const { auth } = usePage<{ auth?: { authenticated: boolean } }>().props

    const [state,       setState]       = useState(filters.state_code   ?? '')
    const [listingType, setListingType] = useState(filters.listing_type ?? '')
    const [species,     setSpecies]     = useState<string[]>(filters.species ?? [])
    const [minPrice,    setMinPrice]    = useState(filters.min_price ?? '')
    const [maxPrice,    setMaxPrice]    = useState(filters.max_price ?? '')

    useEffect(() => {
        const handler = () => setScrolled(window.scrollY > 10)
        window.addEventListener('scroll', handler)
        return () => window.removeEventListener('scroll', handler)
    }, [])

    function buildParams(overrides: Record<string, unknown> = {}) {
        const params: Record<string, unknown> = {}
        if (state)                 params.state_code    = state
        if (listingType)           params.listing_type  = listingType
        if (species.length > 0)    params.species       = species
        if (minPrice)              params.min_price     = minPrice
        if (maxPrice)              params.max_price     = maxPrice
        return { ...params, ...overrides }
    }

    function applyFilters(overrides: Record<string, unknown> = {}) {
        router.get('/properties', buildParams(overrides) as Record<string, string>, {
            preserveScroll: false,
        })
    }

    function toggleSpecies(code: string) {
        const next = species.includes(code)
            ? species.filter(s => s !== code)
            : [...species, code]
        setSpecies(next)
        const params = buildParams({ species: next.length > 0 ? next : undefined })
        router.get('/properties', params as Record<string, string>)
    }

    function goToPage(page: number) {
        router.get('/properties', { ...buildParams(), page } as Record<string, string>)
    }

    const hasFilters = !!(state || listingType || species.length || minPrice || maxPrice)

    return (
        <>
            <Head title="Find Hunting Land — American Headhunter" />
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
                            <li><a href="/properties" style={{ color: 'var(--blaze)' }}>Find Land</a></li>
                            <li><a href="/auctions">Auctions</a></li>
                            <li><a href="/outfitters">Outfitters</a></li>
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
                        <div className="section-num" style={{ marginBottom: 16 }}>Find Land</div>
                        <h1 style={{ fontFamily: 'var(--display)', fontSize: 52, fontWeight: 400, color: 'var(--bone)', margin: '0 0 12px', letterSpacing: '-0.02em', lineHeight: 1.1 }}>
                            Hunting Land for Lease
                        </h1>
                        <p style={{ fontFamily: 'var(--body)', fontSize: 18, color: 'var(--parch-deep)', margin: 0 }}>
                            {listings.total.toLocaleString()} {listings.total === 1 ? 'listing' : 'listings'} across the United States
                        </p>
                    </div>
                </div>

                {/* ── MAIN CONTENT ─────────────────────────────────────────── */}
                <div style={{ maxWidth: 1200, margin: '0 auto', padding: '48px 40px 80px', display: 'grid', gridTemplateColumns: '260px 1fr', gap: 40, alignItems: 'start' }}>

                    {/* ── FILTER SIDEBAR ──────────────────────────────────── */}
                    <div style={{ position: 'sticky', top: 100 }}>

                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                            <span style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '.15em', textTransform: 'uppercase', color: 'var(--sage-dim)', fontWeight: 700 }}>
                                Filters
                            </span>
                            {hasFilters && (
                                <button
                                    onClick={() => {
                                        setState(''); setListingType(''); setSpecies([]); setMinPrice(''); setMaxPrice('')
                                        router.get('/properties')
                                    }}
                                    style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--blaze)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                                >
                                    Clear all
                                </button>
                            )}
                        </div>

                        {/* State */}
                        <FilterBlock label="State">
                            <select
                                value={state}
                                onChange={e => { setState(e.target.value); applyFilters({ state_code: e.target.value || undefined }) }}
                                style={selectStyle}
                            >
                                <option value="">All States</option>
                                {US_STATES.map(([code, name]) => (
                                    <option key={code} value={code}>{name}</option>
                                ))}
                            </select>
                        </FilterBlock>

                        {/* Listing type */}
                        <FilterBlock label="Lease Type">
                            <select
                                value={listingType}
                                onChange={e => { setListingType(e.target.value); applyFilters({ listing_type: e.target.value || undefined }) }}
                                style={selectStyle}
                            >
                                <option value="">All Types</option>
                                <option value="annual_lease">Annual Lease</option>
                                <option value="seasonal_lease">Seasonal Lease</option>
                                <option value="day_hunt">Day Hunt</option>
                            </select>
                        </FilterBlock>

                        {/* Price range */}
                        <FilterBlock label="Price Range">
                            <div style={{ display: 'flex', gap: 8 }}>
                                <input
                                    type="number"
                                    placeholder="Min $"
                                    value={minPrice}
                                    onChange={e => setMinPrice(e.target.value)}
                                    onBlur={() => applyFilters()}
                                    onKeyDown={e => e.key === 'Enter' && applyFilters()}
                                    style={{ ...inputStyle, flex: 1 }}
                                />
                                <input
                                    type="number"
                                    placeholder="Max $"
                                    value={maxPrice}
                                    onChange={e => setMaxPrice(e.target.value)}
                                    onBlur={() => applyFilters()}
                                    onKeyDown={e => e.key === 'Enter' && applyFilters()}
                                    style={{ ...inputStyle, flex: 1 }}
                                />
                            </div>
                        </FilterBlock>

                        {/* Species */}
                        <FilterBlock label="Game Species">
                            {SPECIES_OPTIONS.map(s => (
                                <label key={s.code} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '5px 0', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        checked={species.includes(s.code)}
                                        onChange={() => toggleSpecies(s.code)}
                                        style={{ accentColor: 'var(--blaze)', width: 14, height: 14 }}
                                    />
                                    <span style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink)' }}>{s.label}</span>
                                </label>
                            ))}
                        </FilterBlock>
                    </div>

                    {/* ── LISTINGS GRID ───────────────────────────────────── */}
                    <div>

                        {/* Results count */}
                        {listings.from !== null && (
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 11, color: 'var(--sage-dim)', marginBottom: 24, letterSpacing: '.08em' }}>
                                Showing {listings.from}–{listings.to} of {listings.total.toLocaleString()} listings
                            </div>
                        )}

                        {/* Empty state */}
                        {listings.data.length === 0 && (
                            <div style={{ background: 'var(--bone)', border: '1px solid var(--parch-dim)', padding: '64px 32px', textAlign: 'center' }}>
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.15em', textTransform: 'uppercase', color: 'var(--blaze)', marginBottom: 12 }}>
                                    No Results
                                </div>
                                <p style={{ fontFamily: 'var(--body)', fontSize: 17, color: 'var(--ink)', margin: '0 0 20px' }}>
                                    No listings match your current filters.
                                </p>
                                <button
                                    onClick={() => { setState(''); setListingType(''); setSpecies([]); setMinPrice(''); setMaxPrice(''); router.get('/properties') }}
                                    style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '.12em', textTransform: 'uppercase', color: 'var(--blaze)', background: 'none', border: '1px solid var(--blaze)', padding: '10px 20px', cursor: 'pointer' }}
                                >
                                    Clear Filters
                                </button>
                            </div>
                        )}

                        {/* Cards */}
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
                            {listings.data.map(listing => (
                                <ListingCard key={listing.id} listing={listing} authenticated={auth?.authenticated ?? false} />
                            ))}
                        </div>

                        {/* Pagination */}
                        {listings.last_page > 1 && (
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 48 }}>
                                {Array.from({ length: listings.last_page }, (_, i) => i + 1).map(page => (
                                    <button
                                        key={page}
                                        onClick={() => goToPage(page)}
                                        style={{
                                            width: 36, height: 36,
                                            fontFamily: 'var(--mono)', fontSize: 12,
                                            background: page === listings.current_page ? 'var(--ink)' : 'transparent',
                                            color: page === listings.current_page ? 'var(--bone)' : 'var(--ink)',
                                            border: `1px solid ${page === listings.current_page ? 'var(--ink)' : 'var(--parch-dim)'}`,
                                            cursor: 'pointer',
                                        }}
                                    >
                                        {page}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    )
}

// ── Sub-components ────────────────────────────────────────────────────────────

function FilterBlock({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div style={{ marginBottom: 24, paddingBottom: 24, borderBottom: '1px solid var(--parch-dim)' }}>
            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '.12em', textTransform: 'uppercase', color: 'var(--sage-dim)', marginBottom: 10 }}>
                {label}
            </div>
            {children}
        </div>
    )
}

const TYPE_BADGE_COLOR: Record<string, string> = {
    annual_lease:   'var(--blaze)',
    seasonal_lease: 'var(--brass)',
    day_hunt:       'var(--parch-deep)',
    auction:        'var(--brass)',
}

function ListingCard({ listing, authenticated }: { listing: Listing; authenticated: boolean }) {
    const applyHref = authenticated ? `/apply/${listing.id}` : '/get-started'
    const acres = listing.property.huntable_acres ?? listing.property.total_acres

    return (
        <div style={{ background: 'var(--bone)', border: '1px solid var(--parch-dim)', display: 'flex', flexDirection: 'column' }}>

            {/* Card header */}
            <div style={{ background: 'var(--ink)', padding: '16px 18px', position: 'relative' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                    <span style={{
                        fontFamily: 'var(--mono)', fontSize: 9, fontWeight: 700,
                        letterSpacing: '.12em', textTransform: 'uppercase',
                        color: TYPE_BADGE_COLOR[listing.listing_type] ?? 'var(--blaze)',
                        background: 'rgba(255,255,255,0.06)',
                        padding: '3px 8px', border: '1px solid rgba(255,255,255,0.1)',
                    }}>
                        {TYPE_LABELS[listing.listing_type] ?? listing.listing_type}
                    </span>
                    <span style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-deep)', letterSpacing: '.06em' }}>
                        {acres.toLocaleString()} ac
                    </span>
                </div>
                <div style={{ fontFamily: 'var(--display)', fontSize: 17, fontWeight: 500, color: 'var(--bone)', lineHeight: 1.2, marginBottom: 4 }}>
                    {listing.property.title}
                </div>
                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--parch-deep)', letterSpacing: '.06em' }}>
                    {listing.property.county} Co. · {listing.property.state_code}
                </div>
            </div>

            {/* Card body */}
            <div style={{ padding: '14px 18px', flex: 1, display: 'flex', flexDirection: 'column', gap: 12 }}>

                {/* Species tags */}
                {listing.property.species.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                        {listing.property.species.slice(0, 4).map(code => (
                            <span key={code} style={{
                                fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '.1em',
                                textTransform: 'uppercase', color: 'var(--sage-dim)',
                                background: 'transparent', border: '1px solid var(--parch-dim)',
                                padding: '2px 7px',
                            }}>
                                {SPECIES_NAMES[code] ?? code}
                            </span>
                        ))}
                        {listing.property.species.length > 4 && (
                            <span style={{ fontFamily: 'var(--mono)', fontSize: 9, color: 'var(--parch-deep)' }}>
                                +{listing.property.species.length - 4} more
                            </span>
                        )}
                    </div>
                )}

                {/* Price + CTA */}
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 'auto', paddingTop: 12, borderTop: '1px solid var(--parch-dim)' }}>
                    <div>
                        <div style={{ fontFamily: 'var(--display)', fontSize: 20, fontWeight: 600, color: 'var(--ink)', lineHeight: 1 }}>
                            {formatPrice(listing)}
                        </div>
                        {listing.max_hunters && listing.max_hunters > 1 && (
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 9, color: 'var(--parch-deep)', marginTop: 3, letterSpacing: '.08em' }}>
                                up to {listing.max_hunters} hunters
                            </div>
                        )}
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <Link
                            href={`/properties/${listing.property.slug}`}
                            style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--ink)', textDecoration: 'none', padding: '8px 12px', border: '1px solid var(--parch-dim)' }}
                        >
                            Details
                        </Link>
                        <Link
                            href={applyHref}
                            style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--bone)', textDecoration: 'none', padding: '8px 14px', background: 'var(--ink)' }}
                        >
                            Apply →
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    )
}

// ── Style constants ───────────────────────────────────────────────────────────

const selectStyle: React.CSSProperties = {
    width: '100%',
    padding: '8px 10px',
    fontFamily: 'var(--body)',
    fontSize: 14,
    color: 'var(--ink)',
    background: 'var(--bone)',
    border: '1px solid var(--parch-dim)',
    borderRadius: 0,
    appearance: 'none',
    cursor: 'pointer',
}

const inputStyle: React.CSSProperties = {
    padding: '8px 10px',
    fontFamily: 'var(--mono)',
    fontSize: 12,
    color: 'var(--ink)',
    background: 'var(--bone)',
    border: '1px solid var(--parch-dim)',
    borderRadius: 0,
    width: '100%',
    boxSizing: 'border-box',
}
