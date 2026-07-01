import { Head, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import MemberTopbar from '@/Components/Member/MemberTopbar'

interface LeaseRow {
  lease_id: string
  property_title: string
  end_date: string | null
  checked_in_at: string | null
  qr_url: string | null
}

interface Props {
  leases: LeaseRow[]
  check_in_url: string
  check_out_url: string
}

interface PageProps {
  flash: { success: string | null; error: string | null }
  [key: string]: unknown
}

const INK = '#0a1512'
const MONO = "'JetBrains Mono', Menlo, monospace"
const DISPLAY = "'Fraunces', Georgia, serif"

function formatTime(iso: string): string {
  try {
    return new Date(iso).toLocaleString(undefined, {
      month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    })
  } catch {
    return iso
  }
}

export default function CheckInIndex({ leases, check_in_url, check_out_url }: Props) {
  const { flash } = usePage<PageProps>().props
  // Per-lease busy state so one card's request never freezes the others.
  const [busyId, setBusyId] = useState<string | null>(null)
  const [locatingId, setLocatingId] = useState<string | null>(null)
  // The lease whose gate QR is being shown in the modal, if any.
  const [qrLease, setQrLease] = useState<LeaseRow | null>(null)

  function withPosition(cb: (coords: { lat: number; lng: number } | null) => void) {
    if (!navigator.geolocation) {
      cb(null)
      return
    }
    navigator.geolocation.getCurrentPosition(
      pos => cb({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      () => cb(null), // advisory only — proceed without GPS
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    )
  }

  function handleCheckIn(leaseId: string) {
    setBusyId(leaseId)
    setLocatingId(leaseId)
    withPosition(coords => {
      setLocatingId(null)
      router.post(check_in_url, {
        lease_id: leaseId,
        lat: coords?.lat ?? null,
        lng: coords?.lng ?? null,
      }, { onFinish: () => setBusyId(null) })
    })
  }

  function handleCheckOut(leaseId: string) {
    setBusyId(leaseId)
    router.post(check_out_url, { lease_id: leaseId }, { onFinish: () => setBusyId(null) })
  }

  return (
    <>
      <Head title="Field Check-In" />
      <div className="topo-bg" style={{ minHeight: '100vh', backgroundColor: '#EDE5D0' }}>
        <MemberTopbar maxWidth={640} />

        <div style={{ maxWidth: '640px', margin: '0 auto', padding: '40px 16px 64px' }}>
          {flash?.success && (
            <div style={{ background: '#f0fdf4', border: '1px solid #86efac', borderRadius: '4px', padding: '12px 16px', marginBottom: '16px', fontSize: '13px', color: '#15803d' }}>
              {flash.success}
            </div>
          )}
          {flash?.error && (
            <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '4px', padding: '12px 16px', marginBottom: '16px', fontSize: '13px', color: '#b91c1c' }}>
              {flash.error}
            </div>
          )}

          <div style={{ marginBottom: '24px' }}>
            <div style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>Field Check-In</div>
            <h1 style={{ fontFamily: DISPLAY, fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>Your Properties</h1>
            <p style={{ fontSize: '13px', color: '#6b5e50', lineHeight: 1.5, margin: '8px 0 0' }}>
              Check in when you arrive and out when you head home — it lets us know you're safely on and off the property. Your location, if shared, is advisory only and never blocks a check-in.
            </p>
          </div>

          {leases.length === 0 ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '32px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: DISPLAY, fontSize: '18px', color: INK, marginBottom: '8px' }}>No active leases</div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: 0 }}>
                You don't have any active leases to check in against. If you're a guest on someone's lease, ask the lease holder to add and approve you first.
              </p>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
              {leases.map(lease => {
                const isOpen = lease.checked_in_at !== null
                const busy = busyId === lease.lease_id
                const locating = locatingId === lease.lease_id
                return (
                  <div key={lease.lease_id} style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', overflow: 'hidden' }}>
                    <div style={{ padding: '18px 24px', borderBottom: '1px solid #f0ece6', background: isOpen ? '#0A1512' : '#fafaf9' }}>
                      <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', fontWeight: 700, color: isOpen ? '#6b9e8f' : '#888' }}>
                        {isOpen ? 'Checked In' : 'Not Checked In'}
                      </div>
                      <div style={{ fontFamily: DISPLAY, fontSize: '20px', color: isOpen ? '#fff' : INK, marginTop: '4px' }}>
                        {lease.property_title}
                      </div>
                      {isOpen && lease.checked_in_at && (
                        <div style={{ fontSize: '12px', color: '#aaa', marginTop: '4px', fontFamily: MONO }}>
                          Since {formatTime(lease.checked_in_at)}
                        </div>
                      )}
                      {!isOpen && lease.end_date && (
                        <div style={{ fontSize: '12px', color: '#a89874', marginTop: '4px', fontFamily: MONO }}>
                          Lease through {lease.end_date}
                        </div>
                      )}
                    </div>

                    <div style={{ padding: '20px 24px', display: 'flex', alignItems: 'center', gap: '14px' }}>
                      {!isOpen ? (
                        <button
                          onClick={() => handleCheckIn(lease.lease_id)}
                          disabled={busy}
                          style={{ flex: 1, padding: '13px', background: '#C84C21', color: '#fff', border: 'none', borderRadius: '3px', fontFamily: MONO, fontSize: '12px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: busy ? 'not-allowed' : 'pointer', opacity: busy ? 0.7 : 1 }}
                        >
                          {locating ? 'Getting Location…' : busy ? 'Checking In…' : 'Check In'}
                        </button>
                      ) : (
                        <button
                          onClick={() => handleCheckOut(lease.lease_id)}
                          disabled={busy}
                          style={{ flex: 1, padding: '13px', background: '#0A1512', color: '#C84C21', border: '1px solid #1a2e28', borderRadius: '3px', fontFamily: MONO, fontSize: '12px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: busy ? 'not-allowed' : 'pointer', opacity: busy ? 0.7 : 1 }}
                        >
                          {busy ? 'Checking Out…' : 'Check Out'}
                        </button>
                      )}
                      {lease.qr_url && (
                        <button
                          onClick={() => setQrLease(lease)}
                          style={{ padding: '13px 16px', background: '#fff', color: INK, border: '1px solid #d4c9b0', borderRadius: '3px', fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: 'pointer', whiteSpace: 'nowrap' }}
                        >
                          Show Gate QR
                        </button>
                      )}
                      <a
                        href={`/member/leases/${lease.lease_id}`}
                        style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.08em', textTransform: 'uppercase', color: '#6b5e50', textDecoration: 'none', whiteSpace: 'nowrap' }}
                      >
                        Lease Details →
                      </a>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        {qrLease && qrLease.qr_url && (
          <div
            onClick={() => setQrLease(null)}
            style={{ position: 'fixed', inset: 0, background: 'rgba(10,21,18,.72)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '24px', zIndex: 50 }}
          >
            <div
              onClick={e => e.stopPropagation()}
              style={{ background: '#fff', border: `1px solid ${'#b8934a'}`, borderRadius: '4px', maxWidth: '360px', width: '100%', overflow: 'hidden' }}
            >
              <div style={{ padding: '18px 24px', background: INK, borderBottom: '1px solid #b8934a' }}>
                <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874' }}>Gate Check-In QR</div>
                <div style={{ fontFamily: DISPLAY, fontSize: '19px', color: '#F4ECDC', marginTop: '4px' }}>{qrLease.property_title}</div>
              </div>
              <div style={{ padding: '24px', textAlign: 'center' }}>
                <img src={qrLease.qr_url} alt={`Gate check-in QR for ${qrLease.property_title}`} style={{ width: '240px', height: '240px', display: 'block', margin: '0 auto' }} />
                <p style={{ fontSize: '12px', color: '#6b5e50', lineHeight: 1.5, margin: '16px 0 0' }}>
                  Post this at the gate. Scanning it opens the check-in page for anyone with an active lease on the property.
                </p>
                <button
                  onClick={() => setQrLease(null)}
                  style={{ marginTop: '18px', padding: '11px 22px', background: '#0A1512', color: '#F4ECDC', border: 'none', borderRadius: '3px', fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: 'pointer' }}
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  )
}
