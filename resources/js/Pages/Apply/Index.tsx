import { useState, useRef, useEffect } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { US_STATES } from '@/lib/usStates';
import { formatPhoneInput } from '@/lib/phone';
import FilePondUploader from '@/Components/FilePondUploader';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Listing {
    id: string;
    listing_type: string;
    season_start: string | null;
    season_end: string | null;
    min_hunters: number | null;
    max_hunters: number | null;
    price_per_hunter: string | null;
    price_total: string | null;
    deposit_percent: number | null;
    deposit_amount: string | null;
}

interface Property {
    id: string;
    title: string;
    slug: string;
    state_code: string;
    county: string;
    total_acres: string;
}

interface HunterData {
    hunter_type: 'primary' | 'guest';
    user_id: string | null;
    guest_hunter_id: string | null;
    save_as_guest: boolean;
    first_name: string;
    last_name: string;
    date_of_birth: string;
    email: string;
    home_phone: string;
    cell_phone: string;
    address_line1: string;
    address_line2: string;
    city: string;
    state_code: string;
    zip_code: string;
    emergency_contact_name: string;
    emergency_contact_phone: string;
    emergency_contact_relationship: string;
    medical_conditions: string;
    dl_number: string;
    dl_state: string;
    dl_expiry: string;
    dl_photo: File | null;
    dl_photo_back: File | null;
    dl_document_id: string | null;
    dl_document_id_back: string | null;
    dl_confirmed_current: boolean;
    hunting_license_number: string;
    hunting_license_state: string;
    hunting_license_expiry: string;
    hunting_license_photo: File | null;
    hunting_license_photo_back: File | null;
    hunting_license_document_id: string | null;
    hunting_license_document_id_back: string | null;
    hunting_license_confirmed_current: boolean;
}

interface SavedGuest {
    id: string;
    first_name: string;
    last_name: string;
    date_of_birth?: string;
    email?: string;
    home_phone?: string;
    cell_phone?: string;
    address_line1?: string;
    address_line2?: string;
    city?: string;
    state_code?: string;
    zip_code?: string;
    emergency_contact_name?: string;
    emergency_contact_phone?: string;
    emergency_contact_relationship?: string;
    medical_conditions?: string;
    dl_number?: string;
    dl_state?: string;
    dl_expiry?: string;
    hunting_license_number?: string;
    hunting_license_state?: string;
    hunting_license_expiry?: string;
}

interface CertificationDoc {
    key: string;
    version: number;
    title: string;
    content: string;
}

interface UnavailableRange {
    start: string;
    end: string;
    reason: string;
}

interface ApplyIndexProps {
    listing: Listing;
    property: Property;
    unavailableRanges: UnavailableRange[];
    primaryHunter: HunterData;
    savedGuests: SavedGuest[];
    certificationDoc: CertificationDoc | null;
    canApply: boolean;
    restrictedState: string | null;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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
    return 'Contact for pricing';
}

function formatDate(d: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function isMinorDob(dob: string): boolean {
    if (!dob) return false;
    const age = (Date.now() - new Date(dob).getTime()) / (365.25 * 24 * 3600 * 1000);
    return age < 18;
}

// ── Date helpers (calendar / availability) ──────────────────────────────────────
// All dates are handled as YYYY-MM-DD strings to dodge timezone drift.

function isoDate(year: number, month: number, day: number): string {
    return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** True if the inclusive [start, end] range touches any unavailable range. */
function rangeOverlapsUnavailable(start: string, end: string, ranges: UnavailableRange[]): boolean {
    if (!start || !end) return false;
    return ranges.some(r => start <= r.end && end >= r.start);
}

/** True if a single day falls inside any unavailable range. */
function dayIsUnavailable(day: string, ranges: UnavailableRange[]): boolean {
    return ranges.some(r => day >= r.start && day <= r.end);
}

function blankGuest(): HunterData {
    return {
        hunter_type: 'guest', user_id: null, guest_hunter_id: null, save_as_guest: true,
        first_name: '', last_name: '', date_of_birth: '', email: '',
        home_phone: '', cell_phone: '', address_line1: '', address_line2: '',
        city: '', state_code: '', zip_code: '',
        emergency_contact_name: '', emergency_contact_phone: '', emergency_contact_relationship: '',
        medical_conditions: '', dl_number: '', dl_state: '', dl_expiry: '',
        dl_photo: null, dl_photo_back: null, dl_document_id: null, dl_document_id_back: null, dl_confirmed_current: false,
        hunting_license_number: '', hunting_license_state: '', hunting_license_expiry: '',
        hunting_license_photo: null, hunting_license_photo_back: null,
        hunting_license_document_id: null, hunting_license_document_id_back: null,
        hunting_license_confirmed_current: false,
    };
}

function guestToHunter(g: SavedGuest): HunterData {
    return {
        hunter_type: 'guest', user_id: null, guest_hunter_id: g.id, save_as_guest: false,
        first_name: g.first_name, last_name: g.last_name,
        date_of_birth: g.date_of_birth ?? '', email: g.email ?? '',
        home_phone: g.home_phone ?? '', cell_phone: g.cell_phone ?? '',
        address_line1: g.address_line1 ?? '', address_line2: g.address_line2 ?? '',
        city: g.city ?? '', state_code: g.state_code ?? '', zip_code: g.zip_code ?? '',
        emergency_contact_name: g.emergency_contact_name ?? '',
        emergency_contact_phone: g.emergency_contact_phone ?? '',
        emergency_contact_relationship: g.emergency_contact_relationship ?? '',
        medical_conditions: g.medical_conditions ?? '',
        dl_number: g.dl_number ?? '', dl_state: g.dl_state ?? '', dl_expiry: g.dl_expiry ?? '',
        dl_photo: null, dl_photo_back: null, dl_document_id: null, dl_document_id_back: null, dl_confirmed_current: false,
        hunting_license_number: g.hunting_license_number ?? '',
        hunting_license_state: g.hunting_license_state ?? '',
        hunting_license_expiry: g.hunting_license_expiry ?? '',
        hunting_license_photo: null, hunting_license_photo_back: null,
        hunting_license_document_id: null, hunting_license_document_id_back: null,
        hunting_license_confirmed_current: false,
    };
}

// ── Shared field styles ───────────────────────────────────────────────────────

const inputStyle = (hasError = false): React.CSSProperties => ({
    width: '100%', padding: '10px 14px', boxSizing: 'border-box',
    fontFamily: 'var(--mono)', fontSize: 13, color: 'var(--ink)',
    border: `1px solid ${hasError ? 'var(--blaze)' : '#d0ccc4'}`,
    background: 'var(--bone)', outline: 'none', borderRadius: 0,
});

const labelStyle: React.CSSProperties = {
    fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.12em',
    textTransform: 'uppercase', color: '#6b6558', display: 'block', marginBottom: 6,
};

const sectionLabel = (color = 'var(--blaze)'): React.CSSProperties => ({
    fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em',
    color, textTransform: 'uppercase', marginBottom: 20,
    display: 'flex', alignItems: 'center', gap: 10,
});

// ── FileUploadInput component ────────────────────────────────────────────────

function FileUploadInput({ label, onChange }: {
    label: string;
    file: File | null;
    onChange: (file: File | null) => void;
}) {
    // FilePond in local mode (no server): it just holds the chosen file and shows
    // the same dashed parchment dropzone as the admin uploader; the actual File is
    // lifted into the surrounding application form and submitted with everything
    // else. JPG / PNG / PDF up to 5 MB.
    return (
        <div>
            <label style={labelStyle}>{label}</label>
            <FilePondUploader
                name="file"
                maxFileSize="5MB"
                acceptedFileTypes={['image/jpeg', 'image/png', 'application/pdf']}
                labelIdle='Drag &amp; Drop your file or <span class="filepond--label-action">Browse</span>'
                onupdatefiles={items => onChange(items[0]?.file ?? null)}
            />
        </div>
    );
}

// ── HunterCard component ──────────────────────────────────────────────────────

interface HunterCardProps {
    index: number;
    hunter: HunterData;
    isPrimary: boolean;
    isExpanded: boolean;
    onToggle: () => void;
    onUpdate: (field: keyof HunterData, value: unknown) => void;
    onRemove?: () => void;
    errors: Record<string, string>;
    propertyState: string;
}

const errStyle: React.CSSProperties = {
    color: 'var(--blaze)', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 4,
};

function HunterCard({ index, hunter, isPrimary, isExpanded, onToggle, onUpdate, onRemove, errors, propertyState }: HunterCardProps) {
    const minor = isMinorDob(hunter.date_of_birth);
    const prefix = `hunters.${index}`;
    const e = (field: string): string => errors[`${prefix}.${field}`] ?? '';

    // The hunting license must be issued by the property's state, so the field is
    // locked to it — keep the underlying form value in sync (prefilled credentials
    // or saved guests may carry a different state).
    useEffect(() => {
        if (propertyState && hunter.hunting_license_state !== propertyState) {
            onUpdate('hunting_license_state', propertyState);
        }
    }, [propertyState, hunter.hunting_license_state]);

    const errorCount = Object.keys(errors).filter(k => k.startsWith(`${prefix}.`)).length;

    const name = (hunter.first_name || hunter.last_name)
        ? `${hunter.first_name} ${hunter.last_name}`.trim()
        : isPrimary ? 'Primary Hunter (You)' : `Hunter #${index + 1}`;

    return (
        <div style={{ border: `1px solid ${errorCount > 0 ? 'var(--blaze)' : '#d0ccc4'}`, marginBottom: 16, background: 'white' }}>
            {/* Header */}
            <div
                onClick={onToggle}
                style={{
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                    padding: '14px 20px', cursor: 'pointer',
                    background: isPrimary ? '#f7f4ef' : 'white',
                    borderBottom: isExpanded ? '1px solid #d0ccc4' : 'none',
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <span style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em', textTransform: 'uppercase', color: '#6b6558' }}>
                        {isPrimary ? '01' : String(index + 1).padStart(2, '0')}
                    </span>
                    <span style={{ fontFamily: 'var(--display)', fontSize: 16, color: 'var(--ink)' }}>
                        {name}
                    </span>
                    {isPrimary && (
                        <span style={{ fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '0.15em', textTransform: 'uppercase', color: 'var(--brass)', background: '#f0e8d0', padding: '3px 8px' }}>
                            You
                        </span>
                    )}
                    {minor && (
                        <span style={{ fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '0.15em', textTransform: 'uppercase', color: '#b05a00', background: '#fff0d6', padding: '3px 8px' }}>
                            Minor
                        </span>
                    )}
                    {errorCount > 0 && !isExpanded && (
                        <span style={{ fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '0.1em', textTransform: 'uppercase', color: 'white', background: 'var(--blaze)', padding: '3px 8px' }}>
                            {errorCount} error{errorCount > 1 ? 's' : ''}
                        </span>
                    )}
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    {!isPrimary && onRemove && (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); onRemove(); }}
                            style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.1em', textTransform: 'uppercase', color: 'var(--blaze)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                        >
                            Remove
                        </button>
                    )}
                    <span style={{ fontFamily: 'var(--mono)', fontSize: 14, color: '#888' }}>
                        {isExpanded ? '▲' : '▼'}
                    </span>
                </div>
            </div>

            {/* Body */}
            {isExpanded && (
                <div style={{ padding: '24px 24px 28px' }}>

                    {/* Personal Info */}
                    <div style={sectionLabel()}>
                        <span style={{ width: 16, height: 1, background: 'var(--blaze)', display: 'block' }} />
                        Personal Information
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 20 }}>
                        <div>
                            <label style={labelStyle}>First Name *</label>
                            <input type="text" value={hunter.first_name} onChange={e => onUpdate('first_name', e.target.value)} style={inputStyle(!!e('first_name'))} />
                            {e('first_name') && <div style={errStyle}>{e('first_name')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Last Name *</label>
                            <input type="text" value={hunter.last_name} onChange={e => onUpdate('last_name', e.target.value)} style={inputStyle(!!e('last_name'))} />
                            {e('last_name') && <div style={errStyle}>{e('last_name')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Date of Birth *</label>
                            <input type="date" value={hunter.date_of_birth} onChange={e => onUpdate('date_of_birth', e.target.value)} style={inputStyle(!!e('date_of_birth'))} />
                            {e('date_of_birth') && <div style={errStyle}>{e('date_of_birth')}</div>}
                            {minor && !e('date_of_birth') && <div style={{ color: '#b05a00', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 4 }}>⚠ Minor — under 18</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Email *</label>
                            <input type="email" value={hunter.email} onChange={e => onUpdate('email', e.target.value)} style={inputStyle(!!e('email'))} />
                            {e('email') && <div style={errStyle}>{e('email')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Home Phone</label>
                            <input type="tel" value={hunter.home_phone} onChange={e => onUpdate('home_phone', formatPhoneInput(e.target.value))} style={inputStyle(!!e('home_phone'))} placeholder="(555) 123-4567" />
                            {e('home_phone') && <div style={errStyle}>{e('home_phone')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Cell Phone *</label>
                            <input type="tel" value={hunter.cell_phone} onChange={e => onUpdate('cell_phone', formatPhoneInput(e.target.value))} style={inputStyle(!!e('cell_phone'))} placeholder="(555) 123-4567" />
                            {e('cell_phone') && <div style={errStyle}>{e('cell_phone')}</div>}
                        </div>
                    </div>

                    {/* Address */}
                    <div style={sectionLabel()}>
                        <span style={{ width: 16, height: 1, background: 'var(--blaze)', display: 'block' }} />
                        Home Address
                    </div>
                    <div style={{ display: 'grid', gap: 14, marginBottom: 20 }}>
                        <div>
                            <label style={labelStyle}>Street Address *</label>
                            <input type="text" value={hunter.address_line1} onChange={e => onUpdate('address_line1', e.target.value)} style={inputStyle(!!e('address_line1'))} placeholder="123 Main St" />
                            {e('address_line1') && <div style={errStyle}>{e('address_line1')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Apt / Suite / Unit</label>
                            <input type="text" value={hunter.address_line2} onChange={e => onUpdate('address_line2', e.target.value)} style={inputStyle()} placeholder="Optional" />
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 14 }}>
                            <div>
                                <label style={labelStyle}>City *</label>
                                <input type="text" value={hunter.city} onChange={e => onUpdate('city', e.target.value)} style={inputStyle(!!e('city'))} />
                                {e('city') && <div style={errStyle}>{e('city')}</div>}
                            </div>
                            <div>
                                <label style={labelStyle}>State *</label>
                                <select value={hunter.state_code} onChange={e => onUpdate('state_code', e.target.value)} style={{ ...inputStyle(!!e('state_code')), appearance: 'none' }}>
                                    <option value="">—</option>
                                    {US_STATES.map(([code, name]) => <option key={code} value={code}>{code} — {name}</option>)}
                                </select>
                                {e('state_code') && <div style={errStyle}>{e('state_code')}</div>}
                            </div>
                            <div>
                                <label style={labelStyle}>Zip Code *</label>
                                <input type="text" value={hunter.zip_code} onChange={e => onUpdate('zip_code', e.target.value)} style={inputStyle(!!e('zip_code'))} maxLength={10} />
                                {e('zip_code') && <div style={errStyle}>{e('zip_code')}</div>}
                            </div>
                        </div>
                    </div>

                    {/* Emergency Contact */}
                    <div style={sectionLabel()}>
                        <span style={{ width: 16, height: 1, background: 'var(--blaze)', display: 'block' }} />
                        Emergency Contact
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 14, marginBottom: 20 }}>
                        <div>
                            <label style={labelStyle}>Contact Name *</label>
                            <input type="text" value={hunter.emergency_contact_name} onChange={e => onUpdate('emergency_contact_name', e.target.value)} style={inputStyle(!!e('emergency_contact_name'))} />
                            {e('emergency_contact_name') && <div style={errStyle}>{e('emergency_contact_name')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Relationship *</label>
                            <input type="text" value={hunter.emergency_contact_relationship} onChange={e => onUpdate('emergency_contact_relationship', e.target.value)} style={inputStyle(!!e('emergency_contact_relationship'))} placeholder="e.g., Spouse" />
                            {e('emergency_contact_relationship') && <div style={errStyle}>{e('emergency_contact_relationship')}</div>}
                        </div>
                        <div style={{ gridColumn: '1 / -1' }}>
                            <label style={labelStyle}>Contact Phone *</label>
                            <input type="tel" value={hunter.emergency_contact_phone} onChange={e => onUpdate('emergency_contact_phone', formatPhoneInput(e.target.value))} style={inputStyle(!!e('emergency_contact_phone'))} placeholder="(555) 123-4567" />
                            {e('emergency_contact_phone') && <div style={errStyle}>{e('emergency_contact_phone')}</div>}
                        </div>
                    </div>

                    {/* Medical */}
                    <div style={sectionLabel()}>
                        <span style={{ width: 16, height: 1, background: 'var(--blaze)', display: 'block' }} />
                        Medical Conditions
                    </div>
                    <div style={{ marginBottom: 28 }}>
                        <textarea
                            value={hunter.medical_conditions}
                            onChange={e => onUpdate('medical_conditions', e.target.value)}
                            rows={3}
                            placeholder="List any relevant medical conditions, allergies, or medications the landowner / emergency services should be aware of. Leave blank if none."
                            style={{ ...inputStyle(), resize: 'vertical', lineHeight: 1.6, fontFamily: 'var(--body)', fontSize: 14 }}
                        />
                    </div>

                    {/* Driver's License */}
                    <div style={sectionLabel('var(--brass)')}>
                        <span style={{ width: 16, height: 1, background: 'var(--brass)', display: 'block' }} />
                        Driver's License
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 14, marginBottom: 12 }}>
                        <div>
                            <label style={labelStyle}>DL Number *</label>
                            <input type="text" value={hunter.dl_number} onChange={e => onUpdate('dl_number', e.target.value)} style={inputStyle(!!e('dl_number'))} />
                            {e('dl_number') && <div style={errStyle}>{e('dl_number')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Issuing State *</label>
                            <select value={hunter.dl_state} onChange={e => onUpdate('dl_state', e.target.value)} style={{ ...inputStyle(!!e('dl_state')), appearance: 'none' }}>
                                <option value="">—</option>
                                {US_STATES.map(([code, name]) => <option key={code} value={code}>{code} — {name}</option>)}
                            </select>
                            {e('dl_state') && <div style={errStyle}>{e('dl_state')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Expiry Date *</label>
                            <input type="date" value={hunter.dl_expiry} onChange={e => onUpdate('dl_expiry', e.target.value)} style={inputStyle(!!e('dl_expiry'))} />
                            {e('dl_expiry') && <div style={errStyle}>{e('dl_expiry')}</div>}
                        </div>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 12 }}>
                        <FileUploadInput
                            label="DL Photo — Front"
                            file={hunter.dl_photo}
                            onChange={f => onUpdate('dl_photo', f)}
                        />
                        <FileUploadInput
                            label="DL Photo — Back"
                            file={hunter.dl_photo_back}
                            onChange={f => onUpdate('dl_photo_back', f)}
                        />
                    </div>
                    <div style={{ marginBottom: 28 }}>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12, cursor: 'pointer' }}>
                            <input
                                type="checkbox"
                                checked={hunter.dl_confirmed_current}
                                onChange={e => onUpdate('dl_confirmed_current', e.target.checked)}
                                style={{ accentColor: 'var(--brass)', width: 16, height: 16 }}
                            />
                            <span style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink)' }}>
                                I confirm this driver's license is current and not expired.
                            </span>
                        </label>
                        {!hunter.dl_confirmed_current && (
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: '#b05a00', marginTop: 6 }}>
                                Applications with unconfirmed DL information may be declined upon review.
                            </div>
                        )}
                    </div>

                    {/* Hunting License */}
                    <div style={sectionLabel('var(--brass)')}>
                        <span style={{ width: 16, height: 1, background: 'var(--brass)', display: 'block' }} />
                        Hunting License
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 14, marginBottom: 12 }}>
                        <div>
                            <label style={labelStyle}>License Number *</label>
                            <input type="text" value={hunter.hunting_license_number} onChange={e => onUpdate('hunting_license_number', e.target.value)} style={inputStyle(!!e('hunting_license_number'))} />
                            {e('hunting_license_number') && <div style={errStyle}>{e('hunting_license_number')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Issuing State *</label>
                            <input
                                type="text"
                                value={propertyState || hunter.hunting_license_state}
                                readOnly
                                disabled
                                style={{ ...inputStyle(!!e('hunting_license_state')), background: '#f2efe9', color: 'var(--ink-lift)', cursor: 'not-allowed' }}
                            />
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--sage-dim)', marginTop: 4 }}>
                                Must match the property's state ({propertyState})
                            </div>
                            {e('hunting_license_state') && <div style={errStyle}>{e('hunting_license_state')}</div>}
                        </div>
                        <div>
                            <label style={labelStyle}>Expiry Date *</label>
                            <input type="date" value={hunter.hunting_license_expiry} onChange={e => onUpdate('hunting_license_expiry', e.target.value)} style={inputStyle(!!e('hunting_license_expiry'))} />
                            {e('hunting_license_expiry') && <div style={errStyle}>{e('hunting_license_expiry')}</div>}
                        </div>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 12 }}>
                        <FileUploadInput
                            label="License Photo — Front"
                            file={hunter.hunting_license_photo}
                            onChange={f => onUpdate('hunting_license_photo', f)}
                        />
                        <FileUploadInput
                            label="License Photo — Back"
                            file={hunter.hunting_license_photo_back}
                            onChange={f => onUpdate('hunting_license_photo_back', f)}
                        />
                    </div>
                    <div style={{ marginBottom: 8 }}>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12, cursor: 'pointer' }}>
                            <input
                                type="checkbox"
                                checked={hunter.hunting_license_confirmed_current}
                                onChange={e => onUpdate('hunting_license_confirmed_current', e.target.checked)}
                                style={{ accentColor: 'var(--brass)', width: 16, height: 16 }}
                            />
                            <span style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink)' }}>
                                I confirm this hunting license is current and valid for the proposed season.
                            </span>
                        </label>
                        {!hunter.hunting_license_confirmed_current && (
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: '#b05a00', marginTop: 6 }}>
                                Applications with unconfirmed license information may be declined upon review.
                            </div>
                        )}
                    </div>

                    {/* Save as guest option for non-primary hunters */}
                    {!isPrimary && (
                        <div style={{ marginTop: 20, padding: '14px 16px', background: '#f7f4ef', borderTop: '1px solid #e0dbd2' }}>
                            <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                                <input
                                    type="checkbox"
                                    checked={hunter.save_as_guest}
                                    onChange={e => onUpdate('save_as_guest', e.target.checked)}
                                    style={{ accentColor: 'var(--brass)', width: 16, height: 16 }}
                                />
                                <span style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink)' }}>
                                    Save to my guest list for future applications
                                </span>
                            </label>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Availability calendar (day hunt date picker) ────────────────────────────────

interface AvailabilityCalendarProps {
    seasonStart: string;        // YYYY-MM-DD
    seasonEnd: string;          // YYYY-MM-DD
    today: string;              // YYYY-MM-DD
    unavailable: UnavailableRange[];
    start: string;
    end: string;
    onChange: (start: string, end: string) => void;
}

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

function AvailabilityCalendar({ seasonStart, seasonEnd, today, unavailable, start, end, onChange }: AvailabilityCalendarProps) {
    // Navigation is clamped to the months the season spans (and never before now).
    const minMonthAnchor = seasonStart < today ? today : seasonStart;
    const [view, setView] = useState(() => {
        const base = start || minMonthAnchor;
        return { year: Number(base.slice(0, 4)), month: Number(base.slice(5, 7)) - 1 };
    });

    const minYM = `${minMonthAnchor.slice(0, 4)}-${minMonthAnchor.slice(5, 7)}`;
    const maxYM = `${seasonEnd.slice(0, 4)}-${seasonEnd.slice(5, 7)}`;
    const viewYM = `${view.year}-${String(view.month + 1).padStart(2, '0')}`;
    const canPrev = viewYM > minYM;
    const canNext = viewYM < maxYM;

    function shiftMonth(delta: number) {
        setView(v => {
            const d = new Date(v.year, v.month + delta, 1);
            return { year: d.getFullYear(), month: d.getMonth() };
        });
    }

    function dayState(iso: string): 'disabled' | 'available' | 'selected' | 'in-range' {
        const outOfSeason = iso < seasonStart || iso > seasonEnd || iso < today;
        if (outOfSeason || dayIsUnavailable(iso, unavailable)) return 'disabled';
        if (iso === start || iso === end) return 'selected';
        if (start && end && iso > start && iso < end) return 'in-range';
        return 'available';
    }

    function pick(iso: string) {
        // No anchor yet → begin a new selection at the clicked day.
        if (!start) {
            onChange(iso, '');
            return;
        }
        // Anchor set but no end yet → set the other end of the range. Clicking
        // before the anchor is allowed (we order the pair). A span that crosses a
        // taken date can't be booked, so we restart at the clicked day instead.
        if (!end) {
            if (iso === start) return;
            const [s, e] = iso < start ? [iso, start] : [start, iso];
            if (rangeOverlapsUnavailable(s, e, unavailable)) {
                onChange(iso, '');
                return;
            }
            onChange(s, e);
            return;
        }
        // A complete range already exists → grow it to include the clicked day
        // rather than starting over. Clicking inside the range leaves it unchanged.
        const s = iso < start ? iso : start;
        const e = iso > end ? iso : end;
        if (rangeOverlapsUnavailable(s, e, unavailable)) {
            onChange(iso, '');
            return;
        }
        onChange(s, e);
    }

    const firstWeekday = new Date(view.year, view.month, 1).getDay();
    const daysInMonth = new Date(view.year, view.month + 1, 0).getDate();
    const monthLabel = new Date(view.year, view.month, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    const cells: (string | null)[] = [
        ...Array(firstWeekday).fill(null),
        ...Array.from({ length: daysInMonth }, (_, i) => isoDate(view.year, view.month, i + 1)),
    ];

    const navBtn = (enabled: boolean): React.CSSProperties => ({
        fontFamily: 'var(--mono)', fontSize: 16, lineHeight: 1, width: 32, height: 32,
        border: '1px solid var(--parch-deep)', background: 'transparent',
        color: enabled ? 'var(--ink)' : '#c8c3ba', cursor: enabled ? 'pointer' : 'not-allowed',
    });

    return (
        <div style={{ border: '1px solid var(--parch-deep)', padding: 20, maxWidth: 360 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                <button type="button" onClick={() => canPrev && shiftMonth(-1)} disabled={!canPrev} style={navBtn(canPrev)}>‹</button>
                <span style={{ fontFamily: 'var(--mono)', fontSize: 12, letterSpacing: '0.1em', textTransform: 'uppercase', color: 'var(--ink)' }}>
                    {monthLabel}
                </span>
                <button type="button" onClick={() => canNext && shiftMonth(1)} disabled={!canNext} style={navBtn(canNext)}>›</button>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 2 }}>
                {WEEKDAYS.map(d => (
                    <div key={d} style={{ fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '0.08em', color: 'var(--sage-dim)', textAlign: 'center', padding: '4px 0' }}>
                        {d}
                    </div>
                ))}
                {cells.map((iso, i) => {
                    if (!iso) return <div key={`e${i}`} />;
                    const s = dayState(iso);
                    const dayNum = Number(iso.slice(8, 10));
                    const inSelection = s === 'selected' || s === 'in-range';
                    const bg = inSelection ? 'var(--blaze)' : 'transparent';
                    const color = s === 'disabled' ? '#c8c3ba' : inSelection ? 'white' : 'var(--ink)';
                    return (
                        <button
                            key={iso}
                            type="button"
                            disabled={s === 'disabled'}
                            onClick={() => pick(iso)}
                            style={{
                                fontFamily: 'var(--mono)', fontSize: 12, height: 36,
                                border: 'none', background: bg, color,
                                cursor: s === 'disabled' ? 'not-allowed' : 'pointer',
                                textDecoration: s === 'disabled' ? 'line-through' : 'none',
                            }}
                        >
                            {dayNum}
                        </button>
                    );
                })}
            </div>

            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, marginTop: 14 }}>
                <div style={{ display: 'flex', gap: 16, fontFamily: 'var(--mono)', fontSize: 9, color: 'var(--sage-dim)' }}>
                    <span><span style={{ display: 'inline-block', width: 10, height: 10, background: 'var(--blaze)', marginRight: 5, verticalAlign: 'middle' }} />Selected</span>
                    <span style={{ textDecoration: 'line-through' }}>Unavailable</span>
                </div>
                {start && (
                    <button
                        type="button"
                        onClick={() => onChange('', '')}
                        style={{ fontFamily: 'var(--mono)', fontSize: 9, letterSpacing: '0.08em', textTransform: 'uppercase', background: 'transparent', border: 'none', color: 'var(--blaze)', cursor: 'pointer', padding: 0 }}
                    >
                        Clear
                    </button>
                )}
            </div>
        </div>
    );
}

// ── Step indicator ────────────────────────────────────────────────────────────

function StepIndicator({ step }: { step: 1 | 2 }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 0, marginBottom: 40 }}>
            {[{ n: 1, label: 'Application Details' }, { n: 2, label: 'Hunter Details' }].map(({ n, label }, i) => (
                <div key={n} style={{ display: 'flex', alignItems: 'center' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <div style={{
                            width: 28, height: 28, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center',
                            background: step >= n ? 'var(--blaze)' : '#e0dbd2',
                            fontFamily: 'var(--mono)', fontSize: 11, color: step >= n ? 'white' : '#888',
                        }}>
                            {n}
                        </div>
                        <span style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.12em', textTransform: 'uppercase', color: step >= n ? 'var(--ink)' : '#888' }}>
                            {label}
                        </span>
                    </div>
                    {i === 0 && (
                        <div style={{ width: 48, height: 1, background: step >= 2 ? 'var(--blaze)' : '#e0dbd2', margin: '0 16px' }} />
                    )}
                </div>
            ))}
        </div>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function ApplyIndex({ listing, property, unavailableRanges, primaryHunter, savedGuests, certificationDoc, canApply, restrictedState }: ApplyIndexProps) {
    const [step, setStep] = useState<1 | 2>(1);
    const [expandedIndex, setExpandedIndex] = useState<number>(0);
    const [showGuestPicker, setShowGuestPicker] = useState(false);
    const [showCertModal, setShowCertModal] = useState(false);

    const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD, local tz

    // Listing type drives the whole date experience: annual/seasonal leases run
    // the entire fixed season (applicant can't change the term); a day hunt lets
    // the applicant pick an available range inside the season.
    const isFixedTerm = listing.listing_type === 'annual_lease' || listing.listing_type === 'seasonal_lease';
    const isDayHunt   = listing.listing_type === 'day_hunt';
    const seasonEnded = !!listing.season_end && listing.season_end < today;
    // A fixed-term listing whose season has ended can no longer be applied to.
    const blockedPastSeason = isFixedTerm && seasonEnded;

    // Only prefill the proposed term from the listing's season when it is still a
    // valid forward-looking range. A past season (e.g. a stale active listing)
    // would otherwise pre-load dates that always fail server validation
    // (proposed_start must be today or later), making the submit appear to do
    // nothing.
    const seasonUsable = !!listing.season_start && !!listing.season_end
        && listing.season_start >= today && listing.season_end > listing.season_start;

    // Fixed-term: always show the season dates (even an ended one — they stay
    // visible but locked and the submit is blocked). Day hunt: applicant picks,
    // so start empty. Otherwise fall back to the forward-looking prefill.
    const defaultStart = isFixedTerm ? (listing.season_start ?? '') : (!isDayHunt && seasonUsable ? listing.season_start! : '');
    const defaultEnd   = isFixedTerm ? (listing.season_end ?? '')   : (!isDayHunt && seasonUsable ? listing.season_end!   : '');

    const { data, setData, post, processing, errors } = useForm<{
        application_type: 'individual' | 'club';
        proposed_start: string;
        proposed_end: string;
        message: string;
        hunters: HunterData[];
        certification_accepted: boolean;
    }>({
        application_type: 'individual',
        proposed_start:   defaultStart,
        proposed_end:     defaultEnd,
        message:                  '',
        hunters:                  [{ ...primaryHunter, dl_photo: null, dl_photo_back: null, hunting_license_photo: null, hunting_license_photo_back: null }],
        certification_accepted:   false,
    });

    function updateHunter(index: number, field: keyof HunterData, value: unknown) {
        const hunters = [...data.hunters];
        hunters[index] = { ...hunters[index], [field]: value };
        setData('hunters', hunters);
    }

    function addBlankHunter() {
        setData('hunters', [...data.hunters, blankGuest()]);
        setExpandedIndex(data.hunters.length);
        setShowGuestPicker(false);
    }

    function addSavedGuest(guest: SavedGuest) {
        setData('hunters', [...data.hunters, guestToHunter(guest)]);
        setExpandedIndex(data.hunters.length);
        setShowGuestPicker(false);
    }

    function removeHunter(index: number) {
        const hunters = data.hunters.filter((_, i) => i !== index);
        setData('hunters', hunters);
        if (expandedIndex >= hunters.length) {
            setExpandedIndex(hunters.length - 1);
        }
    }

    function validateStep1(): boolean {
        // Mirror the server rules so the step gate matches: start today-or-later,
        // end after start. (Inertia still runs full server-side validation.)
        if (blockedPastSeason) return false;
        if (!data.proposed_start || !data.proposed_end) return false;
        if (data.proposed_start < today) return false;
        if (data.proposed_end <= data.proposed_start) return false;

        // Day hunt: the range must sit inside the season and avoid taken dates.
        if (isDayHunt) {
            if (listing.season_start && data.proposed_start < listing.season_start) return false;
            if (listing.season_end && data.proposed_end > listing.season_end) return false;
            if (rangeOverlapsUnavailable(data.proposed_start, data.proposed_end, unavailableRanges)) return false;
        }
        return true;
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(`/apply/${listing.id}`, {
            onError: (formErrors) => {
                // Step-1 errors are invisible while the user is on step 2, which
                // made a failed submit look like it did nothing. Jump back to the
                // offending step so the failure is always surfaced.
                const step1Fields = ['application_type', 'proposed_start', 'proposed_end', 'message'];
                if (Object.keys(formErrors).some(key => step1Fields.includes(key))) {
                    setStep(1);
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
        });
    }

    // ── Sidebar card (shared across steps) ───────────────────────────────────
    const sidebar = (
        <div style={{ position: 'sticky', top: 120 }}>
            <div className="field-card">
                <div className="field-card-header">
                    <div>
                        <div className="field-card-label">Lease Listing</div>
                        <div className="field-card-id">AH-{listing.id.slice(0, 8).toUpperCase()}</div>
                    </div>
                    <div className="field-stamp">Open</div>
                </div>
                <div className="field-rows">
                    <div className="field-row">
                        <span className="field-row-label">Type</span>
                        <span className="field-row-value">{formatType(listing.listing_type)}</span>
                    </div>
                    {listing.season_start && (
                        <div className="field-row">
                            <span className="field-row-label">Season Start</span>
                            <span className="field-row-value">{formatDate(listing.season_start)}</span>
                        </div>
                    )}
                    {listing.season_end && (
                        <div className="field-row">
                            <span className="field-row-label">Season End</span>
                            <span className="field-row-value">{formatDate(listing.season_end)}</span>
                        </div>
                    )}
                    {listing.max_hunters != null && (
                        <div className="field-row">
                            <span className="field-row-label">Max Hunters</span>
                            <span className="field-row-value">{listing.max_hunters}</span>
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
                    <div className="field-price">{formatPrice(listing)}</div>
                </div>
            </div>

            {step === 1 && (
                <div style={{ marginTop: 20, padding: '16px 20px', background: 'var(--bone)', border: '1px solid var(--parch-deep)' }}>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: 'var(--sage-dim)', textTransform: 'uppercase', marginBottom: 8 }}>
                        How It Works
                    </div>
                    <ol style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink-lift)', lineHeight: 1.7, margin: 0, paddingLeft: 18 }}>
                        <li>Submit your application</li>
                        <li>Landowner reviews &amp; responds</li>
                        <li>If approved, sign the lease online</li>
                        <li>Pay deposit — access info released</li>
                    </ol>
                </div>
            )}

            {step === 2 && (
                <div style={{ marginTop: 20, padding: '16px 20px', background: '#fff8ec', border: '1px solid #e8d9a0' }}>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', color: '#8a6a00', textTransform: 'uppercase', marginBottom: 8 }}>
                        Hunter Count
                    </div>
                    <div style={{ fontFamily: 'var(--display)', fontSize: 28, color: 'var(--ink)', lineHeight: 1 }}>
                        {data.hunters.length}
                    </div>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: '#6b6558', marginTop: 4 }}>
                        {data.hunters.length === 1 ? 'hunter named' : 'hunters named'}
                    </div>
                    {listing.max_hunters && data.hunters.length > listing.max_hunters && (
                        <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--blaze)', marginTop: 8 }}>
                            ⚠ Exceeds max of {listing.max_hunters} for this listing
                        </div>
                    )}
                </div>
            )}
        </div>
    );

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
                    <Link href={`/properties/${property.slug}`} style={{ color: 'var(--parch-dim)', textDecoration: 'none' }}>{property.title}</Link>
                    <span>›</span>
                    <span style={{ color: 'var(--brass)' }}>Apply</span>
                </div>

                <div style={{ maxWidth: 1200, margin: '0 auto', padding: '0 40px 64px', position: 'relative', zIndex: 1 }}>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em', color: 'var(--brass)', textTransform: 'uppercase', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 16 }}>
                        <span style={{ display: 'block', width: 32, height: 1, background: 'var(--brass)' }} />
                        Chapter I — Application
                    </div>
                    <h1 style={{ fontFamily: 'var(--display)', fontSize: 'clamp(32px, 4vw, 56px)', fontWeight: 400, lineHeight: 1.05, letterSpacing: '-0.02em', color: 'var(--bone)', marginBottom: 12 }}>
                        Apply for This Lease
                    </h1>
                    <p style={{ fontFamily: 'var(--body)', fontSize: 16, fontStyle: 'italic', color: 'var(--parch-dim)', margin: 0 }}>
                        {property.county} County, {property.state_code} · {parseFloat(property.total_acres).toLocaleString()} acres
                    </p>
                </div>
            </div>

            {/* ── CONTENT ─────────────────────────────────────────────────── */}
            <div style={{ maxWidth: 1200, margin: '0 auto', padding: '64px 40px 80px', display: 'grid', gridTemplateColumns: '1fr 340px', gap: 72, alignItems: 'start' }}>

                <form onSubmit={handleSubmit}>
                    {!canApply && (
                        <div style={{
                            border: '1px solid var(--blaze)',
                            background: 'rgba(193,75,42,0.06)',
                            padding: '20px 24px',
                            marginBottom: 32,
                        }}>
                            <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', textTransform: 'uppercase', color: 'var(--blaze)', marginBottom: 8 }}>
                                Outside your hunting region
                            </div>
                            <p style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink)', margin: '0 0 14px', lineHeight: 1.5 }}>
                                Your membership covers hunting in {restrictedState ?? 'your home state'} only, so you can&rsquo;t apply to this {property.state_code} listing. Upgrade to a multi-state plan to hunt anywhere.
                            </p>
                            <Link href="/pricing" className="btn-solid" style={{ display: 'inline-block', fontSize: 12 }}>
                                View memberships →
                            </Link>
                        </div>
                    )}
                    <StepIndicator step={step} />

                    {/* ── STEP 1: Application Details ─────────────────── */}
                    {step === 1 && (
                        <>
                            {/* Application type */}
                            <div style={{ marginBottom: 44 }}>
                                <div style={sectionLabel()}>
                                    <span style={{ width: 20, height: 1, background: 'var(--blaze)', display: 'block' }} />
                                    Application Type
                                </div>
                                <div style={{ display: 'flex', gap: 16 }}>
                                    {(['individual', 'club'] as const).map(type => (
                                        <label key={type} style={{
                                            display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer',
                                            padding: '16px 24px', flex: 1,
                                            border: `1px solid ${data.application_type === type ? 'var(--blaze)' : 'var(--parch-deep)'}`,
                                            background: data.application_type === type ? 'var(--bone)' : 'transparent',
                                        }}>
                                            <input type="radio" name="application_type" value={type} checked={data.application_type === type} onChange={() => setData('application_type', type)} style={{ accentColor: 'var(--blaze)' }} />
                                            <div>
                                                <div style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--ink)', marginBottom: 4 }}>
                                                    {type === 'individual' ? 'Individual' : 'Hunting Club'}
                                                </div>
                                                <div style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink-lift)' }}>
                                                    {type === 'individual' ? 'You and your party' : 'Applying as a registered club'}
                                                </div>
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            </div>

                            {/* Dates */}
                            <div style={{ marginBottom: 44 }}>
                                <div style={sectionLabel()}>
                                    <span style={{ width: 20, height: 1, background: 'var(--blaze)', display: 'block' }} />
                                    {isDayHunt ? 'Select Your Dates' : 'Proposed Season'}
                                </div>

                                {blockedPastSeason && (
                                    <div style={{ background: '#fff0f0', border: '1px solid var(--blaze)', padding: '14px 18px', marginBottom: 16, fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink)' }}>
                                        This listing's season ended on {formatDate(listing.season_end)} and is no longer accepting applications.
                                    </div>
                                )}

                                {isFixedTerm ? (
                                    // Annual / seasonal: the term is the listing's whole season and the
                                    // applicant cannot change it — show it locked.
                                    <>
                                        <p style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink-lift)', margin: '0 0 14px', lineHeight: 1.6 }}>
                                            This is a {formatType(listing.listing_type).toLowerCase()} — the term covers the full season and is set by the landowner.
                                        </p>
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                                            <div>
                                                <label style={labelStyle}>Season Start</label>
                                                <input type="date" value={data.proposed_start} readOnly disabled style={{ ...inputStyle(!!errors.proposed_start), background: '#f2efe9', color: 'var(--ink-lift)', cursor: 'not-allowed' }} />
                                                {errors.proposed_start && <div style={{ color: 'var(--blaze)', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 4 }}>{errors.proposed_start}</div>}
                                            </div>
                                            <div>
                                                <label style={labelStyle}>Season End</label>
                                                <input type="date" value={data.proposed_end} readOnly disabled style={{ ...inputStyle(!!errors.proposed_end), background: '#f2efe9', color: 'var(--ink-lift)', cursor: 'not-allowed' }} />
                                                {errors.proposed_end && <div style={{ color: 'var(--blaze)', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 4 }}>{errors.proposed_end}</div>}
                                            </div>
                                        </div>
                                    </>
                                ) : isDayHunt && listing.season_start && listing.season_end ? (
                                    // Day hunt: applicant picks an available range inside the season.
                                    <>
                                        <p style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--ink-lift)', margin: '0 0 14px', lineHeight: 1.6 }}>
                                            Click your arrival date, then your departure date. Click another day to extend the range, or Clear to start over. Crossed-out dates are already booked or unavailable.
                                        </p>
                                        <AvailabilityCalendar
                                            seasonStart={listing.season_start}
                                            seasonEnd={listing.season_end}
                                            today={today}
                                            unavailable={unavailableRanges}
                                            start={data.proposed_start}
                                            end={data.proposed_end}
                                            onChange={(s, en) => setData(d => ({ ...d, proposed_start: s, proposed_end: en }))}
                                        />
                                        <div style={{ marginTop: 14, fontFamily: 'var(--mono)', fontSize: 12, color: 'var(--ink)' }}>
                                            {data.proposed_start && data.proposed_end
                                                ? `${formatDate(data.proposed_start)} → ${formatDate(data.proposed_end)}`
                                                : data.proposed_start
                                                    ? `${formatDate(data.proposed_start)} → select an end date`
                                                    : 'No dates selected yet'}
                                        </div>
                                        {(errors.proposed_start || errors.proposed_end) && (
                                            <div style={{ color: 'var(--blaze)', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 6 }}>
                                                {errors.proposed_start || errors.proposed_end}
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    // Fallback (e.g. auction, or a listing missing season dates): editable range.
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                                        <div>
                                            <label style={labelStyle}>Start Date *</label>
                                            <input type="date" min={today} value={data.proposed_start} onChange={e => setData('proposed_start', e.target.value)} style={inputStyle(!!errors.proposed_start)} />
                                            {errors.proposed_start && <div style={{ color: 'var(--blaze)', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 4 }}>{errors.proposed_start}</div>}
                                        </div>
                                        <div>
                                            <label style={labelStyle}>End Date *</label>
                                            <input type="date" min={data.proposed_start || today} value={data.proposed_end} onChange={e => setData('proposed_end', e.target.value)} style={inputStyle(!!errors.proposed_end)} />
                                            {errors.proposed_end && <div style={{ color: 'var(--blaze)', fontFamily: 'var(--mono)', fontSize: 10, marginTop: 4 }}>{errors.proposed_end}</div>}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Message */}
                            <div style={{ marginBottom: 44 }}>
                                <div style={sectionLabel()}>
                                    <span style={{ width: 20, height: 1, background: 'var(--blaze)', display: 'block' }} />
                                    Message to Landowner <span style={{ color: 'var(--parch-dim)', fontWeight: 300, marginLeft: 8 }}>(Optional)</span>
                                </div>
                                <textarea
                                    value={data.message}
                                    onChange={e => setData('message', e.target.value)}
                                    rows={5}
                                    placeholder="Introduce yourself, describe your hunting experience, and explain why you're a good fit for this property..."
                                    style={{ ...inputStyle(!!errors.message), resize: 'vertical', lineHeight: 1.6, fontFamily: 'var(--body)', fontSize: 15 }}
                                />
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.1em', color: 'var(--parch-dim)', marginTop: 6, textAlign: 'right' }}>
                                    {data.message.length}/1000
                                </div>
                            </div>

                            <div style={{ display: 'flex', gap: 16, alignItems: 'center' }}>
                                <button
                                    type="button"
                                    className="btn-solid"
                                    disabled={!validateStep1()}
                                    onClick={() => setStep(2)}
                                    style={{ opacity: validateStep1() ? 1 : 0.5, cursor: validateStep1() ? 'pointer' : 'not-allowed' }}
                                >
                                    Next: Hunter Details →
                                </button>
                                <Link href={`/properties/${property.slug}`} className="btn-outline">Cancel</Link>
                            </div>
                            <p style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--parch-dim)', fontStyle: 'italic', marginTop: 20 }}>
                                Submitting an application does not obligate you to any payment.
                            </p>
                        </>
                    )}

                    {/* ── STEP 2: Hunter Roster ───────────────────────── */}
                    {step === 2 && (
                        <>
                            <div style={{ fontFamily: 'var(--body)', fontSize: 15, color: 'var(--ink-lift)', marginBottom: 28, lineHeight: 1.6 }}>
                                Provide details for every hunter in your party. This information is used for vetting and access coordination. All licenses and IDs will be verified by the landowner.
                            </div>

                            {errors.hunters && (
                                <div style={{ background: '#fff0f0', border: '1px solid var(--blaze)', padding: '12px 16px', marginBottom: 20, fontFamily: 'var(--mono)', fontSize: 12, color: 'var(--blaze)' }}>
                                    {typeof errors.hunters === 'string' ? errors.hunters : 'Please correct the errors below.'}
                                </div>
                            )}

                            {/* Hunter cards */}
                            {data.hunters.map((hunter, i) => (
                                <HunterCard
                                    key={i}
                                    index={i}
                                    hunter={hunter}
                                    isPrimary={i === 0}
                                    isExpanded={expandedIndex === i}
                                    onToggle={() => setExpandedIndex(expandedIndex === i ? -1 : i)}
                                    onUpdate={(field, value) => updateHunter(i, field, value)}
                                    onRemove={i > 0 ? () => removeHunter(i) : undefined}
                                    errors={errors as Record<string, string>}
                                    propertyState={property.state_code}
                                />
                            ))}

                            {/* Add hunter */}
                            <div style={{ marginTop: 8, marginBottom: 40 }}>
                                {savedGuests.length > 0 && !showGuestPicker && (
                                    <button
                                        type="button"
                                        onClick={() => setShowGuestPicker(true)}
                                        style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--brass)', background: 'none', border: '1px solid var(--brass)', padding: '10px 20px', cursor: 'pointer', marginRight: 12 }}
                                    >
                                        + From Saved Guest List
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={addBlankHunter}
                                    style={{ fontFamily: 'var(--mono)', fontSize: 11, letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--ink)', background: 'none', border: '1px solid #d0ccc4', padding: '10px 20px', cursor: 'pointer' }}
                                >
                                    + Add Another Hunter
                                </button>

                                {/* Guest picker */}
                                {showGuestPicker && savedGuests.length > 0 && (
                                    <div style={{ marginTop: 16, border: '1px solid #d0ccc4', background: 'white' }}>
                                        <div style={{ padding: '12px 16px', borderBottom: '1px solid #e0dbd2', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                            <span style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', textTransform: 'uppercase', color: '#6b6558' }}>Saved Guests</span>
                                            <button type="button" onClick={() => setShowGuestPicker(false)} style={{ fontFamily: 'var(--mono)', fontSize: 10, color: '#888', background: 'none', border: 'none', cursor: 'pointer' }}>✕</button>
                                        </div>
                                        {savedGuests.map(g => (
                                            <div
                                                key={g.id}
                                                style={{ padding: '12px 16px', borderBottom: '1px solid #f0ece6', cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}
                                                onClick={() => addSavedGuest(g)}
                                            >
                                                <span style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink)' }}>{g.first_name} {g.last_name}</span>
                                                <span style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--brass)' }}>Add →</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* ── Certification ─────────────────────────── */}
                            <div style={{ marginBottom: 32, padding: '24px 28px', background: '#f8f5f0', border: '1px solid #d9d0c4' }}>
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.2em', textTransform: 'uppercase', color: 'var(--sage)', marginBottom: 14, display: 'flex', alignItems: 'center', gap: 10 }}>
                                    <span style={{ display: 'block', width: 20, height: 1, background: 'var(--sage)' }} />
                                    Accuracy Certification
                                </div>
                                <label style={{ display: 'flex', gap: 14, cursor: 'pointer', alignItems: 'flex-start' }}>
                                    <input
                                        type="checkbox"
                                        checked={data.certification_accepted}
                                        onChange={e => setData('certification_accepted', e.target.checked)}
                                        style={{ marginTop: 3, accentColor: 'var(--sage)', width: 16, height: 16, flexShrink: 0, cursor: 'pointer' }}
                                    />
                                    <span style={{ fontFamily: 'var(--body)', fontSize: 14, color: 'var(--ink)', lineHeight: 1.6 }}>
                                        I certify under penalty of perjury that all information provided about myself and every hunter named in this application is true, complete, and accurate.
                                        Providing false information may result in lease termination, forfeiture of fees, and legal liability.{' '}
                                        <button
                                            type="button"
                                            onClick={() => setShowCertModal(true)}
                                            style={{ fontFamily: 'var(--mono)', fontSize: 11, color: 'var(--brass)', background: 'none', border: 'none', padding: 0, cursor: 'pointer', textDecoration: 'underline', letterSpacing: '0.05em' }}
                                        >
                                            View full certification →
                                        </button>
                                    </span>
                                </label>
                                {errors.certification_accepted && (
                                    <div style={{ fontFamily: 'var(--mono)', fontSize: 10, color: 'var(--blaze)', marginTop: 10 }}>
                                        {errors.certification_accepted}
                                    </div>
                                )}
                            </div>

                            {/* Navigation */}
                            <div style={{ display: 'flex', gap: 16, alignItems: 'center', borderTop: '1px solid #e0dbd2', paddingTop: 32 }}>
                                <button
                                    type="submit"
                                    disabled={processing || !data.certification_accepted || blockedPastSeason || !canApply}
                                    className="btn-solid"
                                    style={{ opacity: (processing || !data.certification_accepted || blockedPastSeason || !canApply) ? 0.5 : 1, cursor: (processing || !data.certification_accepted || blockedPastSeason || !canApply) ? 'not-allowed' : 'pointer' }}
                                >
                                    {processing ? 'Submitting…' : 'Submit Application →'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setStep(1)}
                                    className="btn-outline"
                                >
                                    ← Back
                                </button>
                            </div>
                            <p style={{ fontFamily: 'var(--body)', fontSize: 13, color: 'var(--parch-dim)', fontStyle: 'italic', marginTop: 20 }}>
                                Hunter information is stored on your profile and reused for future applications. License and ID details will be verified by the landowner.
                            </p>
                        </>
                    )}
                </form>

                {/* ── SIDEBAR ─────────────────────────────────────────────── */}
                {sidebar}
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

            {/* ── CERTIFICATION MODAL ─────────────────────────────────────── */}
            {showCertModal && (
                <div
                    onClick={() => setShowCertModal(false)}
                    style={{
                        position: 'fixed', inset: 0, zIndex: 9000,
                        background: 'rgba(18,14,10,0.72)',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        padding: 24,
                    }}
                >
                    <div
                        onClick={e => e.stopPropagation()}
                        style={{
                            background: 'var(--bone)', maxWidth: 680, width: '100%',
                            maxHeight: '80vh', display: 'flex', flexDirection: 'column',
                            boxShadow: '0 24px 64px rgba(0,0,0,0.5)',
                        }}
                    >
                        {/* Modal header */}
                        <div style={{ padding: '20px 28px', borderBottom: '1px solid #d9d0c4', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexShrink: 0 }}>
                            <div>
                                <div style={{ fontFamily: 'var(--mono)', fontSize: 10, letterSpacing: '0.15em', textTransform: 'uppercase', color: 'var(--sage)', marginBottom: 4 }}>
                                    Legal Certification
                                </div>
                                <div style={{ fontFamily: 'var(--display)', fontSize: 18, color: 'var(--ink)' }}>
                                    {certificationDoc?.title ?? 'Hunter Information Accuracy Certification'}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowCertModal(false)}
                                style={{ fontFamily: 'var(--mono)', fontSize: 18, color: '#888', background: 'none', border: 'none', cursor: 'pointer', lineHeight: 1, padding: '4px 8px' }}
                            >
                                ✕
                            </button>
                        </div>

                        {/* Modal body — scrollable */}
                        <div style={{ padding: '28px 32px', overflowY: 'auto', flex: 1 }}>
                            <pre style={{
                                fontFamily: 'var(--body)', fontSize: 13.5, lineHeight: 1.8,
                                color: 'var(--ink)', whiteSpace: 'pre-wrap', wordBreak: 'break-word',
                                margin: 0,
                            }}>
                                {certificationDoc?.content ?? 'Certification text not available.'}
                            </pre>
                        </div>

                        {/* Modal footer */}
                        <div style={{ padding: '16px 28px', borderTop: '1px solid #d9d0c4', flexShrink: 0, display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
                            <button
                                type="button"
                                onClick={() => { setData('certification_accepted', true); setShowCertModal(false); }}
                                className="btn-solid"
                                style={{ fontSize: 13 }}
                            >
                                I Understand — Accept &amp; Close
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowCertModal(false)}
                                className="btn-outline"
                                style={{ fontSize: 13 }}
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
