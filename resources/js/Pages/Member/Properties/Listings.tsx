import { useForm, router } from '@inertiajs/react'
import { useState } from 'react'
import { PortalChrome, PropertyHead, Section, BackLink, INK, ACCENT, type PropertySummary } from '@/Components/Member/PropertyChrome'

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
  price_per_hunter_weekly: number | null
  price_total: number | null
  deposit_amount: number | null
  deposit_percent: number | null
  booking_deposit_amount: number | null
  booking_deposit_percent: number | null
}

interface Props {
  property: PropertySummary & { id: string }
  listings: Listing[]
  listingTypes: Record<string, string>
  statuses: Record<string, string>
  visibilities: Record<string, string>
}

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
    price_per_hunter_weekly: listing?.price_per_hunter_weekly != null ? String(listing.price_per_hunter_weekly) : '',
    price_total:      listing?.price_total != null ? String(listing.price_total) : '',
    deposit_amount:   listing?.deposit_amount != null ? String(listing.deposit_amount) : '',
    deposit_percent:  listing?.deposit_percent != null ? String(listing.deposit_percent) : '',
    booking_deposit_amount:  listing?.booking_deposit_amount != null ? String(listing.booking_deposit_amount) : '',
    booking_deposit_percent: listing?.booking_deposit_percent != null ? String(listing.booking_deposit_percent) : '',
  })

  const isDayHunt = data.listing_type === 'day_hunt'

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
    <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: '18px', border: `1px solid ${ACCENT}`, background: '#fff', padding: '24px 22px', marginBottom: '20px' }}>
      <div style={{ fontFamily: 'var(--display)', fontSize: '18px', color: INK }}>
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
        <Field htmlFor="price_per_hunter" text={isDayHunt ? 'Price Per Hunter / Day ($)' : 'Price Per Hunter ($)'} error={errors.price_per_hunter}>
          <input id="price_per_hunter" type="number" min={0} step="0.01" value={data.price_per_hunter} onChange={e => setData('price_per_hunter', e.target.value)} style={input} />
        </Field>
        {isDayHunt ? (
          <Field htmlFor="price_per_hunter_weekly" text="Price Per Hunter / Week ($)" error={errors.price_per_hunter_weekly}>
            <input id="price_per_hunter_weekly" type="number" min={0} step="0.01" value={data.price_per_hunter_weekly} onChange={e => setData('price_per_hunter_weekly', e.target.value)} style={input} placeholder="No weekly discount" />
          </Field>
        ) : (
          <Field htmlFor="price_total" text="Total Price ($)" error={errors.price_total}>
            <input id="price_total" type="number" min={0} step="0.01" value={data.price_total} onChange={e => setData('price_total', e.target.value)} style={input} />
          </Field>
        )}
      </div>
      {isDayHunt && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
          <Field htmlFor="price_total" text="Total Price ($)" error={errors.price_total}>
            <input id="price_total" type="number" min={0} step="0.01" value={data.price_total} onChange={e => setData('price_total', e.target.value)} style={input} />
          </Field>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
        <Field htmlFor="deposit_amount" text="Deposit ($)" error={errors.deposit_amount}>
          <input id="deposit_amount" type="number" min={0} step="0.01" value={data.deposit_amount} onChange={e => setData('deposit_amount', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="deposit_percent" text="Deposit (%)" error={errors.deposit_percent}>
          <input id="deposit_percent" type="number" min={0} max={100} value={data.deposit_percent} onChange={e => setData('deposit_percent', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="booking_deposit_amount" text="Booking Deposit ($)" error={errors.booking_deposit_amount}>
          <input id="booking_deposit_amount" type="number" min={0} step="0.01" value={data.booking_deposit_amount} onChange={e => setData('booking_deposit_amount', e.target.value)} style={input} />
        </Field>
        <Field htmlFor="booking_deposit_percent" text="Booking Deposit (%)" error={errors.booking_deposit_percent}>
          <input id="booking_deposit_percent" type="number" min={0} max={100} value={data.booking_deposit_percent} onChange={e => setData('booking_deposit_percent', e.target.value)} style={input} />
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

  const addAction = editing === null ? (
    <button onClick={() => setEditing('new')} style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 16px', background: INK, color: '#F4ECDC', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap' }}>
      + Add Listing
    </button>
  ) : undefined

  return (
    <PortalChrome headTitle={`Listings · ${property.title}`}>

      <BackLink href={`/member/properties/${property.id}`}>← Back to Property</BackLink>

      <PropertyHead property={property} />

      <Section title="Listings" action={addAction}>

        {/* Create / edit form */}
        {editing === 'new' && (
          <ListingForm key="new" property={property} listing={null} listingTypes={listingTypes} statuses={statuses} visibilities={visibilities} onClose={() => setEditing(null)} />
        )}
        {editingListing && (
          <ListingForm key={editingListing.id} property={property} listing={editingListing} listingTypes={listingTypes} statuses={statuses} visibilities={visibilities} onClose={() => setEditing(null)} />
        )}

        {/* Existing listings */}
        {listings.length === 0 && editing === null ? (
          <div style={{ border: '1px dashed #d4c9b0', background: '#fff', padding: '36px 24px', textAlign: 'center' }}>
            <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#6b5e50' }}>
              No listings yet. Add one to put this property in front of hunters.
            </div>
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
            {listings.map(l => (
              <div key={l.id} style={{ border: '1px solid #d4c9b0', background: '#fff', padding: '18px 20px', opacity: editing === l.id ? 0.45 : 1 }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px' }}>
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '8px' }}>
                      <span style={{ fontFamily: 'var(--display)', fontSize: '18px', color: INK }}>
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
                      <span>{money(l.price_per_hunter)}{l.listing_type === 'day_hunt' ? '/hunter/day' : '/hunter'}</span>
                      {l.listing_type === 'day_hunt' && l.price_per_hunter_weekly != null && (
                        <>
                          <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                          <span>{money(l.price_per_hunter_weekly)}/hunter/week</span>
                        </>
                      )}
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
                    {l.listing_type === 'day_hunt' && (
                      <a href={`/member/properties/${property.id}/listings/${l.id}/availability`} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '7px 14px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', cursor: 'pointer', textDecoration: 'none', whiteSpace: 'nowrap', pointerEvents: editing !== null ? 'none' : 'auto', opacity: editing !== null ? 0.5 : 1 }}>
                        Calendar
                      </a>
                    )}
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
      </Section>

    </PortalChrome>
  )
}
