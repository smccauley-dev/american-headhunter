import { Head } from '@inertiajs/react'

interface Signer {
  name: string
  role: string
  status: string
  signed_at: string | null
}

interface AccessInfo {
  gate_code?: string
  cabin_code?: string
  wifi_ssid?: string
  wifi_password?: string
  directions?: string
}

interface Props {
  lease: {
    id: string
    status: string
    start_date: string
    end_date: string
    total_price: string
    auto_renew: boolean
  }
  property: {
    id: string
    title: string
    county: string
    state: string
    acres: string | number
    rules: string[]
  } | null
  access_info: AccessInfo | null
  signers: Signer[]
  sign_url: string | null
}

const STATUS_LABEL: Record<string, string> = {
  active: 'Active',
  pending_signatures: 'Awaiting Signatures',
  expired: 'Expired',
  terminated: 'Terminated',
  cancelled: 'Cancelled',
}

const STATUS_COLOR: Record<string, string> = {
  active: '#15803d',
  pending_signatures: '#c2410c',
  expired: '#6b7280',
  terminated: '#6b7280',
  cancelled: '#6b7280',
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', marginBottom: '16px', overflow: 'hidden' }}>
      <div style={{ padding: '12px 20px', borderBottom: '1px solid #f0ece6', background: '#fafaf9' }}>
        <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#888', fontWeight: '700' }}>
          {title}
        </span>
      </div>
      <div style={{ padding: '16px 20px' }}>
        {children}
      </div>
    </div>
  )
}

function AccessField({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ marginBottom: '14px' }}>
      <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#888', marginBottom: '4px' }}>
        {label}
      </div>
      <div style={{ fontFamily: 'monospace', fontSize: '16px', fontWeight: '700', color: '#0A1512', letterSpacing: '.05em', background: '#f5f3ef', padding: '8px 12px', borderRadius: '3px', border: '1px solid #e5e0d8', display: 'inline-block', minWidth: '120px' }}>
        {value}
      </div>
    </div>
  )
}

export default function Lease({ lease, property, access_info, signers, sign_url }: Props) {
  const statusColor = STATUS_COLOR[lease.status] ?? '#6b7280'
  const statusLabel = STATUS_LABEL[lease.status] ?? lease.status
  const allSigned   = signers.every(s => s.status === 'signed')

  return (
    <>
      <Head title={property?.title ?? 'Lease Detail'} />

      <div style={{ minHeight: '100vh', background: '#fafaf9' }}>

        {/* Topbar */}
        <div style={{ background: '#0A1512', borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '800px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', fontWeight: '700' }}>
              American Headhunter
            </span>
            <a
              href="/member"
              style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}
            >
              ← My Leases
            </a>
          </div>
        </div>

        <div style={{ maxWidth: '800px', margin: '0 auto', padding: '40px 16px 64px' }}>

          {/* Header */}
          <div style={{ background: '#0A1512', borderRadius: '4px', padding: '24px', marginBottom: '20px' }}>
            <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#C84C21', marginBottom: '6px' }}>
              Hunting Lease
            </div>
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '24px', fontWeight: '400', color: '#fff', margin: '0 0 4px' }}>
              {property?.title ?? 'Hunting Property'}
            </h1>
            {property && (
              <div style={{ fontSize: '13px', color: '#aaa', marginBottom: '12px' }}>
                {property.county} County, {property.state}
                {property.acres ? ` · ${Number(property.acres).toLocaleString()} acres` : ''}
              </div>
            )}
            <span style={{
              display: 'inline-block',
              padding: '4px 10px',
              borderRadius: '20px',
              background: 'rgba(255,255,255,0.08)',
              border: '1px solid rgba(255,255,255,0.12)',
              fontFamily: 'monospace',
              fontSize: '10px',
              fontWeight: '700',
              letterSpacing: '.08em',
              textTransform: 'uppercase',
              color: statusColor,
            }}>
              {statusLabel}
            </span>
          </div>

          {/* Sign CTA — only when pending and user hasn't signed */}
          {sign_url && (
            <div style={{ background: '#fff7ed', border: '1px solid #fed7aa', borderRadius: '4px', padding: '16px 20px', marginBottom: '16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div>
                <div style={{ fontSize: '14px', fontWeight: '600', color: '#c2410c', marginBottom: '2px' }}>Your signature is required</div>
                <div style={{ fontSize: '13px', color: '#9a3412' }}>Sign the lease agreement to activate your access.</div>
              </div>
              <a
                href={sign_url}
                style={{ fontFamily: 'monospace', fontSize: '11px', fontWeight: '700', letterSpacing: '.08em', textTransform: 'uppercase', background: '#C84C21', color: '#fff', padding: '10px 18px', borderRadius: '3px', textDecoration: 'none', whiteSpace: 'nowrap', marginLeft: '16px' }}
              >
                Sign Now
              </a>
            </div>
          )}

          {/* Lease Terms */}
          <Section title="Lease Terms">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px' }}>
              <div>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '4px' }}>Start Date</div>
                <div style={{ fontSize: '14px', fontWeight: '600', color: '#0A1512' }}>{lease.start_date}</div>
              </div>
              <div>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '4px' }}>End Date</div>
                <div style={{ fontSize: '14px', fontWeight: '600', color: '#0A1512' }}>{lease.end_date}</div>
              </div>
              <div>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '4px' }}>Total Price</div>
                <div style={{ fontSize: '14px', fontWeight: '600', color: '#0A1512' }}>${lease.total_price}</div>
              </div>
              <div>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '4px' }}>Auto-Renew</div>
                <div style={{ fontSize: '14px', fontWeight: '600', color: '#0A1512' }}>{lease.auto_renew ? 'Enabled' : 'Disabled'}</div>
              </div>
            </div>
          </Section>

          {/* Signing Status — shown when pending or not all signed */}
          {signers.length > 0 && (lease.status === 'pending_signatures' || !allSigned) && (
            <Section title="Signatures">
              {signers.map((signer, i) => {
                const isSigned = signer.status === 'signed'
                return (
                  <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 0', borderBottom: i < signers.length - 1 ? '1px solid #f0ece6' : 'none' }}>
                    <div style={{ width: '28px', height: '28px', borderRadius: '50%', background: isSigned ? '#f0fdf4' : '#fff7ed', border: `2px solid ${isSigned ? '#15803d' : '#d97706'}`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                      <span style={{ fontSize: '14px', color: isSigned ? '#15803d' : '#d97706', fontWeight: '700' }}>
                        {isSigned ? '✓' : '○'}
                      </span>
                    </div>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontSize: '13px', fontWeight: '600', color: '#1a1a1a' }}>{signer.name}</div>
                      <div style={{ fontSize: '11px', color: '#888', fontFamily: 'monospace' }}>{signer.role}</div>
                    </div>
                    <div style={{ fontSize: '11px', fontFamily: 'monospace', fontWeight: '700', textTransform: 'uppercase', letterSpacing: '.06em', color: isSigned ? '#15803d' : '#d97706' }}>
                      {isSigned ? 'Signed' : 'Pending'}
                    </div>
                  </div>
                )
              })}
            </Section>
          )}

          {/* Access Info — active leases only */}
          {access_info && Object.keys(access_info).length > 0 && (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', marginBottom: '16px', overflow: 'hidden' }}>
              <div style={{ padding: '12px 20px', borderBottom: '1px solid #f0ece6', background: '#0A1512', display: 'flex', alignItems: 'center', gap: '8px' }}>
                <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#C84C21', fontWeight: '700' }}>
                  Property Access
                </span>
                <span style={{ fontFamily: 'monospace', fontSize: '10px', color: '#6b9e8f' }}>· Keep confidential</span>
              </div>
              <div style={{ padding: '20px' }}>
                {access_info.gate_code && <AccessField label="Gate Code" value={access_info.gate_code} />}
                {access_info.cabin_code && <AccessField label="Cabin Code" value={access_info.cabin_code} />}
                {access_info.wifi_ssid && <AccessField label="WiFi Network" value={access_info.wifi_ssid} />}
                {access_info.wifi_password && <AccessField label="WiFi Password" value={access_info.wifi_password} />}
                {access_info.directions && (
                  <div>
                    <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#888', marginBottom: '6px' }}>Directions</div>
                    <div style={{ fontSize: '13px', color: '#333', lineHeight: '1.6', background: '#f5f3ef', padding: '12px', borderRadius: '3px', border: '1px solid #e5e0d8' }}>
                      {access_info.directions}
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Property Rules */}
          {(property?.rules?.length ?? 0) > 0 && (
            <Section title="Property Rules">
              <ul style={{ margin: 0, padding: 0, listStyle: 'none' }}>
                {property!.rules.map((rule, i) => (
                  <li key={i} style={{ display: 'flex', gap: '10px', padding: '8px 0', borderBottom: i < property!.rules.length - 1 ? '1px solid #f5f3ef' : 'none', fontSize: '13px', color: '#333', lineHeight: '1.5' }}>
                    <span style={{ color: '#C84C21', fontWeight: '700', flexShrink: 0 }}>·</span>
                    {rule}
                  </li>
                ))}
              </ul>
            </Section>
          )}

        </div>
      </div>
    </>
  )
}
