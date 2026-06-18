import { useForm } from '@inertiajs/react'
import { TrashIcon } from '@heroicons/react/24/outline'
import { PortalChrome, PropertyHead, Section, BackLink, INK, ACCENT, type PropertySummary } from '@/Components/Member/PropertyChrome'

type DayStatus = 'available' | 'booked' | 'blocked' | 'maintenance' | 'out' | 'pad'

interface Cell { day: number | null; status: DayStatus; title: string | null }
interface Month { label: string; weeks: Cell[][] }

interface Calendar {
  season_start: string | null
  season_end: string | null
  months: Month[]
  totals: { available: number; booked: number; blocked: number; maintenance: number }
}

interface Blackout { date_start: string; date_end: string; reason: 'blocked' | 'maintenance' }
interface Booking { date_start: string; date_end: string; cost: number | null; hunter_count: number | null; lease_id: string | null }

interface Listing {
  id: string
  listing_type: string
  price_per_hunter: number | null
  price_per_hunter_weekly: number | null
}

interface Props {
  property: PropertySummary & { id: string }
  listing: Listing
  calendar: Calendar
  blackouts: Blackout[]
  bookings: Booking[]
}

const COLORS: Record<string, { bg: string; fg: string; bd: string }> = {
  available:   { bg: '#e7f1ea', fg: '#0a1512', bd: '#bcd6c6' },
  booked:      { bg: '#c84c21', fg: '#ffffff', bd: '#a83c16' },
  blocked:     { bg: '#3a3a3a', fg: '#ffffff', bd: '#2a2a2a' },
  maintenance: { bg: '#d9a521', fg: '#0a1512', bd: '#b88a16' },
  out:         { bg: 'transparent', fg: '#9aa3a0', bd: 'transparent' },
  pad:         { bg: 'transparent', fg: 'transparent', bd: 'transparent' },
}

const DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S']
const mono = 'JetBrains Mono, monospace'

function money(v: number | null): string {
  if (v === null) return '—'
  return '$' + v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

function LegendChip({ status, label, count }: { status: DayStatus; label: string; count?: number }) {
  const c = COLORS[status]
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: mono, fontSize: '11px', color: '#6b5e50' }}>
      <span style={{ width: 14, height: 14, borderRadius: 3, background: c.bg, border: `1px solid ${c.bd}` }} />
      {label}{count != null ? ` (${count})` : ''}
    </span>
  )
}

function MonthGrid({ month }: { month: Month }) {
  return (
    <div>
      <div style={{ fontFamily: 'var(--display)', fontSize: '15px', color: INK, marginBottom: '8px' }}>{month.label}</div>
      <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
        <thead>
          <tr>{DOW.map((d, i) => <th key={i} style={{ padding: '4px 0', fontFamily: mono, fontSize: '10px', color: '#9aa3a0', fontWeight: 500 }}>{d}</th>)}</tr>
        </thead>
        <tbody>
          {month.weeks.map((week, wi) => (
            <tr key={wi}>
              {week.map((cell, ci) => {
                const c = COLORS[cell.status] ?? COLORS.out
                return (
                  <td key={ci} style={{ padding: '2px' }}>
                    {cell.day != null && (
                      <div title={cell.title ?? undefined} style={{ height: 28, lineHeight: '28px', textAlign: 'center', borderRadius: 4, fontFamily: mono, fontSize: '11px', background: c.bg, color: c.fg, border: `1px solid ${c.bd}` }}>
                        {cell.day}
                      </div>
                    )}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

const label: React.CSSProperties = {
  display: 'block', fontFamily: mono, fontSize: '10px', fontWeight: 600,
  letterSpacing: '.12em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px',
}
const input: React.CSSProperties = {
  width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK,
  background: '#fff', border: '1px solid #d4c9b0', padding: '9px 11px', outline: 'none', boxSizing: 'border-box',
}
const btn = (variant: 'ink' | 'ghost' | 'danger'): React.CSSProperties => ({
  fontFamily: mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase',
  padding: '9px 18px', cursor: 'pointer',
  background: variant === 'ink' ? INK : 'transparent',
  color: variant === 'ink' ? '#F4ECDC' : variant === 'danger' ? ACCENT : INK,
  border: variant === 'ink' ? 'none' : variant === 'danger' ? '1px solid rgba(200,76,33,0.4)' : '1px solid #d4c9b0',
})

function BlackoutEditor({ property, listing, blackouts }: { property: { id: string }; listing: Listing; blackouts: Blackout[] }) {
  const { data, setData, put, processing, errors } = useForm<{ blackouts: Blackout[] }>({
    blackouts: blackouts.map(b => ({ ...b })),
  })

  function addRow() {
    setData('blackouts', [...data.blackouts, { date_start: '', date_end: '', reason: 'blocked' }])
  }
  function removeRow(i: number) {
    setData('blackouts', data.blackouts.filter((_, idx) => idx !== i))
  }
  function update(i: number, key: keyof Blackout, value: string) {
    setData('blackouts', data.blackouts.map((r, idx) => idx === i ? { ...r, [key]: value } : r))
  }
  function save(e: React.FormEvent) {
    e.preventDefault()
    put(`/member/properties/${property.id}/listings/${listing.id}/availability`, { preserveScroll: true })
  }

  return (
    <form onSubmit={save} style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
      {errors.blackouts && (
        <div style={{ fontFamily: mono, fontSize: '11px', color: ACCENT, border: '1px solid rgba(200,76,33,0.4)', background: 'rgba(200,76,33,0.05)', padding: '10px 12px' }}>
          {errors.blackouts}
        </div>
      )}

      {data.blackouts.length === 0 && (
        <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50' }}>
          No blackout dates. Add a range to close dates that can't be booked (e.g. owner use or maintenance).
        </div>
      )}

      {data.blackouts.map((r, i) => (
        <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: '12px', alignItems: 'end' }}>
          <div>
            <label style={label}>From</label>
            <input type="date" value={r.date_start} onChange={e => update(i, 'date_start', e.target.value)} style={input} />
          </div>
          <div>
            <label style={label}>To</label>
            <input type="date" value={r.date_end} onChange={e => update(i, 'date_end', e.target.value)} style={input} />
          </div>
          <div>
            <label style={label}>Reason</label>
            <select value={r.reason} onChange={e => update(i, 'reason', e.target.value)} style={input}>
              <option value="blocked">Blocked</option>
              <option value="maintenance">Maintenance</option>
            </select>
          </div>
          <button type="button" onClick={() => removeRow(i)} style={{ ...btn('danger'), padding: '9px 14px', display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <TrashIcon style={{ width: 14, height: 14 }} />
            Remove
          </button>
        </div>
      ))}

      <div style={{ display: 'flex', gap: '10px', borderTop: '1px solid #e5ddd0', paddingTop: '16px' }}>
        <button type="button" onClick={addRow} style={btn('ghost')}>+ Add Blackout</button>
        <button type="submit" disabled={processing} style={{ ...btn('ink'), opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Saving…' : 'Save Blackouts'}
        </button>
      </div>
    </form>
  )
}

export default function PropertyAvailability({ property, listing, calendar, blackouts, bookings }: Props) {
  const hasSeason = calendar.months.length > 0

  return (
    <PortalChrome headTitle={`Availability · ${property.title}`}>

      <BackLink href={`/member/properties/${property.id}/listings`}>← Back to Listings</BackLink>

      <PropertyHead property={property} />

      {hasSeason && (
        <Section title="Booked Dates" description="Lease-reserved dates with the agreed cost. Managed automatically — cancel or terminate the lease to free them.">
          {bookings.length === 0 ? (
            <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50' }}>
              No bookings yet. Activated day-hunt leases will appear here.
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
              {bookings.map((b, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', border: '1px solid #d4c9b0', background: '#fff', padding: '12px 16px', fontFamily: mono, fontSize: '12px', color: '#6b5e50' }}>
                  <span style={{ color: INK }}>{b.date_start} → {b.date_end}</span>
                  <span>
                    {b.hunter_count != null ? `${b.hunter_count} hunter${b.hunter_count === 1 ? '' : 's'}` : '—'}
                    <span style={{ color: '#d4c9b0', margin: '0 10px' }}>·</span>
                    <span style={{ color: ACCENT, fontWeight: 700 }}>{money(b.cost)}</span>
                  </span>
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      <Section
        title="Day-Hunt Calendar"
        description={hasSeason
          ? <>Season {calendar.season_start} – {calendar.season_end}. Booked dates come from activated leases and free up automatically when a lease is cancelled or terminated.</>
          : undefined}
        action={
          <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap' }}>
            <span style={{ fontFamily: mono, fontSize: '11px', color: '#6b5e50' }}>{money(listing.price_per_hunter)}/hunter/day</span>
            {listing.price_per_hunter_weekly != null && (
              <span style={{ fontFamily: mono, fontSize: '11px', color: '#6b5e50' }}>{money(listing.price_per_hunter_weekly)}/hunter/week</span>
            )}
          </div>
        }
      >
        {!hasSeason ? (
          <div style={{ border: '1px dashed #d4c9b0', background: '#fff', padding: '36px 24px', textAlign: 'center', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#6b5e50' }}>
            This listing has no season dates set, so there is no calendar to manage. Set a season start and end on the listing first.
          </div>
        ) : (
          <>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '16px', marginBottom: '20px' }}>
              <LegendChip status="available" label="Available" count={calendar.totals.available} />
              <LegendChip status="booked" label="Booked" count={calendar.totals.booked} />
              <LegendChip status="blocked" label="Blocked" count={calendar.totals.blocked} />
              <LegendChip status="maintenance" label="Maintenance" count={calendar.totals.maintenance} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: '24px' }}>
              {calendar.months.map((m, i) => <MonthGrid key={i} month={m} />)}
            </div>
          </>
        )}
      </Section>

      {hasSeason && (
        <Section title="Blackout Dates" description="Close dates so they can't be booked. Ranges cannot overlap a booking or each other.">
          <BlackoutEditor property={property} listing={listing} blackouts={blackouts} />
        </Section>
      )}

    </PortalChrome>
  )
}
