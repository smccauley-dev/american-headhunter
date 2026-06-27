import { PortalChrome, PropertyHead, Section, BackLink, InboxStackIcon, INK, TAN, type PropertySummary } from '@/Components/Member/PropertyChrome'

interface AppRow {
  id: string
  ref: string
  status: string
  status_label: string
  applicant_name: string
  type: string
  listing_title: string
  hunters: number
  submitted_at: string | null
  has_lease: boolean
}

interface Props {
  property: PropertySummary & { id: string }
  applications: AppRow[]
}

const STATUS_COLOR: Record<string, string> = {
  pending: '#b05a00',
  under_review: '#3d6b8e',
  approved: '#15803d',
  rejected: '#b91c1c',
  withdrawn: '#9c9388',
  expired: '#9c9388',
}

function StatusPill({ status, label }: { status: string; label: string }) {
  const color = STATUS_COLOR[status] ?? INK
  return (
    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', border: `1px solid ${color}`, color }}>
      {label}
    </span>
  )
}

export default function ApplicationsIndex({ property, applications }: Props) {
  return (
    <PortalChrome headTitle={`Lease Applications · ${property.title}`}>

      <BackLink href={`/member/properties/${property.id}`}>← Back to Property</BackLink>

      <PropertyHead property={property} />

      <Section title="Lease Applications" icon={<InboxStackIcon />} description="Applications submitted for this property's listings. Open one to review the applicant, message them, and approve or reject.">

        {applications.length === 0 ? (
          <div style={{ border: '1px dashed #d4c9b0', background: '#fff', padding: '36px 24px', textAlign: 'center' }}>
            <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#6b5e50' }}>
              No applications yet. When a hunter applies to one of this property's listings, it will appear here.
            </div>
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            {applications.map(a => (
              <a
                key={a.id}
                href={`/member/properties/${property.id}/applications/${a.id}`}
                style={{ display: 'block', border: '1px solid #d4c9b0', background: '#fff', padding: '16px 20px', textDecoration: 'none' }}
              >
                <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px' }}>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap', marginBottom: '8px' }}>
                      <span style={{ fontFamily: 'var(--display)', fontSize: '18px', color: INK }}>{a.applicant_name}</span>
                      <StatusPill status={a.status} label={a.status_label} />
                      {a.has_lease && (
                        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', background: INK, color: '#F4ECDC' }}>
                          Lease created
                        </span>
                      )}
                    </div>
                    <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#6b5e50', lineHeight: 1.7 }}>
                      <span>{a.ref}</span>
                      <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                      <span>{a.type}</span>
                      <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                      <span>{a.hunters} hunter{a.hunters === 1 ? '' : 's'}</span>
                      {a.submitted_at && (
                        <>
                          <span style={{ color: '#d4c9b0', margin: '0 8px' }}>·</span>
                          <span>Submitted {a.submitted_at}</span>
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
