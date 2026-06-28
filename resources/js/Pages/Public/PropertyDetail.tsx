import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import PublicNav from '@/Components/Public/PublicNav';

interface PropertySpecies {
    species_code: string;
    availability: 'seasonal' | 'year_round';
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
    // When true, every public listing is paused: only `slug` is sent and the
    // page renders a minimal, noindex'd "not available" stub (no details).
    paused?: boolean;
    title: string;
    slug: string;
    boundary_map_url: string | null;
    boundary_map_coords: { lat: number; lng: number } | null;
    description: string | null;
    status: string;
    state_code: string;
    county: string;
    total_acres: string;
    huntable_acres: string | null;
    species: PropertySpecies[];
    wildlife_agency: string | null;
    rules: PropertyRule[];
    photos: PropertyPhoto[];
    listings: PropertyListing[];
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

// One labelled group of game-type chips (In-Season vs Year-Round). Renders
// nothing when the group is empty so a property with only one kind shows one
// heading rather than an empty section.
function SpeciesGroup({ label, species }: { label: string; species: PropertySpecies[] }) {
    if (species.length === 0) return null;
    return (
        <div style={{ marginBottom: 28 }}>
            <div style={{
                fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.2em',
                color: 'var(--blaze)', textTransform: 'uppercase', marginBottom: 16,
                display: 'flex', alignItems: 'center', gap: 12,
            }}>
                <span style={{ display: 'block', width: 20, height: 1, background: 'var(--blaze)' }} />
                {label}
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                {species.map(s => (
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
    );
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

// A listing keeps its public page in every non-draft state; only `active` is
// open for application. pending = a lease is being signed; leased = executed;
// unavailable = landowner-marked "not currently available" (still posted).
function availabilityLabel(status: string): string {
    if (status === 'leased') return 'Leased Out';
    if (status === 'pending') return 'Under Contract';
    if (status === 'unavailable') return 'Not Currently Available';
    return 'Available';
}

// The longer "why you can't apply" line shown on the pricing card footer.
function unavailableFooterText(status: string): string {
    if (status === 'pending') return 'Under Contract — Not Accepting Applications';
    if (status === 'unavailable') return 'Not Currently Available';
    return 'Leased Out — Not Currently Available';
}

// The italic explanatory note on the sticky sidebar status card.
function unavailableNote(status: string): string {
    if (status === 'pending') return 'This lease is under contract and not currently accepting applications.';
    if (status === 'unavailable') return 'This property isn’t currently available. Check back for future availability.';
    return 'This property is leased for the current season. Check back for future availability.';
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
    const [lightbox, setLightbox] = useState<number | null>(null);
    // publicListings is ordered active → pending → leased, so the first entry is
    // the one to feature: an open listing if any, otherwise the leased/pending one.
    const listing = property.listings?.[0] ?? null;
    const isOpen = listing?.status === 'active';
    const statusLabel = listing ? availabilityLabel(listing.status) : null;
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

    // Paused: the owner has hidden this property. Keep the URL at 200 (no 404)
    // but show nothing about it, and noindex so it drops from search results.
    if (property.paused) {
        return (
            <div className="ah-page">
                <Head>
                    <meta name="robots" content="noindex" />
                </Head>
                <PublicNav />
                <div className="topo-bg" style={{
                    minHeight: '80vh', backgroundColor: '#EDE5D0',
                    display: 'flex', flexDirection: 'column',
                    alignItems: 'center', justifyContent: 'center', textAlign: 'center',
                    gap: 20, padding: '120px 24px 80px',
                }}>
                    <div style={{
                        fontFamily: 'var(--mono)', fontSize: 12, fontWeight: 600,
                        letterSpacing: '0.2em', textTransform: 'uppercase', color: 'var(--blaze)',
                    }}>
                        Not Currently Available
                    </div>
                    <h1 style={{ fontSize: 26, margin: 0, color: 'var(--ink)' }}>
                        This listing isn’t currently available
                    </h1>
                    <p style={{ maxWidth: 460, color: 'var(--ink-soft)', lineHeight: 1.6 }}>
                        It may return soon. In the meantime, browse our other properties.
                    </p>
                    <Link href="/properties" className="btn-solid" style={{ justifyContent: 'center' }}>
                        Browse Properties
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className="ah-page">

            {/* ── NAV ─────────────────────────────────────────────────────── */}
            <PublicNav />

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
                                <div className="field-stamp" style={isOpen ? undefined : { color: 'var(--blaze)', borderColor: 'var(--blaze)' }}>
                                    {statusLabel}
                                </div>
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
                            {isOpen ? (
                                <Link href={applyHref} className="btn-solid" style={{ width: '100%', justifyContent: 'center', marginTop: 16, boxSizing: 'border-box' }}>
                                    Apply for This Lease →
                                </Link>
                            ) : (
                                <div style={{ width: '100%', textAlign: 'center', marginTop: 16, padding: '12px 16px', boxSizing: 'border-box', border: '1px solid var(--brass-dim)', background: 'var(--bone-dim, rgba(0,0,0,0.03))', fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--ink-soft)' }}>
                                    {unavailableFooterText(listing.status)}
                                </div>
                            )}
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
                            <div style={{ position: 'relative', border: '1px solid var(--ink)', background: 'var(--ink)' }}>
                                <img
                                    src={property.boundary_map_url}
                                    alt={`${property.title} boundary map`}
                                    loading="lazy"
                                    style={{ display: 'block', width: '100%', height: 'auto' }}
                                />
                                {property.boundary_map_coords && (
                                    <span style={{
                                        position: 'absolute', bottom: 10, left: 10,
                                        background: 'rgba(10,21,18,0.8)', color: 'var(--bone)',
                                        border: '1px solid var(--brass-dim)',
                                        fontFamily: 'var(--mono)', fontSize: 10,
                                        letterSpacing: '0.08em', padding: '4px 10px',
                                        borderRadius: 2, whiteSpace: 'nowrap',
                                    }}>
                                        {property.boundary_map_coords.lat.toFixed(6)}, {property.boundary_map_coords.lng.toFixed(6)}
                                    </span>
                                )}
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

                    {/* Species — split into in-season vs year-round game */}
                    {property.species?.length > 0 && (
                        <div style={{ marginBottom: 56 }}>
                            <SpeciesGroup
                                label="In-Season Game"
                                species={property.species.filter(s => s.availability !== 'year_round')}
                            />
                            <SpeciesGroup
                                label="Year-Round Game"
                                species={property.species.filter(s => s.availability === 'year_round')}
                            />
                            <p style={{
                                fontFamily: 'var(--body)', fontSize: 14, fontStyle: 'italic',
                                lineHeight: 1.6, color: 'var(--ink-soft)', marginTop: 20,
                                paddingTop: 16, borderTop: '1px dotted var(--parch-deep)',
                            }}>
                                Not all game is permitted year-round. Open seasons and bag limits are set by{' '}
                                {property.wildlife_agency ?? 'your state wildlife agency'} — verify current
                                regulations before hunting.
                            </p>
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
                                        <div className="field-card-label">{isOpen ? 'Apply for Lease' : 'Lease Status'}</div>
                                        <div className="field-card-id">AH-{listing.id.slice(0, 8).toUpperCase()}</div>
                                    </div>
                                    <div className="field-stamp" style={isOpen ? undefined : { color: 'var(--blaze)', borderColor: 'var(--blaze)' }}>
                                        {statusLabel}
                                    </div>
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
                                    {isOpen ? (
                                        <>
                                            <Link href={applyHref} className="btn-solid" style={{ justifyContent: 'center' }}>
                                                Apply for This Lease →
                                            </Link>
                                            {!auth?.authenticated && (
                                                <Link href="/login" className="btn-outline" style={{ textAlign: 'center', justifyContent: 'center' }}>
                                                    Sign In to Apply
                                                </Link>
                                            )}
                                        </>
                                    ) : (
                                        <>
                                            <div style={{ textAlign: 'center', padding: '12px 16px', border: '1px solid var(--parch-deep)', background: 'var(--parch)', fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--ink-soft)' }}>
                                                {statusLabel}
                                            </div>
                                            <p style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--sage-dim)', fontStyle: 'italic', textAlign: 'center', margin: 0 }}>
                                                {unavailableNote(listing.status)}
                                            </p>
                                            <Link href="/get-started" className="btn-outline" style={{ textAlign: 'center', justifyContent: 'center' }}>
                                                Browse Available Leases →
                                            </Link>
                                        </>
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
