import { PortalChrome, PropertyHead, Section, BackLink, DocumentTextIcon, INK, TAN, type PropertySummary } from '@/Components/Member/PropertyChrome'

interface LeaseRow {
  id: string
  ref: string
  status: string
  status_label: string
  lessee_name: string
  start_date: string | null
  end_date: string | null
  total_price: number | null
  created_at: string | null
  terminated_at: string | null
}

interface Props {
  property: PropertySummary & { id: string }
  leases: LeaseRow[]
}

const STATUS_COLOR: Record<string, string> = {
  active: '#6b7856',             // sage — healthy
  pending_signatures: '#b8934a', // gold — in progress
  pending_payment: '#b8934a',
  expired: '#a89874',            // tan — aged out
  terminated: '#8a3216',         // clay — ended early
  cancelled: '#722814',          // deep rust
}

function StatusBadge({ status, label }: { status: string; label: string }) {
  const bg = STATUS_COLOR[status] ?? INK
  return (
    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', background: bg, color: '#F4ECDC' }}>
      {label}
    </span>
  )
}

function money(dollars: number | null): string {
  if (dollars === null) return '—'
  return '$' + dollars.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export default function LeasesIndex({ property, leases }: Props) {
  return (
    <PortalChrome headTitle={`Leases · ${property.title}`}>

      <BackLink href={`/member/properties/${property.id}`}>← Back to Property</BackLink>

      <PropertyHead property={property} />

      <Section title="Leases" icon={<DocumentTextIcon />} description="Every lease ever written against this property — past and current. Open one to see its full record, parties, documents and payment history.">

        {leases.length === 0 ? (
          <div style={{ border: '1px dashed #d4c9b0', background: '#fff', padding: '36px 24px', textAlign: 'center' }}>
            <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#6b5e50' }}>
              No leases yet. When an approved applicant pays the booking fee, the lease that's created will appear here.
            </div>
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            {leases.map(l => (
              <a
                key={l.id}
                href={`/member/leases/${l.id}`}
                style={{ display: 'block', border: '1px solid #d4c9b0', background: '#fff', padding: '16px 20px', textDecoration: 'none' }}
              >
                <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px' }}>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap', marginBottom: '8px' }}>
                      <span style={{ fontFamily: 'var(--display)', fontSize: '18px', color: INK }}>{l.lessee_name}</span>
                      <StatusBadge status={l.status} label={l.status_label} />
                    </div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#6b5e50', lineHeight: 1.7 }}>
                      <span>{l.ref}</span>
                      {(l.start_date || l.end_date) && (
                        <>
                          <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                          <span>{l.start_date ?? '—'} – {l.end_date ?? '—'}</span>
                        </>
                      )}
                      <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                      <span>{money(l.total_price)}</span>
                      {l.terminated_at && (
                        <>
                          <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                          <span>Ended {l.terminated_at}</span>
                        </>
                      )}
                    </div>
                  </div>
                  <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, flexShrink: 0, whiteSpace: 'nowrap' }}>
                    View →
                  </span>
                </div>
              </a>
            ))}
          </div>
        )}
      </Section>

    </PortalChrome>
  )
}
