import { Head, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

interface Props {
  property: { title: string; county: string; state: string } | null
  lease: { id: string; end_date: string } | null
  open_check_in: { checked_in_at: string } | null
  check_in_url: string
  check_out_url: string
}

interface PageProps {
  flash: { success: string | null; error: string | null }
  [key: string]: unknown
}

function formatTime(iso: string): string {
  try {
    return new Date(iso).toLocaleString(undefined, {
      month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    })
  } catch {
    return iso
  }
}

export default function CheckIn({ property, lease, open_check_in, check_in_url, check_out_url }: Props) {
  const { flash } = usePage<PageProps>().props
  const [busy, setBusy] = useState(false)
  const [locating, setLocating] = useState(false)

  function withPosition(cb: (coords: { lat: number; lng: number } | null) => void) {
    if (!navigator.geolocation) {
      cb(null)
      return
    }
    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      pos => { setLocating(false); cb({ lat: pos.coords.latitude, lng: pos.coords.longitude }) },
      () => { setLocating(false); cb(null) }, // advisory only — proceed without GPS
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    )
  }

  function handleCheckIn() {
    if (!lease) return
    setBusy(true)
    withPosition(coords => {
      router.post(check_in_url, {
        lease_id: lease.id,
        lat: coords?.lat ?? null,
        lng: coords?.lng ?? null,
      }, { onFinish: () => setBusy(false) })
    })
  }

  function handleCheckOut() {
    if (!lease) return
    setBusy(true)
    router.post(check_out_url, { lease_id: lease.id }, { onFinish: () => setBusy(false) })
  }

  const isOpen = open_check_in !== null

  return (
    <>
      <Head title="Check In" />

      <div style={{ minHeight: '100vh', background: '#fafaf9' }}>

        {/* Topbar */}
        <div style={{ background: '#0A1512', borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '520px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', fontWeight: 700 }}>
              American Headhunter
            </span>
            <a href="/member" style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}>
              Member Portal
            </a>
          </div>
        </div>

        <div style={{ maxWidth: '520px', margin: '0 auto', padding: '40px 16px 64px' }}>

          {/* Flash */}
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

          {/* Header */}
          <div style={{ marginBottom: '24px' }}>
            <div style={{ fontFamily: 'monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
              Field Check-In
            </div>
            <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '28px', fontWeight: 400, color: '#0A1512', margin: 0, lineHeight: 1.1 }}>
              {property?.title ?? 'Hunting Property'}
            </h1>
            {property && (
              <div style={{ fontSize: '13px', color: '#6b5e50', marginTop: '6px' }}>
                {property.county} County, {property.state}
              </div>
            )}
          </div>

          {!lease && (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '24px', textAlign: 'center' }}>
              <div style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '18px', color: '#0A1512', marginBottom: '8px' }}>
                No active lease here
              </div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: '0 0 16px' }}>
                We couldn't find an active lease for you on this property. If you're a guest on someone's lease, ask the lease holder to add and approve you first.
              </p>
              <a href="/member" style={{ fontFamily: 'monospace', fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#0A1512', textDecoration: 'none', border: '1px solid #d4c9b0', padding: '9px 18px', display: 'inline-block' }}>
                Back to Portal
              </a>
            </div>
          )}

          {lease && (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', overflow: 'hidden' }}>
              <div style={{ padding: '20px 24px', borderBottom: '1px solid #f0ece6', background: isOpen ? '#0A1512' : '#fafaf9' }}>
                <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', fontWeight: 700, color: isOpen ? '#6b9e8f' : '#888' }}>
                  Status
                </div>
                <div style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '22px', color: isOpen ? '#fff' : '#0A1512', marginTop: '4px' }}>
                  {isOpen ? 'Checked In' : 'Not Checked In'}
                </div>
                {isOpen && open_check_in && (
                  <div style={{ fontSize: '12px', color: '#aaa', marginTop: '4px', fontFamily: 'monospace' }}>
                    Since {formatTime(open_check_in.checked_in_at)}
                  </div>
                )}
              </div>

              <div style={{ padding: '24px' }}>
                <p style={{ fontSize: '13px', color: '#6b5e50', lineHeight: 1.5, margin: '0 0 18px' }}>
                  {isOpen
                    ? "Don't forget to check out when you head home — it lets us know you're safely off the property."
                    : 'Checking in logs your arrival. We may use your location to flag the nearest stand and confirm you’re on the property — it never blocks your check-in.'}
                </p>

                {!isOpen ? (
                  <button
                    onClick={handleCheckIn}
                    disabled={busy || locating}
                    style={{ width: '100%', padding: '14px', background: '#C84C21', color: '#fff', border: 'none', borderRadius: '3px', fontFamily: 'monospace', fontSize: '13px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: busy || locating ? 'not-allowed' : 'pointer', opacity: busy || locating ? 0.7 : 1 }}
                  >
                    {locating ? 'Getting Location…' : busy ? 'Checking In…' : 'Check In Now'}
                  </button>
                ) : (
                  <button
                    onClick={handleCheckOut}
                    disabled={busy}
                    style={{ width: '100%', padding: '14px', background: '#0A1512', color: '#C84C21', border: '1px solid #1a2e28', borderRadius: '3px', fontFamily: 'monospace', fontSize: '13px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: busy ? 'not-allowed' : 'pointer', opacity: busy ? 0.7 : 1 }}
                  >
                    {busy ? 'Checking Out…' : 'Check Out'}
                  </button>
                )}

                <a
                  href={`/member/leases/${lease.id}`}
                  style={{ display: 'block', textAlign: 'center', marginTop: '14px', fontFamily: 'monospace', fontSize: '11px', letterSpacing: '.08em', textTransform: 'uppercase', color: '#6b5e50', textDecoration: 'none' }}
                >
                  View Lease Details
                </a>
              </div>
            </div>
          )}

        </div>
      </div>
    </>
  )
}
