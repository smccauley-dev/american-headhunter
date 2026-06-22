import { useState, useRef } from 'react';
import { Link, router } from '@inertiajs/react';
import PublicNav from '@/Components/Public/PublicNav';

interface PropertySpecies {
    species_code: string;
}

interface Listing {
    id: string;
    listing_type: string;
    price_per_hunter: string | null;
    price_total: string | null;
    min_hunters: number | null;
    max_hunters: number | null;
    season_start: string | null;
    season_end: string | null;
    property: {
        id: string;
        title: string;
        slug: string;
        state_code: string;
        county: string;
        total_acres: string;
        description: string | null;
        center_lat: number | null;
        center_lng: number | null;
        primary_photo_url: string | null;
        species: PropertySpecies[];
    };
}

interface HeroSettings {
    card_count: number;
    eyebrow: string;
    line1: string;
    line2: string;
    line3: string;
    stat1_label: string;
    stat1_value: string;
    stat2_label: string;
    stat2_value: string;
    stat3_label: string;
    stat3_value: string;
}

interface StatItem { label: string; num: string; sub: string; }

interface HomeSettings {
    hero: HeroSettings;
    stats: StatItem[];
    cta: { headline: string; sub: string };
    sections: {
        almanac: boolean;
        stats: boolean;
        expedition: boolean;
        testimonials: boolean;
        cta: boolean;
    };
}

interface HomeProps {
    listings: Listing[];
    homeSettings: HomeSettings;
}

const SPECIES_CATALOG = [
    { code: 'whitetail_deer', name: 'Whitetail Deer', glyph: 'Ω', count: '4,280 properties' },
    { code: 'elk',            name: 'Rocky Mtn. Elk', glyph: 'Σ', count: '842 properties'   },
    { code: 'turkey',         name: 'Wild Turkey',    glyph: 'Ψ', count: '2,140 properties' },
    { code: 'hog',            name: 'Wild Hog',       glyph: 'Δ', count: '1,380 properties' },
    { code: 'waterfowl',      name: 'Waterfowl',      glyph: 'Λ', count: '920 properties'   },
    { code: 'dove',           name: 'Dove',           glyph: 'Φ', count: '1,660 properties' },
];

const TESTIMONIALS = [
    {
        text: 'Signed the lease in twenty minutes — had access info before I left the driveway. This is how it should\'ve always worked.',
        name: 'Marcus Tillman',
        role: 'Season Lessee · Kinney County, Texas',
    },
    {
        text: 'I listed three properties in a single afternoon. Had my first application by morning. The contract handled itself.',
        name: 'James Earl Pruitt',
        role: 'Landowner · 4,200 acres · Hill Country',
    },
    {
        text: 'The harvest log alone is worth it. My wife and I have five years of data on this property now — nothing else does that.',
        name: 'Raymond & Carol Stokes',
        role: 'Annual Members · Central Appalachia',
    },
];

const STATES = [
    'AL','AR','AZ','CA','CO','FL','GA','IA','ID','IL','IN','KS','KY','LA',
    'MI','MN','MO','MS','MT','NC','ND','NE','NM','NV','NY','OH','OK','OR',
    'PA','SC','SD','TN','TX','UT','VA','WA','WI','WV','WY',
];

function formatPrice(listing: Listing): string {
    if (listing.price_total) return `$${parseInt(listing.price_total).toLocaleString()}`;
    if (listing.price_per_hunter) return `$${parseInt(listing.price_per_hunter).toLocaleString()}`;
    return 'Contact';
}

function formatPricePer(listing: Listing): string {
    if (listing.listing_type === 'annual_lease') return 'per year';
    if (listing.listing_type === 'seasonal_lease') return 'per season';
    if (listing.listing_type === 'day_hunt') return 'per day';
    if (listing.price_per_hunter && !listing.price_total) return 'per hunter';
    return '';
}

function formatType(type: string): string {
    const map: Record<string, string> = {
        annual_lease: 'Annual', seasonal_lease: 'Season',
        day_hunt: 'Day Hunt', auction: 'Auction',
    };
    return map[type] ?? type;
}

function formatSpecies(code: string): string {
    const map: Record<string, string> = {
        whitetail_deer: 'Whitetail', mule_deer: 'Mule Deer', elk: 'Elk',
        turkey: 'Turkey', waterfowl: 'Waterfowl', dove: 'Dove',
        hog: 'Hog', bear: 'Bear', antelope: 'Antelope',
        pheasant: 'Pheasant', quail: 'Quail',
        rabbit: 'Rabbit', squirrel: 'Squirrel', coyote: 'Coyote', other: 'Other',
    };
    return map[code] ?? code.replace(/_/g, ' ');
}

function FieldCard({ listing, compact = false }: { listing: Listing; compact?: boolean }) {
    const coords = formatCoords(listing.property.center_lat, listing.property.center_lng);
    return (
        <div className={`field-card${compact ? ' field-card--compact' : ''}`}>
            <div className="field-card-header">
                <div>
                    <div className="field-card-label">Field Record</div>
                    <div className="field-card-id">AH-{listing.id.slice(0, 8).toUpperCase()}</div>
                </div>
                <div className="field-stamp">Verified</div>
            </div>
            <div className="field-title">{listing.property.title}</div>
            <div className="field-sub">{listing.property.county} County · {listing.property.state_code}</div>
            <div className="field-rows">
                {coords && (
                    <div className="field-row">
                        <span className="field-row-label">Coordinates</span>
                        <span className="field-row-value">{coords}</span>
                    </div>
                )}
                <div className="field-row">
                    <span className="field-row-label">Acreage</span>
                    <span className="field-row-value">
                        {parseFloat(listing.property.total_acres).toLocaleString()}{' '}
                        <span className="dim">acres</span>
                    </span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Primary Game</span>
                    <span className="field-row-value">
                        {listing.property.species?.slice(0, 3).map(s => formatSpecies(s.species_code)).join(', ') || '—'}
                    </span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Season</span>
                    <span className="field-row-value">
                        {formatSeason(listing.season_start, listing.season_end)}
                    </span>
                </div>
                {!compact && (
                    <>
                        <div className="field-row">
                            <span className="field-row-label">Lease Type</span>
                            <span className="field-row-value">{formatType(listing.listing_type)}</span>
                        </div>
                        <div className="field-row">
                            <span className="field-row-label">Max Hunters</span>
                            <span className="field-row-value">
                                {listing.max_hunters ?? '—'}{' '}
                                <span className="dim">on lease</span>
                            </span>
                        </div>
                    </>
                )}
            </div>
            <div className="field-footer">
                <div className="field-price">
                    {formatPrice(listing)}
                    {formatPricePer(listing) && <small> {formatPricePer(listing)}</small>}
                </div>
                <Link href={`/properties/${listing.property.slug}`} className="field-cta">
                    View Listing →
                </Link>
            </div>
        </div>
    );
}

function PlaceholderCard({ compact = false }: { compact?: boolean }) {
    return (
        <div className={`field-card${compact ? ' field-card--compact' : ''}`}>
            <div className="field-card-header">
                <div>
                    <div className="field-card-label">Field Record</div>
                    <div className="field-card-id">AH-2026-00184</div>
                </div>
                <div className="field-stamp">Verified</div>
            </div>
            <div className="field-title">Brackettville <em>Whitetail</em> Ranch</div>
            <div className="field-sub">Kinney County, Texas — Hill Country edge</div>
            <div className="field-rows">
                <div className="field-row">
                    <span className="field-row-label">Coordinates</span>
                    <span className="field-row-value">29.31° N <span className="dim">·</span> 100.42° W</span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Acreage</span>
                    <span className="field-row-value">2,840 <span className="dim">acres</span></span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Primary Game</span>
                    <span className="field-row-value">Whitetail, Axis, Hog</span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Season</span>
                    <span className="field-row-value">Oct 5 <span className="dim">–</span> Jan 22</span>
                </div>
                {!compact && (
                    <div className="field-row">
                        <span className="field-row-label">Max Hunters</span>
                        <span className="field-row-value">6 <span className="dim">on lease</span></span>
                    </div>
                )}
            </div>
            <div className="field-footer">
                <div className="field-price">$14,500 <small>/ season</small></div>
                <Link href="/get-started" className="field-cta">View listing →</Link>
            </div>
        </div>
    );
}

function PlaceholderCard2({ compact = false }: { compact?: boolean }) {
    return (
        <div className={`field-card${compact ? ' field-card--compact' : ''}`}>
            <div className="field-card-header">
                <div>
                    <div className="field-card-label">Field Record</div>
                    <div className="field-card-id">AH-2026-00217</div>
                </div>
                <div className="field-stamp">Verified</div>
            </div>
            <div className="field-title">Flint Hills <em>Elk</em> Ridge</div>
            <div className="field-sub">Chase County, Kansas — Tallgrass Prairie</div>
            <div className="field-rows">
                <div className="field-row">
                    <span className="field-row-label">Coordinates</span>
                    <span className="field-row-value">38.30° N <span className="dim">·</span> 96.60° W</span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Acreage</span>
                    <span className="field-row-value">1,200 <span className="dim">acres</span></span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Primary Game</span>
                    <span className="field-row-value">Elk, Whitetail, Turkey</span>
                </div>
                <div className="field-row">
                    <span className="field-row-label">Season</span>
                    <span className="field-row-value">Sep 1 <span className="dim">–</span> Feb 28</span>
                </div>
            </div>
            <div className="field-footer">
                <div className="field-price">$18,000 <small>/ year</small></div>
                <Link href="/get-started" className="field-cta">View listing →</Link>
            </div>
        </div>
    );
}

function CompassRose() {
    return (
        <svg className="compass" viewBox="0 0 140 140">
            <circle cx="70" cy="70" r="60" fill="none" stroke="#0a1512" strokeWidth="0.8" />
            <circle cx="70" cy="70" r="50" fill="none" stroke="#0a1512" strokeWidth="0.5" strokeDasharray="2,4" />
            <circle cx="70" cy="70" r="4" fill="#c84c21" />
            <polygon points="70,10 75,65 70,68 65,65" fill="#0a1512" />
            <polygon points="70,130 75,75 70,72 65,75" fill="#0a1512" opacity="0.4" />
            <polygon points="10,70 65,65 68,70 65,75" fill="#0a1512" opacity="0.6" />
            <polygon points="130,70 75,65 72,70 75,75" fill="#0a1512" opacity="0.6" />
            <text x="70" y="8"   textAnchor="middle" fontFamily="Fraunces" fontSize="11" fontWeight="600" fill="#0a1512">N</text>
            <text x="70" y="140" textAnchor="middle" fontFamily="Fraunces" fontSize="10" fill="#0a1512">S</text>
            <text x="5"  y="74"  textAnchor="middle" fontFamily="Fraunces" fontSize="10" fill="#0a1512">W</text>
            <text x="135" y="74" textAnchor="middle" fontFamily="Fraunces" fontSize="10" fill="#0a1512">E</text>
        </svg>
    );
}

function formatCoords(lat: number | null, lng: number | null): string | null {
    if (lat === null || lng === null) return null;
    const latStr = `${Math.abs(lat).toFixed(2)}° ${lat >= 0 ? 'N' : 'S'}`;
    const lngStr = `${Math.abs(lng).toFixed(2)}° ${lng >= 0 ? 'E' : 'W'}`;
    return `${latStr} · ${lngStr}`;
}

function formatSeason(start: string | null, end: string | null): string {
    if (!start && !end) return '—';
    const fmt = (d: string) => {
        const [, m, day] = d.split('-');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return `${months[parseInt(m) - 1]} ${parseInt(day)}`;
    };
    if (start && end) return `${fmt(start)} – ${fmt(end)}`;
    return start ? fmt(start) : fmt(end!);
}

export default function Home({ listings, homeSettings }: HomeProps) {
    const [testimonialIdx, setIdx]        = useState(0);
    const [state, setState]               = useState('');
    const [species, setSpecies]           = useState('');
    const [leaseType, setLeaseType]       = useState('');

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        const params = new URLSearchParams();
        if (state)     params.set('state_code',   state);
        if (species)   params.set('species',       species);
        if (leaseType) params.set('listing_type',  leaseType);
        router.get('/properties?' + params.toString());
    }

    const isDual    = homeSettings.hero.card_count >= 2;
    const featured  = listings[0] ?? null;
    const featured2 = isDual ? (listings[1] ?? null) : null;
    const t = TESTIMONIALS[testimonialIdx];

    return (
        <div className="ah-page">

            {/* ── NAV ─────────────────────────────────────────────────────── */}
            <PublicNav />

            {/* ── HERO ────────────────────────────────────────────────────── */}
            <section className="ah-hero">
                <div className="hero-topo topo-bg" />
                <div className="reg-mark reg-tl" /><div className="reg-mark reg-tr" />
                <div className="reg-mark reg-bl" /><div className="reg-mark reg-br" />
                <div className={`hero-grid${isDual ? ' dual-cards' : ''}`}>
                    <div className="hero-left">
                        <div className="hero-eyebrow">
                            <div className="eyebrow-line" />
                            <span className="eyebrow-text">{homeSettings.hero.eyebrow}</span>
                        </div>
                        <h1 className="hero-headline">
                            <span className="line">{homeSettings.hero.line1}</span>
                            <span className="line line-2">{homeSettings.hero.line2}</span>
                            <span className="line">{homeSettings.hero.line3}</span>
                        </h1>
                        <div className="hero-meta">
                            <div className="hero-meta-item">
                                <div className="hero-meta-label">{homeSettings.hero.stat1_label}</div>
                                <div className="hero-meta-value"><em>{homeSettings.hero.stat1_value}</em></div>
                            </div>
                            <div className="hero-meta-item">
                                <div className="hero-meta-label">{homeSettings.hero.stat2_label}</div>
                                <div className="hero-meta-value"><em>{homeSettings.hero.stat2_value}</em></div>
                            </div>
                            <div className="hero-meta-item">
                                <div className="hero-meta-label">{homeSettings.hero.stat3_label}</div>
                                <div className="hero-meta-value"><em>{homeSettings.hero.stat3_value}</em></div>
                            </div>
                        </div>
                        <div className="hero-actions">
                            <Link href="/properties" className="btn-solid">Explore Land →</Link>
                            <Link href="/get-started?type=landowner" className="btn-outline">List Your Property</Link>
                        </div>
                    </div>
                    <div className={`hero-right${isDual ? ' dual' : ''}`}>
                        {featured
                            ? <FieldCard listing={featured} compact={isDual} />
                            : <PlaceholderCard compact={isDual} />
                        }
                        {isDual && (
                            featured2
                                ? <FieldCard listing={featured2} compact={true} />
                                : <PlaceholderCard2 compact={true} />
                        )}
                    </div>
                </div>
                <CompassRose />
            </section>

            {/* ── SEARCH BAR ──────────────────────────────────────────────── */}
            <div className="search-section topo-bg-dark">
                <div className="search-label-strip">
                    <span>§ Property Search</span>
                    <span>{listings.length > 0 ? `${listings.length}+ active listings` : 'Founding listings opening soon'}</span>
                </div>
                <form onSubmit={handleSearch}>
                    <div className="search-bar">
                        <div className="search-field">
                            <label>State</label>
                            <select value={state} onChange={e => setState(e.target.value)}>
                                <option value="">Any State</option>
                                {STATES.map(s => <option key={s} value={s}>{s}</option>)}
                            </select>
                        </div>
                        <div className="search-field">
                            <label>Species</label>
                            <select value={species} onChange={e => setSpecies(e.target.value)}>
                                <option value="">All Species</option>
                                <option value="whitetail_deer">Whitetail Deer</option>
                                <option value="elk">Elk</option>
                                <option value="turkey">Wild Turkey</option>
                                <option value="hog">Wild Hog</option>
                                <option value="waterfowl">Waterfowl</option>
                                <option value="dove">Dove</option>
                                <option value="bear">Bear</option>
                                <option value="mule_deer">Mule Deer</option>
                                <option value="antelope">Antelope</option>
                                <option value="quail">Quail</option>
                                <option value="pheasant">Pheasant</option>
                            </select>
                        </div>
                        <div className="search-field">
                            <label>Lease Type</label>
                            <select value={leaseType} onChange={e => setLeaseType(e.target.value)}>
                                <option value="">Any Type</option>
                                <option value="seasonal_lease">Season</option>
                                <option value="annual_lease">Annual</option>
                                <option value="day_hunt">Day Hunt</option>
                                <option value="auction">Auction</option>
                            </select>
                        </div>
                        <div className="search-field">
                            <label>Min. Acres</label>
                            <input type="text" placeholder="No minimum" readOnly style={{ cursor: 'default' }} />
                        </div>
                        <button type="submit" className="search-submit">
                            Search Land →
                        </button>
                    </div>
                </form>
            </div>

            {/* ── CHAPTER I — THE ATLAS ────────────────────────────────────── */}
            <section className="ah-chapter properties-chapter topo-bg">
                <div className="chapter-header">
                    <div>
                        <span className="section-num">§ 01</span>
                        <h2 className="chapter-heading">The <em>Atlas</em></h2>
                    </div>
                    <p className="chapter-lede">
                        Thousands of acres across 48 states, from the Hill Country of Texas to the
                        ridge-and-valley hardwoods of Pennsylvania. Every listing verified.
                        Every lease protected.
                    </p>
                </div>
                <div className="properties-grid">
                    {listings.length > 0 ? listings.slice(0, 6).map((listing, i) => (
                        <Link key={listing.id} href={`/properties/${listing.property.slug}`} className="prop-card">
                            <div className="prop-img">
                                {listing.property.primary_photo_url ? (
                                    <img
                                        src={listing.property.primary_photo_url}
                                        alt={listing.property.title}
                                        className="prop-img-photo"
                                        loading="lazy"
                                    />
                                ) : (
                                    <div className="prop-img-placeholder" />
                                )}
                                <span className={`prop-tag${i === 0 ? ' blaze' : ''}`}>
                                    {i === 0 ? 'Featured' : formatType(listing.listing_type)}
                                </span>
                            </div>
                            <div className="prop-body">
                                <div className="prop-location">
                                    {listing.property.county} County · {listing.property.state_code}
                                </div>
                                <div className="prop-name">{listing.property.title}</div>
                                <div className="prop-specs">
                                    <div className="prop-spec">
                                        <strong>{parseFloat(listing.property.total_acres).toLocaleString()}</strong>
                                        <span>Total Acres</span>
                                    </div>
                                    {listing.max_hunters != null && (
                                        <div className="prop-spec">
                                            <strong>{listing.max_hunters}</strong>
                                            <span>Max Hunters</span>
                                        </div>
                                    )}
                                    <div className="prop-spec">
                                        <strong>{formatType(listing.listing_type)}</strong>
                                        <span>Lease Type</span>
                                    </div>
                                </div>
                                {listing.property.species?.length > 0 && (
                                    <div className="prop-species">
                                        {listing.property.species.slice(0, 4).map(s => (
                                            <span key={s.species_code} className="species-pill">
                                                {formatSpecies(s.species_code)}
                                            </span>
                                        ))}
                                    </div>
                                )}
                                <div className="prop-footer">
                                    <div className="prop-price">
                                        {formatPrice(listing)}
                                        {formatPricePer(listing) && <small> {formatPricePer(listing)}</small>}
                                    </div>
                                    <span className="prop-view">View →</span>
                                </div>
                            </div>
                        </Link>
                    )) : (
                        [...Array(3)].map((_, i) => (
                            <div key={i} className="prop-card" style={{ cursor: 'default', opacity: 0.6 }}>
                                <div className="prop-img">
                                    <div className="prop-img-placeholder" />
                                    <span className={`prop-tag${i === 0 ? ' blaze' : ''}`}>
                                        {i === 0 ? 'Coming Soon' : 'Season'}
                                    </span>
                                </div>
                                <div className="prop-body">
                                    <div className="prop-location">— · —</div>
                                    <div className="prop-name">Listings opening soon</div>
                                    <div style={{ flex: 1 }} />
                                    <div className="prop-footer">
                                        <div className="prop-price" style={{ fontSize: 14 }}>Be the first to list</div>
                                        <Link href="/get-started?type=landowner" className="prop-view">List →</Link>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
                <div className="chapter-footer">
                    <span className="chapter-footer-note">
                        {listings.length > 0
                            ? `Showing ${listings.length} featured — new listings added daily.`
                            : 'Founding landowners listing now.'}
                    </span>
                    <Link href="/properties" className="chapter-footer-link">Browse All Land →</Link>
                </div>
            </section>

            {/* ── CHAPTER II — THE ALMANAC ─────────────────────────────────── */}
            {homeSettings.sections.almanac && <section className="ah-chapter species-chapter">
                <div className="species-topo topo-bg-dark" />
                <div className="chapter-header">
                    <div>
                        <span className="section-num">§ 02</span>
                        <h2 className="chapter-heading">The <em>Almanac</em></h2>
                    </div>
                    <p className="chapter-lede">
                        Filter by your quarry. Every property tagged by species, habitat, and season —
                        so you hunt the right ground from day one.
                    </p>
                </div>
                <div className="species-grid">
                    {SPECIES_CATALOG.map((sp, i) => (
                        <div
                            key={sp.code}
                            className="species-card"
                            onClick={() => router.get(`/properties?species=${sp.code}`)}
                            style={{ cursor: 'pointer' }}
                        >
                            <span className="species-num">0{i + 1}</span>
                            <span className="species-glyph">{sp.glyph}</span>
                            <div className="species-foot">
                                <div className="species-name">{sp.name}</div>
                                <div className="species-count">{sp.count}</div>
                            </div>
                        </div>
                    ))}
                </div>
            </section>}

            {/* ── STATS ───────────────────────────────────────────────────── */}
            {homeSettings.sections.stats && <div className="stats-chapter">
                <div className="stats-grid">
                    {homeSettings.stats.map((stat, i) => (
                        <div key={i} className="stat">
                            <div className="stat-label">{stat.label}</div>
                            <div className="stat-num">{stat.num}</div>
                            <div className="stat-sub">{stat.sub}</div>
                        </div>
                    ))}
                </div>
            </div>}

            {/* ── CHAPTER III — THE EXPEDITION ─────────────────────────────── */}
            {homeSettings.sections.expedition && <section className="ah-chapter how-chapter">
                <div className="chapter-header">
                    <div>
                        <span className="section-num">§ 03</span>
                        <h2 className="chapter-heading">The <em>Expedition</em></h2>
                    </div>
                    <p className="chapter-lede">
                        From first search to field entry — the entire leasing process lives inside one
                        platform. No paperwork, no phone tag, no surprises.
                    </p>
                </div>
                <div className="how-grid">
                    <div className="how-step">
                        <div className="how-marker">I</div>
                        <h3 className="how-title">Find Your <em>Ground</em></h3>
                        <p className="how-body">
                            Search by state, county, species, acreage, or lease type. Every listing shows
                            verified boundaries on a topo map, real photos, and a species manifest logged
                            by the landowner.
                        </p>
                        <div className="how-meta">
                            <div className="how-meta-item">Filter by 12+ species</div>
                            <div className="how-meta-item">Verified boundary maps</div>
                            <div className="how-meta-item">Auction &amp; direct-lease options</div>
                        </div>
                    </div>
                    <div className="how-step">
                        <div className="how-marker">II</div>
                        <h3 className="how-title">Sign the <em>Contract</em></h3>
                        <p className="how-body">
                            Apply, negotiate terms, and e-sign a legally binding lease — all without
                            printing a single page. Both parties receive a signed PDF automatically.
                            Identity verification included.
                        </p>
                        <div className="how-meta">
                            <div className="how-meta-item">Dropbox Sign integration</div>
                            <div className="how-meta-item">Identity &amp; background check</div>
                            <div className="how-meta-item">Secure Stripe payment</div>
                        </div>
                    </div>
                    <div className="how-step">
                        <div className="how-marker">III</div>
                        <h3 className="how-title">Work the <em>Field</em></h3>
                        <p className="how-body">
                            Check in from the gate, log every harvest, drop stand pins on your map, and
                            record trail camera sightings. Everything your club needs to manage a property
                            like professionals.
                        </p>
                        <div className="how-meta">
                            <div className="how-meta-item">GPS check-in &amp; check-out</div>
                            <div className="how-meta-item">Harvest log &amp; trophy photos</div>
                            <div className="how-meta-item">Stand registry &amp; map pins</div>
                        </div>
                    </div>
                </div>
            </section>}

            {/* ── TESTIMONIALS ─────────────────────────────────────────────── */}
            {homeSettings.sections.testimonials && <section className="testimonial-chapter">
                <div className="testimonial-topo topo-bg-dark" />
                <div className="testimonial-wrap">
                    <span className="testimonial-label">Field Reports</span>
                    <p className="testimonial-text">"{t.text}"</p>
                    <div className="testimonial-attr">
                        <div className="testimonial-line" />
                        <div className="testimonial-who">
                            <div className="testimonial-name">{t.name}</div>
                            <div className="testimonial-role">{t.role}</div>
                        </div>
                        <div className="testimonial-line" />
                    </div>
                    <div className="testimonial-nav">
                        {TESTIMONIALS.map((_, i) => (
                            <button
                                key={i}
                                className={`test-dot${i === testimonialIdx ? ' active' : ''}`}
                                onClick={() => setIdx(i)}
                            >
                                0{i + 1}
                            </button>
                        ))}
                    </div>
                </div>
            </section>}

            {/* ── CTA ─────────────────────────────────────────────────────── */}
            {homeSettings.sections.cta && <section className="cta-chapter topo-bg">
                <div className="reg-mark reg-tl" /><div className="reg-mark reg-tr" />
                <div className="reg-mark reg-bl" /><div className="reg-mark reg-br" />
                <div className="cta-wrap">
                    <h2 className="cta-heading">{homeSettings.cta.headline}</h2>
                    <p className="cta-sub">{homeSettings.cta.sub}</p>
                    <div className="cta-buttons">
                        <Link href="/properties" className="btn-solid">Browse Properties →</Link>
                        <Link href="/get-started?type=landowner" className="btn-outline">List Your Land</Link>
                    </div>
                </div>
                <div className="cta-coords">30.88° N · 100.47° W · Est. 2025</div>
            </section>}

            {/* ── FOOTER ──────────────────────────────────────────────────── */}
            <footer className="ah-footer">
                <div className="footer-topo topo-bg-dark" />
                <div className="footer-top">
                    <div>
                        <div className="footer-brand-name">
                            American <span className="footer-brand-dot">Headhunter.</span>
                        </div>
                        <div className="footer-brand-tag">Est. 2025 · Hunting Lease Marketplace</div>
                        <p className="footer-desc">
                            The complete platform for hunting lease discovery, contracting, payments,
                            and field operations. Built for landowners and hunters.
                        </p>
                        <div className="footer-coord">30.88° N · 100.47° W</div>
                    </div>
                    <div>
                        <div className="footer-col-title">Marketplace</div>
                        <ul className="footer-links">
                            <li><a href="/properties">Find Land</a></li>
                            <li><a href="/auctions">Auctions</a></li>
                            <li><a href="/outfitters">Outfitters</a></li>
                            <li><a href="/get-started?type=landowner">List Your Land</a></li>
                        </ul>
                    </div>
                    <div>
                        <div className="footer-col-title">Company</div>
                        <ul className="footer-links">
                            <li><a href="/about">About</a></li>
                            <li><a href="/how-it-works">How It Works</a></li>
                            <li><a href="/pricing">Membership</a></li>
                            <li><a href="/contact">Contact</a></li>
                        </ul>
                    </div>
                    <div>
                        <div className="footer-col-title">Resources</div>
                        <ul className="footer-links">
                            <li><a href="/blog">Field Journal</a></li>
                            <li><a href="/lease-guide">Lease Guide</a></li>
                            <li><a href="/state-regulations">Regulations</a></li>
                            <li><a href="/safety">Safety</a></li>
                        </ul>
                    </div>
                    <div>
                        <div className="footer-col-title">Account</div>
                        <ul className="footer-links">
                            <li><a href="/login">Sign In</a></li>
                            <li><a href="/get-started">Create Account</a></li>
                            <li><a href="/member">Member Portal</a></li>
                            <li><a href="/admin">Admin</a></li>
                        </ul>
                    </div>
                </div>
                <div className="footer-bot">
                    <span className="footer-copy">
                        © {new Date().getFullYear()} American Headhunter, LLC · All Rights Reserved
                    </span>
                    <div className="footer-legal">
                        <a href="/privacy">Privacy Policy</a>
                        <a href="/terms">Terms of Service</a>
                        <a href="/cookies">Cookie Policy</a>
                    </div>
                </div>
            </footer>

        </div>
    );
}
