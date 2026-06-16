import { Head, useForm, router } from '@inertiajs/react'
import { useState } from 'react'

interface Listing {
  id: string
  listing_type: string
  status: string
  visibility: string
  auto_renew: boolean
  season_start: string | null
  season_end: string | null
  min_hunters: number | null
  max_hunters: number
  price_per_hunter: number | null
  price_total: number | null
  deposit_amount: number | null
  deposit_percent: number | null
}

interface Props {
  property: { id: string; title: string }
  listings: Listing[]
  listingTypes: Record<string, string>
  statuses: Record<string, string>
  visibilities: Record<string, string>
}

// ── Design tokens ───────────────────────────────────────────────────────────
const INK = '#0A1512'
const ACCENT = '#C84C21'
const PAPER = '#F8F4EB'

const label: React.CSSProperties = {
  display: 'block',
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '10px',
  fontWeight: 600,
  letterSpacing: '.12em',
  textTransform: 'uppercase',
  color: '#a89874',
  marginBottom: '6px',
}

const input: React.CSSProperties = {
  width: '100%',
  fontFamily: 'Crimson Pro, Georgia, serif',
  fontSize: '15px',
  color: INK,
  background: '#fff',
  border: '1px solid #d4c9b0',
  padding: '9px 11px',
  outline: 'none',
  boxSizing: 'border-box',
}

const errStyle: React.CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '10px',
  color: ACCENT,
  marginTop: '5px',
}

const STATUS_COLOR: Record<string, string> = {
  active: ACCENT,
  draft: '#6b7856',
  sold_out: '#8a6d3b',
  expired: '#9c9388',
  archived: '#9c9388',
}

function money(v: number | null): string {
  if (v === null) return '—'
  return '$' + v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

function Field({ children, htmlFor, text, error }: { children: React.ReactNode; htmlFor: string; text: string; error?: string }) {
  return (
    <div>
      <label htmlFor={htmlFor} style={label}>{text}</label>
      {children}
      {error && <div style={errStyle}>{error}</div>}
    </div>
  )
}

function ListingForm({ property, listing, listingTypes, statuses, visibilities, onClose }: {
  property: { id: string }
  listing: Listing | null
  listingTypes: Record<string, string>
  statuses: Record<string, string>
  visibilities: Record<string, string>
  onClose: () => void
}) {
  const isEdit = listing !== null

  const { data, setData, post, put, processing, errors } = useForm({
    listing_type:     listing?.listing_type ?? 'annual_lease',
    status:           listing?.status ?? 'draft',
    visibility:       listing?.visibility ?? 'public',
    auto_renew:       listing?.auto_renew ?? false,
    season_start:     listing?.season_start ?? '',
    season_end:       listing?.season_end ?? '',
    max_hunters:      listing?.max_hunters != null ? String(listing.max_hunters) : '1',
    min_hunters:      listing?.min_hunters != null ? String(listing.min_hunters) : '',
    price_per_hunter: listing?.price_per_hunter != null ? String(listing.price_per_hunter) : '',
    price_total:      listing?.price_total != null ? String(listing.price_total) : '',
    deposit_amount:   listing?.deposit_amount != null ? String(listing.deposit_amount) : '',
    deposit_percent:  listing?.deposit_percent != null ? String(listing.deposit_percent) : '',
  })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    const opts = { onSuccess: onClose }
    if (isEdit) {
      put(`/member/properties/${property.id}/listings/${listing!.id}`, opts)
    } else {
      post(`/member/properties/${property.id}/listings`, opts)
    }
  }

  return (
    <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: '18px', border: `1px solid ${ACCENT}`, background: '#FBF7EE', padding: '24px 22px', marginBottom: '20px' }}>
      <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '18px', color: INK }}>
        {isEdit ? 'Edit Listing' : 'New Listing'}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '14px' }}>
        <Field htmlFor="listing_type" text="Type" error={errors.listing_type}>
          <select id="listing_type" value={data.listing_type} onChange={e => setData('listing_type', e.target.value)} style={input}>
            {Object.entries(listingTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Field>
        <Field htmlFor="status" text="Status" error={errors.status}>
          <select id="status" value={data.status} onChange={e => setData('status', e.target.value)} style={input}>
            {Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Field>
        <Field htmlFor="visibility" text="Visibility" error={errors.visibility}>
          <select id="visibility" value={data.visibility} onChange={e => setData('visibility', e.target.value)} style={input}>
            {Object.entries(visibilities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Field>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
        <Field htmlFor="season_start" text="Season Start" error={errors.season_start}>
          <input id="season_start" type="date" value={data.season_start} onChange={e => setData('season_start', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="season_end" text="Season End" error={errors.season_end}>
          <input id="season_end" type="date" value={data.season_end} onChange={e => setData('season_end', e.target.value)} style={input} />
        </Field>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
        <Field htmlFor="max_hunters" text="Max Hunters" error={errors.max_hunters}>
          <input id="max_hunters" type="number" min={1} value={data.max_hunters} onChange={e => setData('max_hunters', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="min_hunters" text="Min Hunters" error={errors.min_hunters}>
          <input id="min_hunters" type="number" min={1} value={data.min_hunters} onChange={e => setData('min_hunters', e.target.value)} style={input} placeholder="No minimum" />
        </Field>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
        <Field htmlFor="price_per_hunter" text="Price Per Hunter ($)" error={errors.price_per_hunter}>
          <input id="price_per_hunter" type="number" min={0} step="0.01" value={data.price_per_hunter} onChange={e => setData('price_per_hunter', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="price_total" text="Total Price ($)" error={errors.price_total}>
          <input id="price_total" type="number" min={0} step="0.01" value={data.price_total} onChange={e => setData('price_total', e.target.value)} style={input} />
        </Field>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
        <Field htmlFor="deposit_amount" text="Deposit ($)" error={errors.deposit_amount}>
          <input id="deposit_amount" type="number" min={0} step="0.01" value={data.deposit_amount} onChange={e => setData('deposit_amount', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="deposit_percent" text="Deposit (%)" error={errors.deposit_percent}>
          <input id="deposit_percent" type="number" min={0} max={100} value={data.deposit_percent} onChange={e => setData('deposit_percent', e.target.value)} style={input} />
        </Field>
      </div>

      <label style={{ display: 'flex', alignItems: 'center', gap: '9px', cursor: 'pointer', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
        <input type="checkbox" checked={data.auto_renew} onChange={e => setData('auto_renew', e.target.checked)} />
        Auto-renew this listing each season
      </label>

      <div style={{ display: 'flex', gap: '10px', borderTop: '1px solid #e5ddd0', paddingTop: '16px' }}>
        <button type="submit" disabled={processing} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 24px', background: INK, color: '#F4ECDC', border: 'none', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}>
          {processing ? 'Saving…' : isEdit ? 'Save Listing' : 'Create Listing'}
        </button>
        <button type="button" onClick={onClose} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 24px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', cursor: 'pointer' }}>
          Cancel
        </button>
      </div>
    </form>
  )
}

export default function PropertyListings({ property, listings, listingTypes, statuses, visibilities }: Props) {
  // null = no form; 'new' = create; otherwise the listing id being edited
  const [editing, setEditing] = useState<string | null>(null)

  function remove(id: string) {
    if (!confirm('Remove this listing? It will no longer appear to hunters.')) return
    router.delete(`/member/properties/${property.id}/listings/${id}`, { preserveScroll: true })
  }

  const editingListing = editing && editing !== 'new'
    ? listings.find(l => l.id === editing) ?? null
    : null

  return (
    <>
      <Head title={`Listings · ${property.title}`} />

      <div style={{ minHeight: '100vh', background: PAPER }}>

        {/* Topbar */}
        <div style={{ background: INK, borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '760px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: ACCENT, fontWeight: 700 }}>
                American Headhunter
              </span>
              <span style={{ color: '#3a5a50', fontSize: '12px' }}>·</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                Property Management
              </span>
            </div>
            <button onClick={() => router.post('/logout')} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'none', border: 'none', cursor: 'pointer', padding: '4px 0' }}>
              Sign Out
            </button>
          </div>
        </div>

        <div style={{ maxWidth: '760px', margin: '0 auto', padding: '36px 16px 64px' }}>

          <a href={`/member/properties/${property.id}`} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', textDecoration: 'none', display: 'inline-block', marginBottom: '18px' }}>
            ← {property.title}
          </a>

          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: '28px' }}>
            <div>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
                Listings
              </div>
              <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>
                {property.title}
              </h1>
            </div>
            {editing === null && (
              <button onClick={() => setEditing('new')} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 22px', background: INK, color: '#F4ECDC', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap' }}>
                + Add Listing
              </button>
            )}
          </div>

          {/* Create / edit form */}
          {editing === 'new' && (
            <ListingForm key="new" property={property} listing={null} listingTypes={listingTypes} statuses={statuses} visibilities={visibilities} onClose={() => setEditing(null)} />
          )}
          {editingListing && (
            <ListingForm key={editingListing.id} property={property} listing={editingListing} listingTypes={listingTypes} statuses={statuses} visibilities={visibilities} onClose={() => setEditing(null)} />
          )}

          {/* Existing listings */}
          {listings.length === 0 && editing === null ? (
            <div style={{ border: '1px dashed #d4c9b0', background: '#FBF7EE', padding: '36px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#6b5e50' }}>
                No listings yet. Add one to put this property in front of hunters.
              </div>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
              {listings.map(l => (
                <div key={l.id} style={{ border: '1px solid #d4c9b0', background: '#FBF7EE', padding: '18px 20px', opacity: editing === l.id ? 0.45 : 1 }}>
                  <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px' }}>
                    <div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '8px' }}>
                        <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '18px', color: INK }}>
                          {listingTypes[l.listing_type] ?? l.listing_type}
                        </span>
                        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', background: STATUS_COLOR[l.status] ?? INK, color: '#fff' }}>
                          {statuses[l.status] ?? l.status}
                        </span>
                        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', letterSpacing: '.06em', color: '#a89874' }}>
                          {visibilities[l.visibility] ?? l.visibility}
                        </span>
                      </div>
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#6b5e50', lineHeight: 1.7 }}>
                        <span>{money(l.price_per_hunter)}/hunter</span>
                        <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                        <span>Total {money(l.price_total)}</span>
                        <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                        <span>{l.min_hunters ? `${l.min_hunters}–` : ''}{l.max_hunters} hunters</span>
                        {(l.season_start || l.season_end) && (
                          <>
                            <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                            <span>{l.season_start ?? '?'} → {l.season_end ?? '?'}</span>
                          </>
                        )}
                        {l.auto_renew && (
                          <>
                            <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                            <span>auto-renew</span>
                          </>
                        )}
                      </div>
                    </div>
                    <div style={{ display: 'flex', gap: '8px', flexShrink: 0 }}>
                      <button onClick={() => setEditing(l.id)} disabled={editing !== null} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '7px 14px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', cursor: editing !== null ? 'not-allowed' : 'pointer' }}>
                        Edit
                      </button>
                      <button onClick={() => remove(l.id)} disabled={editing !== null} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '7px 14px', background: 'transparent', color: ACCENT, border: '1px solid rgba(200,76,33,0.4)', cursor: editing !== null ? 'not-allowed' : 'pointer' }}>
                        Delete
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

        </div>
      </div>
    </>
  )
}
