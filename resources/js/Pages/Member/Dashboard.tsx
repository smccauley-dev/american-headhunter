import { Head, router } from '@inertiajs/react'

interface Property {
  id: string
  title: string
  county: string
  state: string
  acres: string | number
}

interface LeaseItem {
  id: string
  status: 'active' | 'pending_signatures'
  start_date: string
  end_date: string
  total_price: string
  days_until_expiry: number | null
  property: Property | null
}

interface Props {
  leases: LeaseItem[]
}

const STATUS_LABEL: Record<string, string> = {
  active: 'Active',
  pending_signatures: 'Awaiting Signatures',
}

const STATUS_COLOR: Record<string, { bg: string; color: string; border: string }> = {
  active: { bg: '#f0fdf4', color: '#15803d', border: '#bbf7d0' },
  pending_signatures: { bg: '#fff7ed', color: '#c2410c', border: '#fed7aa' },
}

function ExpiryBadge({ days }: { days: number }) {
  const urgent = days <= 30
  return (
    <span style={{
      fontFamily: 'monospace',
      fontSize: '11px',
      fontWeight: '700',
      letterSpacing: '.06em',
      textTransform: 'uppercase' as const,
      color: urgent ? '#b91c1c' : '#888',
    }}>
      {days === 0 ? 'Expires today' : `${days}d remaining`}
    </span>
  )
}

export default function Dashboard({ leases }: Props) {
  function handleSignOut() {
    router.post('/logout')
  }

  const activeLeases  = leases.filter(l => l.status === 'active')
  const pendingLeases = leases.filter(l => l.status === 'pending_signatures')

  return (
    <>
      <Head title="Member Portal" />

      <div style={{ minHeight: '100vh', background: '#fafaf9' }}>

        {/* Topbar */}
        <div style={{ background: '#0A1512', borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '900px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', fontWeight: '700' }}>
                American Headhunter
              </span>
              <span style={{ color: '#3a5a50', fontSize: '12px' }}>·</span>
              <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                Member Portal
              </span>
            </div>
            <button
              onClick={handleSignOut}
              style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'none', border: 'none', cursor: 'pointer', padding: '4px 0' }}
            >
              Sign Out
            </button>
          </div>
        </div>

        <div style={{ maxWidth: '900px', margin: '0 auto', padding: '40px 16px 64px' }}>

          {/* Page heading */}
          <div style={{ marginBottom: '36px' }}>
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '28px', fontWeight: '400', color: '#0A1512', margin: '0 0 4px' }}>
              My Leases
            </h1>
            <p style={{ fontFamily: 'monospace', fontSize: '11px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#aaa', margin: 0 }}>
              {leases.length === 0
                ? 'No active leases'
                : `${leases.length} lease${leases.length !== 1 ? 's' : ''}`}
            </p>
          </div>

          {/* Empty state */}
          {leases.length === 0 && (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '48px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: 'monospace', fontSize: '11px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#C84C21', marginBottom: '12px' }}>
                No Leases
              </div>
              <p style={{ fontSize: '15px', color: '#444', margin: '0 0 24px' }}>
                You don't have any active or pending hunting leases.
              </p>
              <a
                href="/properties"
                style={{ fontFamily: 'monospace', fontSize: '11px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#C84C21', textDecoration: 'none', borderBottom: '1px solid #C84C21', paddingBottom: '2px' }}
              >
                Browse Properties
              </a>
            </div>
          )}

          {/* Pending signatures section */}
          {pendingLeases.length > 0 && (
            <div style={{ marginBottom: '32px' }}>
              <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#c2410c', marginBottom: '12px', fontWeight: '700' }}>
                Action Required — Awaiting Your Signature
              </div>
              {pendingLeases.map(lease => (
                <LeaseCard key={lease.id} lease={lease} />
              ))}
            </div>
          )}

          {/* Active leases section */}
          {activeLeases.length > 0 && (
            <div>
              {pendingLeases.length > 0 && (
                <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#888', marginBottom: '12px' }}>
                  Active Leases
                </div>
              )}
              {activeLeases.map(lease => (
                <LeaseCard key={lease.id} lease={lease} />
              ))}
            </div>
          )}

        </div>
      </div>
    </>
  )
}

function LeaseCard({ lease }: { lease: LeaseItem }) {
  const statusStyle = STATUS_COLOR[lease.status] ?? STATUS_COLOR.active
  const isPending   = lease.status === 'pending_signatures'

  return (
    <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', marginBottom: '12px', overflow: 'hidden' }}>
      {/* Card header */}
      <div style={{ background: '#0A1512', padding: '14px 20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#C84C21', marginBottom: '2px' }}>
            Hunting Lease
          </div>
          <div style={{ fontSize: '16px', fontWeight: '700', color: '#fff' }}>
            {lease.property?.title ?? 'Hunting Property'}
          </div>
          {lease.property && (
            <div style={{ fontSize: '12px', color: '#aaa', marginTop: '2px' }}>
              {lease.property.county} County, {lease.property.state}
              {lease.property.acres ? ` · ${Number(lease.property.acres).toLocaleString()} acres` : ''}
            </div>
          )}
        </div>
        <div style={{
          padding: '4px 10px',
          borderRadius: '20px',
          background: statusStyle.bg,
          border: `1px solid ${statusStyle.border}`,
          fontFamily: 'monospace',
          fontSize: '10px',
          fontWeight: '700',
          letterSpacing: '.08em',
          textTransform: 'uppercase',
          color: statusStyle.color,
          whiteSpace: 'nowrap',
        }}>
          {STATUS_LABEL[lease.status] ?? lease.status}
        </div>
      </div>

      {/* Card body */}
      <div style={{ padding: '16px 20px' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '16px', marginBottom: '16px' }}>
          <div>
            <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '3px' }}>Start</div>
            <div style={{ fontSize: '13px', fontWeight: '600', color: '#0A1512' }}>{lease.start_date}</div>
          </div>
          <div>
            <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '3px' }}>End</div>
            <div style={{ fontSize: '13px', fontWeight: '600', color: '#0A1512' }}>{lease.end_date}</div>
          </div>
          <div>
            <div style={{ fontFamily: 'monospace', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#aaa', marginBottom: '3px' }}>Total</div>
            <div style={{ fontSize: '13px', fontWeight: '600', color: '#0A1512' }}>${lease.total_price}</div>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div>
            {lease.status === 'active' && lease.days_until_expiry !== null && (
              <ExpiryBadge days={lease.days_until_expiry} />
            )}
            {isPending && (
              <span style={{ fontFamily: 'monospace', fontSize: '11px', color: '#c2410c' }}>
                Sign to activate your lease
              </span>
            )}
          </div>

          <div style={{ display: 'flex', gap: '8px' }}>
            {isPending && (
              <a
                href={`/member/leases/${lease.id}/sign`}
                style={{
                  fontFamily: 'monospace', fontSize: '11px', fontWeight: '700',
                  letterSpacing: '.08em', textTransform: 'uppercase',
                  background: '#C84C21', color: '#fff',
                  padding: '8px 16px', borderRadius: '3px', textDecoration: 'none',
                }}
              >
                Sign Now
              </a>
            )}
            <a
              href={`/member/leases/${lease.id}`}
              style={{
                fontFamily: 'monospace', fontSize: '11px', fontWeight: '700',
                letterSpacing: '.08em', textTransform: 'uppercase',
                background: 'none', color: '#0A1512',
                padding: '8px 16px', borderRadius: '3px', textDecoration: 'none',
                border: '1px solid #d1cfc8',
              }}
            >
              View Lease
            </a>
          </div>
        </div>
      </div>
    </div>
  )
}
