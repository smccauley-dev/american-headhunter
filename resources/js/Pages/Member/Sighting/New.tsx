import { Head, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { useOfflineSubmit } from '@/offline/useOfflineSubmit'
import { useOnline } from '@/offline/useOnline'

interface Option { value: string; label: string }
interface LeaseOption { id: string; property_title: string; end_date: string | null }

interface Props {
  leases: LeaseOption[]
  species: Option[]
  store_url: string
  index_url: string
}

const INK = '#0a1512'
const BLAZE = '#c84c21'
const MONO = "'JetBrains Mono', Menlo, monospace"
const DISPLAY = "'Fraunces', Georgia, serif"

const labelStyle: React.CSSProperties = { fontFamily: MONO, fontSize: '10px', letterSpacing: '.12em', textTransform: 'uppercase', color: '#6b5e50', fontWeight: 700, display: 'block', marginBottom: '6px' }
const inputStyle: React.CSSProperties = { width: '100%', padding: '11px 12px', border: '1px solid #d4c9b0', borderRadius: '3px', fontFamily: MONO, fontSize: '13px', color: INK, background: '#fff', boxSizing: 'border-box' }
const errStyle: React.CSSProperties = { fontFamily: MONO, fontSize: '11px', color: '#b91c1c', marginTop: '4px' }

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export default function SightingNew({ leases, species, store_url, index_url }: Props) {
  const [locating, setLocating] = useState(false)
  const [located, setLocated] = useState(false)

  const { data, setData, post, processing, errors } = useForm({
    lease_id: leases[0]?.id ?? '',
    species_code: '',
    sighting_date: today(),
    sighting_time: '',
    count: '1',
    notes: '',
    latitude: null as number | null,
    longitude: null as number | null,
    gps_accuracy_m: null as number | null,
  })

  function captureGps() {
    if (!navigator.geolocation) return
    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      pos => {
        setData(d => ({ ...d, latitude: pos.coords.latitude, longitude: pos.coords.longitude, gps_accuracy_m: Math.round(pos.coords.accuracy) }))
        setLocating(false)
        setLocated(true)
      },
      () => { setLocating(false) }, // advisory — logging works without a fix
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    )
  }

  const { queue } = useOfflineSubmit('sighting', index_url)
  const online = useOnline()

  function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!navigator.onLine) {
      const label = species.find(s => s.value === data.species_code)?.label ?? 'Sighting'
      void queue(store_url, { ...data }, label)
      return
    }
    post(store_url)
  }

  return (
    <>
      <Head title="Log Sighting" />
      <div style={{ minHeight: '100vh', background: '#faf7f2' }}>
        <div style={{ background: INK, borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '560px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: BLAZE, fontWeight: 700 }}>American Headhunter</span>
            <a href={index_url} style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}>Sightings</a>
          </div>
        </div>

        <div style={{ maxWidth: '560px', margin: '0 auto', padding: '40px 16px 64px' }}>
          <div style={{ marginBottom: '24px' }}>
            <div style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>Field Log</div>
            <h1 style={{ fontFamily: DISPLAY, fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>Log a Sighting</h1>
          </div>

          {leases.length === 0 ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '28px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: DISPLAY, fontSize: '18px', color: INK, marginBottom: '8px' }}>No active lease</div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: '0 0 16px' }}>
                You need an active lease to log a sighting. If you're a guest on someone's lease, ask the lease holder to add and approve you.
              </p>
              <a href="/member" style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: INK, textDecoration: 'none', border: '1px solid #d4c9b0', padding: '10px 18px', display: 'inline-block' }}>Back to Portal</a>
            </div>
          ) : (
            <form onSubmit={submit} style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '18px' }}>

              {!online && (
                <div style={{ background: '#fef6ee', border: '1px solid #f0c9a8', borderRadius: '3px', padding: '11px 14px', fontFamily: MONO, fontSize: '11px', color: '#9a4a1e', lineHeight: 1.5 }}>
                  You're offline. This sighting will be saved on your device and synced automatically when you're back on signal.
                </div>
              )}

              <div>
                <label style={labelStyle}>Lease / Property</label>
                <select value={data.lease_id} onChange={e => setData('lease_id', e.target.value)} style={inputStyle}>
                  {leases.map(l => <option key={l.id} value={l.id}>{l.property_title}</option>)}
                </select>
                {errors.lease_id && <div style={errStyle}>{errors.lease_id}</div>}
              </div>

              <div style={{ display: 'flex', gap: '14px', flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 200px' }}>
                  <label style={labelStyle}>Species</label>
                  <select value={data.species_code} onChange={e => setData('species_code', e.target.value)} style={inputStyle}>
                    <option value="">Select…</option>
                    {species.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
                  </select>
                  {errors.species_code && <div style={errStyle}>{errors.species_code}</div>}
                </div>
                <div style={{ flex: '0 1 120px' }}>
                  <label style={labelStyle}>Count</label>
                  <input type="number" min="1" max="10000" value={data.count} onChange={e => setData('count', e.target.value)} style={inputStyle} />
                  {errors.count && <div style={errStyle}>{errors.count}</div>}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '14px', flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 160px' }}>
                  <label style={labelStyle}>Date</label>
                  <input type="date" max={today()} value={data.sighting_date} onChange={e => setData('sighting_date', e.target.value)} style={inputStyle} />
                  {errors.sighting_date && <div style={errStyle}>{errors.sighting_date}</div>}
                </div>
                <div style={{ flex: '1 1 120px' }}>
                  <label style={labelStyle}>Time <span style={{ color: '#a89874' }}>(opt)</span></label>
                  <input type="time" value={data.sighting_time} onChange={e => setData('sighting_time', e.target.value)} style={inputStyle} />
                  {errors.sighting_time && <div style={errStyle}>{errors.sighting_time}</div>}
                </div>
              </div>

              <div>
                <label style={labelStyle}>Notes <span style={{ color: '#a89874' }}>(opt)</span></label>
                <textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={3} maxLength={2000} style={{ ...inputStyle, fontFamily: 'var(--body)', resize: 'vertical' }} />
                {errors.notes && <div style={errStyle}>{errors.notes}</div>}
              </div>

              {/* GPS — advisory. The precise point lives only in DB 13 and is only
                  ever shown back on the member's own map. */}
              <div style={{ border: '1px dashed #d4c9b0', borderRadius: '3px', padding: '14px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' }}>
                <div>
                  <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b5e50', fontWeight: 700 }}>Location</div>
                  <div style={{ fontSize: '13px', color: located ? '#15803d' : '#6b5e50', marginTop: '3px' }}>
                    {located ? `Captured · ±${data.gps_accuracy_m}m` : 'Optional — tags the spot for your own map only.'}
                  </div>
                </div>
                <button type="button" onClick={captureGps} disabled={locating} style={{ fontFamily: MONO, fontSize: '10px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: INK, background: 'transparent', border: '1px solid #d4c9b0', padding: '9px 14px', cursor: locating ? 'wait' : 'pointer', whiteSpace: 'nowrap' }}>
                  {locating ? 'Locating…' : located ? 'Recapture' : 'Capture GPS'}
                </button>
              </div>

              <div style={{ display: 'flex', gap: '10px', marginTop: '4px' }}>
                <button type="submit" disabled={processing} style={{ flex: 1, padding: '14px', background: BLAZE, color: '#fff', border: 'none', borderRadius: '3px', fontFamily: MONO, fontSize: '13px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}>
                  {processing ? 'Logging…' : online ? 'Log Sighting' : 'Save Offline'}
                </button>
                <a href={index_url} style={{ padding: '14px 18px', color: INK, border: '1px solid #d4c9b0', borderRadius: '3px', fontFamily: MONO, fontSize: '13px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', textDecoration: 'none', display: 'flex', alignItems: 'center' }}>
                  Cancel
                </a>
              </div>
            </form>
          )}
        </div>
      </div>
    </>
  )
}
