import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

interface PropertySpecies {
    species_code: string;
}

interface PropertyRule {
    id: string;
    rule_text: string;
    sort_order: number;
}

interface PropertyPhoto {
    id: string;
    url: string;
    caption: string | null;
    tags: string[];
    is_primary: boolean;
    sort_order: number;
}

interface PropertyListing {
    id: string;
    listing_type: string;
    status: string;
    season_start: string | null;
    season_end: string | null;
    min_hunters: number | null;
    max_hunters: number | null;
    price_per_hunter: string | null;
    price_total: string | null;
    deposit_amount: string | null;
    deposit_percent: number | null;
}

interface Property {
    id: string;
    title: string;
    slug: string;
    boundary_map_url: string | null;
    description: string | null;
    status: string;
    state_code: string;
    county: string;
    total_acres: string;
    huntable_acres: string | null;
    species: PropertySpecies[];
    rules: PropertyRule[];
    photos: PropertyPhoto[];
    active_listings: PropertyListing[];
}

interface PropertyDetailProps {
    property: Property;
}

const SPECIES_NAMES: Record<string, string> = {
    whitetail_deer: 'Whitetail Deer', mule_deer: 'Mule Deer', elk: 'Elk',
    turkey: 'Wild Turkey', waterfowl: 'Waterfowl', dove: 'Dove',
    hog: 'Wild Hog', bear: 'Bear', antelope: 'Antelope',
    pheasant: 'Pheasant', quail: 'Quail',
    rabbit: 'Rabbit', squirrel: 'Squirrel', coyote: 'Coyote', other: 'Other',
};

function formatSpecies(code: string): string {
    return SPECIES_NAMES[code] ?? code.replace(/_/g, ' ');
}

function formatType(type: string): string {
    const map: Record<string, string> = {
        annual_lease: 'Annual', seasonal_lease: 'Season',
        day_hunt: 'Day Hunt', auction: 'Auction',
    };
    return map[type] ?? type;
}

function formatPrice(listing: PropertyListing): string {
    if (listing.price_total) return `$${parseInt(listing.price_total).toLocaleString()}`;
    if (listing.price_per_hunter) return `$${parseInt(listing.price_per_hunter).toLocaleString()}`;
    return 'Contact for pricing';
}

function formatPricePer(listing: PropertyListing): string {
    if (listing.listing_type === 'annual_lease') return 'per year';
    if (listing.listing_type === 'seasonal_lease') return 'per season';
    if (listing.listing_type === 'day_hunt') return 'per day';
    if (listing.price_per_hunter && !listing.price_total) return 'per hunter';
    return '';
}

function formatSeason(listing: PropertyListing): string {
    if (!listing.season_start || !listing.season_end) return '—';
    const start = new Date(listing.season_start);
    const end   = new Date(listing.season_end);
    return `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} – ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
}

function GalleryTile({ photo, onClick, moreCount = 0 }: { photo: PropertyPhoto; onClick: () => void; moreCount?: number }) {
    return (
        <div onClick={onClick} style={{ position: 'relative', height: '100%', overflow: 'hidden', cursor: 'pointer' }}>
            <img
                src={photo.url}
                alt={photo.caption ?? 'Property photo'}
                loading="lazy"
                style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }}
            />
            {moreCount > 0 && (
                <div style={{
                    position: 'absolute', inset: 0, background: 'rgba(10,21,18,0.6)',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    fontFamily: 'var(--mono)', fontSize: 13, fontWeight: 600,
                    letterSpacing: '0.15em', textTransform: 'uppercase', color: 'var(--bone)',
                }}>
                    + {moreCount} more
                </div>
            )}
        </div>
    );
}

export default function PropertyDetail({ property }: PropertyDetailProps) {
    const [scrolled, setScrolled] = useState(false);
    const [lightbox, setLightbox] = useState<number | null>(null);
    const listing = property.active_listings?.[0] ?? null;
    const { auth } = usePage<{ auth: { authenticated: boolean } }>().props;
    const applyHref = auth?.authenticated && listing ? `/apply/${listing.id}` : '/get-started';
    const photos = property.photos ?? [];

    useEffect(() => {
        if (lightbox === null) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setLightbox(null);
            if (e.key === 'ArrowRight') setLightbox(i => (i === null ? null : (i + 1) % photos.length));
            if (e.key === 'ArrowLeft') setLightbox(i => (i === null ? null : (i - 1 + photos.length) % photos.length));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [lightbox, photos.length]);

    return (
        <div className="ah-page">

            {/* ── NAV ─────────────────────────────────────────────────────── */}
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
                    </ul>
                    <div className="nav-actions">
                        <Link href="/login" className="nav-link-text">Sign In</Link>
                        <Link href="/get-started" className="nav-cta">Get Started →</Link>
                    </div>
                </div>
            </nav>

            {/* ── PROPERTY HERO ────────────────────────────────────────────── */}
            <div style={{
                paddingTop: 120,
                background: 'var(--ink)',
                position: 'relative',
                overflow: 'hidden',
            }}>
                <div className="topo-bg-dark" style={{ position: 'absolute', inset: 0, opacity: 1 }} />

                {/* Breadcrumb */}
                <div style={{
                    maxWidth: 1400, margin: '0 auto', padding: '0 40px 32px',
                    position: 'relative', zIndex: 1,
                    fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em',
                    color: 'var(--brass)', textTransform: 'uppercase',
                    display: 'flex', gap: 12, alignItems: 'center',
                }}>
                    <Link href="/" style={{ color: 'var(--parch-dim)', textDecoration: 'none' }}>Home</Link>
                    <span>›</span>
                    <Link href="/properties" style={{ color: 'var(--parch-dim)', textDecoration: 'none' }}>Properties</Link>
                    <span>›</span>
                    <span style={{ color: 'var(--brass)' }}>{property.county} County, {property.state_code}</span>
                </div>

                {/* Title block */}
                <div style={{
                    maxWidth: 1400, margin: '0 auto', padding: '0 40px 64px',
                    position: 'relative', zIndex: 1,
                    display: 'grid', gridTemplateColumns: '1fr 360px', gap: 80, alignItems: 'end',
                }}>
                    <div>
                        <div style={{
                            fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em',
                            color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 20,
                            display: 'flex', alignItems: 'center', gap: 16,
                        }}>
                            <span style={{ display: 'block', width: 32, height: 1, background: 'var(--brass)' }} />
                            {property.county} County · {property.state_code}
                        </div>
                        <h1 style={{
                            fontFamily: 'var(--display)', fontSize: 'clamp(40px, 5vw, 72px)',
                            fontWeight: 400, lineHeight: 1, letterSpacing: '-0.02em',
                            color: 'var(--bone)', marginBottom: 32,
                        }}>
                            {property.title}
                        </h1>
                        <div style={{ display: 'flex', gap: 40 }}>
                            <div style={{ borderLeft: '1px solid var(--brass-dim)', paddingLeft: 16 }}>
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 6 }}>Total Acres</div>
                                <div style={{ fontFamily: 'var(--display)', fontSize: 28, fontWeight: 500, color: 'var(--bone)' }}>
                                    {parseFloat(property.total_acres).toLocaleString()}
                                </div>
                            </div>
                            {property.huntable_acres && (
                                <div style={{ borderLeft: '1px solid var(--brass-dim)', paddingLeft: 16 }}>
                                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 6 }}>Huntable Acres</div>
                                    <div style={{ fontFamily: 'var(--display)', fontSize: 28, fontWeight: 500, color: 'var(--bone)' }}>
                                        {parseFloat(property.huntable_acres).toLocaleString()}
                                    </div>
                                </div>
                            )}
                            {listing && (
                                <div style={{ borderLeft: '1px solid var(--brass-dim)', paddingLeft: 16 }}>
                                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 6 }}>Lease Type</div>
                                    <div style={{ fontFamily: 'var(--display)', fontSize: 28, fontWeight: 500, color: 'var(--bone)' }}>
                                        {formatType(listing.listing_type)}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Pricing card */}
                    {listing && (
                        <div className="field-card" style={{ background: 'var(--bone)' }}>
                            <div className="field-card-header">
                                <div>
                                    <div className="field-card-label">Lease Listing</div>
                                    <div className="field-card-id">AH-{listing.id.slice(0, 8).toUpperCase()}</div>
                                </div>
                                <div className="field-stamp">Available</div>
                            </div>
                            <div className="field-rows">
                                <div className="field-row">
                                    <span className="field-row-label">Season</span>
                                    <span className="field-row-value">{formatSeason(listing)}</span>
                                </div>
                                {listing.max_hunters != null && (
                                    <div className="field-row">
                                        <span className="field-row-label">Hunters</span>
                                        <span className="field-row-value">
                                            {listing.min_hunters ?? 1}–{listing.max_hunters}
                                        </span>
                                    </div>
                                )}
                            </div>
                            <div className="field-footer">
                                <div className="field-price">
                                    {formatPrice(listing)}
                                    {formatPricePer(listing) && <small> {formatPricePer(listing)}</small>}
                                </div>
                            </div>
                            <Link href={applyHref} className="btn-solid" style={{ width: '100%', justifyContent: 'center', marginTop: 16, boxSizing: 'border-box' }}>
                                Apply for This Lease →
                            </Link>
                        </div>
                    )}
                </div>
            </div>

            {/* ── PHOTO GALLERY ────────────────────────────────────────────── */}
            <div style={{ background: 'var(--ink-soft)', borderTop: '1px solid var(--brass-dim)' }}>
                <div style={{ maxWidth: 1400, margin: '0 auto', padding: '0 40px' }}>
                    <div style={{
                        height: 480,
                        display: 'grid',
                        gridTemplateColumns: photos.length > 1 ? '2fr 1fr' : '1fr',
                        gap: 4,
                    }}>
                        {photos.length > 0 ? (
                            <>
                                <GalleryTile photo={photos[0]} onClick={() => setLightbox(0)} />
                                {photos.length > 1 && (
                                    <div style={{ display: 'grid', gridTemplateRows: photos.length > 2 ? '1fr 1fr' : '1fr', gap: 4 }}>
                                        <GalleryTile photo={photos[1]} onClick={() => setLightbox(1)} />
                                        {photos.length > 2 && (
                                            <GalleryTile
                                                photo={photos[2]}
                                                onClick={() => setLightbox(2)}
                                                moreCount={photos.length - 3}
                                            />
                                        )}
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="prop-img-placeholder" style={{ height: '100%' }} />
                        )}
                    </div>
                </div>
            </div>

            {/* ── PHOTO LIGHTBOX ───────────────────────────────────────────── */}
            {lightbox !== null && photos[lightbox] && (
                <div
                    onClick={() => setLightbox(null)}
                    style={{
                        position: 'fixed', inset: 0, zIndex: 300,
                        background: 'rgba(10,21,18,0.95)',
                        display: 'flex', flexDirection: 'column',
                        alignItems: 'center', justifyContent: 'center', gap: 20,
                        padding: 40,
                    }}
                >
                    <img
                        src={photos[lightbox].url}
                        alt={photos[lightbox].caption ?? 'Property photo'}
                        onClick={e => e.stopPropagation()}
                        style={{ maxWidth: '90vw', maxHeight: '74vh', objectFit: 'contain', border: '1px solid var(--brass-dim)' }}
                    />
                    <div onClick={e => e.stopPropagation()} style={{ textAlign: 'center', maxWidth: 720 }}>
                        <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 8 }}>
                            {String(lightbox + 1).padStart(2, '0')} / {String(photos.length).padStart(2, '0')}
                            {photos[lightbox].is_primary && ' · Primary'}
                        </div>
                        {photos[lightbox].caption && (
                            <div style={{ fontFamily: 'var(--body)', fontSize: 16, fontStyle: 'italic', color: 'var(--bone)', marginBottom: 10 }}>
                                {photos[lightbox].caption}
                            </div>
                        )}
                        {photos[lightbox].tags?.length > 0 && (
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, justifyContent: 'center' }}>
                                {photos[lightbox].tags.map(tag => (
                                    <span key={tag} style={{
                                        fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '0.12em',
                                        textTransform: 'uppercase', color: 'var(--parch-dim)',
                                        border: '1px solid var(--brass-dim)', padding: '3px 9px',
                                    }}>
                                        {tag}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                    {photos.length > 1 && (
                        <>
                            <button
                                onClick={e => { e.stopPropagation(); setLightbox((lightbox - 1 + photos.length) % photos.length); }}
                                aria-label="Previous photo"
                                style={{
                                    position: 'absolute', left: 24, top: '50%', transform: 'translateY(-50%)',
                                    background: 'transparent', border: '1px solid var(--brass-dim)',
                                    color: 'var(--bone)', fontSize: 22, padding: '12px 18px', cursor: 'pointer',
                                }}
                            >‹</button>
                            <button
                                onClick={e => { e.stopPropagation(); setLightbox((lightbox + 1) % photos.length); }}
                                aria-label="Next photo"
                                style={{
                                    position: 'absolute', right: 24, top: '50%', transform: 'translateY(-50%)',
                                    background: 'transparent', border: '1px solid var(--brass-dim)',
                                    color: 'var(--bone)', fontSize: 22, padding: '12px 18px', cursor: 'pointer',
                                }}
                            >›</button>
                        </>
                    )}
                    <button
                        onClick={() => setLightbox(null)}
                        aria-label="Close gallery"
                        style={{
                            position: 'absolute', top: 24, right: 24,
                            background: 'transparent', border: '1px solid var(--brass-dim)',
                            color: 'var(--bone)', fontFamily: 'var(--mono)', fontSize: 11,
                            letterSpacing: '0.15em', textTransform: 'uppercase',
                            padding: '10px 16px', cursor: 'pointer',
                        }}
                    >Close ✕</button>
                </div>
            )}

            {/* ── MAIN CONTENT ─────────────────────────────────────────────── */}
            <div style={{ maxWidth: 1400, margin: '0 auto', padding: '80px 40px', display: 'grid', gridTemplateColumns: '1fr 360px', gap: 80 }}>

                {/* Left column */}
                <div>
                    {/* Map placeholder */}
                    <div style={{ marginBottom: 56 }}>
                        <div style={{
                            fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                            color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                            display: 'flex', alignItems: 'center', gap: 12,
                        }}>
                            <span style={{ display: 'block', width: 20, height: 1, background: 'var(--blaze)' }} />
                            Property Boundary
                        </div>
                        {property.boundary_map_url ? (
                            <div style={{ border: '1px solid var(--ink)', background: 'var(--ink)' }}>
                                <img
                                    src={property.boundary_map_url}
                                    alt={`${property.title} boundary map`}
                                    loading="lazy"
                                    style={{ display: 'block', width: '100%', height: 'auto' }}
                                />
                            </div>
                        ) : (
                            <div style={{
                                height: 320, background: 'var(--ink)', border: '1px solid var(--ink)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                flexDirection: 'column', gap: 12,
                            }}>
                                <span style={{ fontFamily: 'var(--display)', fontSize: 48, fontWeight: 300, color: 'var(--brass)', opacity: 0.4, fontStyle: 'italic' }}>Σ</span>
                                <span style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--parch-dim)', textTransform: 'uppercase' }}>
                                    Boundary Map · Mapbox
                                </span>
                                <span style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--parch-dim)', fontStyle: 'italic' }}>
                                    Visible after signing in
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Description */}
                    {property.description && (
                        <div style={{ marginBottom: 56 }}>
                            <div style={{
                                fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                                color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                                display: 'flex', alignItems: 'center', gap: 12,
                            }}>
                                <span style={{ display: 'block', width: 20, height: 1, background: 'var(--blaze)' }} />
                                About This Property
                            </div>
                            <p style={{
                                fontFamily: 'var(--body)', fontSize: 18, fontWeight: 300,
                                lineHeight: 1.7, color: 'var(--ink-lift)',
                            }}>
                                {property.description}
                            </p>
                        </div>
                    )}

                    {/* Species */}
                    {property.species?.length > 0 && (
                        <div style={{ marginBottom: 56 }}>
                            <div style={{
                                fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                                color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                                display: 'flex', alignItems: 'center', gap: 12,
                            }}>
                                <span style={{ display: 'block', width: 20, height: 1, background: 'var(--blaze)' }} />
                                Species Present
                            </div>
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                                {property.species.map(s => (
                                    <span key={s.species_code} style={{
                                        fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em',
                                        textTransform: 'uppercase', color: 'var(--ink)',
                                        border: '1px solid var(--ink)', padding: '8px 16px',
                                    }}>
                                        {formatSpecies(s.species_code)}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Rules */}
                    {property.rules?.length > 0 && (
                        <div style={{ marginBottom: 56 }}>
                            <div style={{
                                fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                                color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 24,
                                display: 'flex', alignItems: 'center', gap: 12,
                            }}>
                                <span style={{ display: 'block', width: 20, height: 1, background: 'var(--blaze)' }} />
                                Property Rules
                            </div>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                                {property.rules.map(rule => (
                                    <div key={rule.id} style={{
                                        fontFamily: 'var(--body)', fontSize: 16, fontWeight: 300,
                                        lineHeight: 1.6, color: 'var(--ink-lift)',
                                        padding: '12px 0', borderBottom: '1px dotted var(--parch-deep)',
                                        display: 'flex', gap: 12, alignItems: 'baseline',
                                    }}>
                                        <span style={{ color: 'var(--blaze)', fontWeight: 600, flexShrink: 0 }}>✓</span>
                                        {rule.rule_text}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Right sidebar */}
                <div>
                    {/* Sticky apply card */}
                    <div style={{ position: 'sticky', top: 120 }}>
                        {listing ? (
                            <div className="field-card">
                                <div className="field-card-header">
                                    <div>
                                        <div className="field-card-label">Apply for Lease</div>
                                        <div className="field-card-id">AH-{listing.id.slice(0, 8).toUpperCase()}</div>
                                    </div>
                                    <div className="field-stamp">Open</div>
                                </div>
                                <div className="field-rows">
                                    <div className="field-row">
                                        <span className="field-row-label">Type</span>
                                        <span className="field-row-value">{formatType(listing.listing_type)}</span>
                                    </div>
                                    <div className="field-row">
                                        <span className="field-row-label">Season</span>
                                        <span className="field-row-value">{formatSeason(listing)}</span>
                                    </div>
                                    {listing.max_hunters != null && (
                                        <div className="field-row">
                                            <span className="field-row-label">Hunters</span>
                                            <span className="field-row-value">{listing.min_hunters ?? 1}–{listing.max_hunters}</span>
                                        </div>
                                    )}
                                    {listing.deposit_percent != null && (
                                        <div className="field-row">
                                            <span className="field-row-label">Deposit</span>
                                            <span className="field-row-value">{listing.deposit_percent}%</span>
                                        </div>
                                    )}
                                </div>
                                <div className="field-footer">
                                    <div className="field-price">
                                        {formatPrice(listing)}
                                        {formatPricePer(listing) && <small> {formatPricePer(listing)}</small>}
                                    </div>
                                </div>
                                <div style={{ marginTop: 20, display: 'flex', flexDirection: 'column', gap: 10 }}>
                                    <Link href={applyHref} className="btn-solid" style={{ justifyContent: 'center' }}>
                                        Apply for This Lease →
                                    </Link>
                                    {!auth?.authenticated && (
                                        <Link href="/login" className="btn-outline" style={{ textAlign: 'center', justifyContent: 'center' }}>
                                            Sign In to Apply
                                        </Link>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="field-card">
                                <div className="field-card-header">
                                    <div>
                                        <div className="field-card-label">Availability</div>
                                    </div>
                                    <div className="field-stamp" style={{ transform: 'rotate(-6deg)', color: 'var(--sage)', borderColor: 'var(--sage)' }}>
                                        Inquire
                                    </div>
                                </div>
                                <p style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--sage-dim)', fontStyle: 'italic', marginBottom: 20 }}>
                                    No active listings at this time. Contact the landowner for availability.
                                </p>
                                <Link href="/get-started" className="btn-solid" style={{ justifyContent: 'center' }}>
                                    Create Account to Inquire →
                                </Link>
                            </div>
                        )}

                        {/* Safety note */}
                        <div style={{
                            marginTop: 24, padding: '16px 20px',
                            background: 'var(--bone)', border: '1px solid var(--parch-deep)',
                        }}>
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--sage-dim)', textTransform: 'uppercase', marginBottom: 8 }}>
                                Lease Protection
                            </div>
                            <p style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink-lift)', lineHeight: 1.55, margin: 0 }}>
                                Every lease includes e-signature, Stripe escrow, identity verification,
                                and a signed PDF delivered to both parties.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* ── CTA STRIP ───────────────────────────────────────────────── */}
            <div style={{
                background: 'var(--blaze)', padding: '56px 40px',
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                maxWidth: '100%',
            }}>
                <div>
                    <div style={{ fontFamily: 'var(--display)', fontSize: 32, fontWeight: 500, color: 'var(--ink)', lineHeight: 1, marginBottom: 8 }}>
                        Ready to lease this property?
                    </div>
                    <div style={{ fontFamily: 'var(--body)', fontSize: 16, fontStyle: 'italic', color: 'var(--ink)', opacity: 0.75 }}>
                        Create a free account and apply in minutes.
                    </div>
                </div>
                <div style={{ display: 'flex', gap: 16, flexShrink: 0 }}>
                    <Link href="/get-started" style={{
                        fontFamily: 'var(--mono)', fontSize: 11, fontWeight: 600,
                        letterSpacing: '0.15em', textTransform: 'uppercase', textDecoration: 'none',
                        padding: '16px 32px', background: 'var(--ink)', color: 'var(--bone)',
                        border: '1px solid var(--ink)',
                    }}>
                        Get Started →
                    </Link>
                    <Link href="/properties" style={{
                        fontFamily: 'var(--mono)', fontSize: 11, fontWeight: 600,
                        letterSpacing: '0.15em', textTransform: 'uppercase', textDecoration: 'none',
                        padding: '16px 32px', background: 'transparent', color: 'var(--ink)',
                        border: '1px solid var(--ink)',
                    }}>
                        Browse More Land
                    </Link>
                </div>
            </div>

            {/* ── FOOTER ──────────────────────────────────────────────────── */}
            <footer className="ah-footer">
                <div className="footer-topo topo-bg-dark" />
                <div className="footer-bot" style={{ maxWidth: 1400, margin: '0 auto', paddingTop: 32, position: 'relative', zIndex: 1 }}>
                    <span className="footer-copy">
                        © {new Date().getFullYear()} American Headhunter, LLC · All Rights Reserved
                    </span>
                    <div className="footer-legal">
                        <a href="/privacy">Privacy</a>
                        <a href="/terms">Terms</a>
                        <a href="/">Home</a>
                    </div>
                </div>
            </footer>
        </div>
    );
}
