import { Head, useForm } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import { useOfflineSubmit } from '@/offline/useOfflineSubmit'
import { useOnline } from '@/offline/useOnline'
import MemberTopbar from '@/Components/Member/MemberTopbar'

interface Option { value: string; label: string }
interface LeaseOption { id: string; property_title: string; end_date: string | null }

interface Props {
  leases: LeaseOption[]
  species: Option[]
  weapons: Option[]
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

export default function HarvestNew({ leases, species, weapons, store_url, index_url }: Props) {
  const [locating, setLocating] = useState(false)
  const [located, setLocated] = useState(false)

  const { data, setData, post, processing, errors } = useForm({
    lease_id: leases[0]?.id ?? '',
    species_code: '',
    weapon_type: '',
    harvest_date: today(),
    harvest_time: '',
    antler_score: '',
    weight_lbs: '',
    age_estimate: '',
    notes: '',
    is_public: false as boolean,
    latitude: null as number | null,
    longitude: null as number | null,
    gps_accuracy_m: null as number | null,
    cwd_acknowledged: false as boolean,
    photos: [] as File[],
    keep_photo_location: false as boolean,
  })

  // The store surfaces a required-CWD acknowledgment as a field error on
  // cwd_acknowledged (422 can't reach Inertia as a status). When present, reveal
  // the ack checkbox so the member can confirm and re-submit.
  const cwdRequired = Boolean(errors.cwd_acknowledged)

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

  const { queue } = useOfflineSubmit('harvest', index_url)
  const online = useOnline()

  // Object-URL previews for the selected photos, revoked when the set changes.
  const previews = useMemo(() => data.photos.map(f => URL.createObjectURL(f)), [data.photos])
  useEffect(() => () => { previews.forEach(u => URL.revokeObjectURL(u)) }, [previews])

  const photoError = Object.entries(errors as Record<string, string>)
    .find(([k]) => k === 'photos' || k.startsWith('photos.'))?.[1]

  function addPhotos(list: FileList | null) {
    const files = Array.from(list ?? [])
    if (files.length === 0) return
    setData('photos', [...data.photos, ...files].slice(0, 6))
  }

  function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!navigator.onLine) {
      const label = species.find(s => s.value === data.species_code)?.label ?? 'Harvest'
      // Files can't sit in the IndexedDB queue — an offline save drops photos.
      const { photos: _photos, keep_photo_location: _keep, ...rest } = data
      void queue(store_url, { ...rest }, label)
      return
    }
    post(store_url)
  }

  return (
    <>
      <Head title="Log Harvest" />
      <div className="topo-bg" style={{ minHeight: '100vh', backgroundColor: '#EDE5D0' }}>
        <MemberTopbar maxWidth={560} rightHref={index_url} rightLabel="← Harvest Log" />

        <div style={{ maxWidth: '560px', margin: '0 auto', padding: '40px 16px 64px' }}>
          <div style={{ marginBottom: '24px' }}>
            <div style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>Field Log</div>
            <h1 style={{ fontFamily: DISPLAY, fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>Log a Harvest</h1>
          </div>

          {leases.length === 0 ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '28px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: DISPLAY, fontSize: '18px', color: INK, marginBottom: '8px' }}>No active lease</div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: '0 0 16px' }}>
                You need an active lease to log a harvest. If you're a guest on someone's lease, ask the lease holder to add and approve you.
              </p>
              <a href="/member" style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: INK, textDecoration: 'none', border: '1px solid #d4c9b0', padding: '10px 18px', display: 'inline-block' }}>Back to Portal</a>
            </div>
          ) : (
            <form onSubmit={submit} style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '18px' }}>

              {!online && (
                <div style={{ background: '#fef6ee', border: '1px solid #f0c9a8', borderRadius: '3px', padding: '11px 14px', fontFamily: MONO, fontSize: '11px', color: '#9a4a1e', lineHeight: 1.5 }}>
                  You're offline. This harvest will be saved on your device and synced when you're back on signal. Quota and CWD-zone checks run at sync — if either blocks it, you'll see it flagged in your pending list.
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
                <div style={{ flex: '1 1 200px' }}>
                  <label style={labelStyle}>Weapon</label>
                  <select value={data.weapon_type} onChange={e => setData('weapon_type', e.target.value)} style={inputStyle}>
                    <option value="">Select…</option>
                    {weapons.map(w => <option key={w.value} value={w.value}>{w.label}</option>)}
                  </select>
                  {errors.weapon_type && <div style={errStyle}>{errors.weapon_type}</div>}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '14px', flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 160px' }}>
                  <label style={labelStyle}>Date</label>
                  <input type="date" max={today()} value={data.harvest_date} onChange={e => setData('harvest_date', e.target.value)} style={inputStyle} />
                  {errors.harvest_date && <div style={errStyle}>{errors.harvest_date}</div>}
                </div>
                <div style={{ flex: '1 1 120px' }}>
                  <label style={labelStyle}>Time <span style={{ color: '#a89874' }}>(opt)</span></label>
                  <input type="time" value={data.harvest_time} onChange={e => setData('harvest_time', e.target.value)} style={inputStyle} />
                  {errors.harvest_time && <div style={errStyle}>{errors.harvest_time}</div>}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '14px', flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 120px' }}>
                  <label style={labelStyle}>Score <span style={{ color: '#a89874' }}>(opt)</span></label>
                  <input type="number" step="0.01" min="0" value={data.antler_score} onChange={e => setData('antler_score', e.target.value)} style={inputStyle} placeholder='B&amp;C in.' />
                  {errors.antler_score && <div style={errStyle}>{errors.antler_score}</div>}
                </div>
                <div style={{ flex: '1 1 120px' }}>
                  <label style={labelStyle}>Weight lbs <span style={{ color: '#a89874' }}>(opt)</span></label>
                  <input type="number" step="0.01" min="0" value={data.weight_lbs} onChange={e => setData('weight_lbs', e.target.value)} style={inputStyle} />
                  {errors.weight_lbs && <div style={errStyle}>{errors.weight_lbs}</div>}
                </div>
                <div style={{ flex: '1 1 120px' }}>
                  <label style={labelStyle}>Age est. <span style={{ color: '#a89874' }}>(opt)</span></label>
                  <input type="text" maxLength={40} value={data.age_estimate} onChange={e => setData('age_estimate', e.target.value)} style={inputStyle} placeholder='e.g. 3.5 yr' />
                  {errors.age_estimate && <div style={errStyle}>{errors.age_estimate}</div>}
                </div>
              </div>

              <div>
                <label style={labelStyle}>Notes <span style={{ color: '#a89874' }}>(opt)</span></label>
                <textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={3} maxLength={2000} style={{ ...inputStyle, fontFamily: 'var(--body)', resize: 'vertical' }} />
                {errors.notes && <div style={errStyle}>{errors.notes}</div>}
              </div>

              {/* GPS — advisory. The precise point lives only in DB 13; it is never
                  shown back on the public gallery (SEC-024). */}
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

              {/* Photos — attached to the harvest and mirrored to the profile
                  Photos gallery. EXIF location data is stripped on ingest unless
                  the member opts to keep it; kept photos are flagged private and
                  never publicly served (SEC-024 / SEC-061). */}
              <div style={{ border: '1px dashed #d4c9b0', borderRadius: '3px', padding: '14px 16px' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' }}>
                  <div>
                    <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b5e50', fontWeight: 700 }}>Photos <span style={{ color: '#a89874' }}>(opt)</span></div>
                    <div style={{ fontSize: '13px', color: '#6b5e50', marginTop: '3px' }}>
                      {data.photos.length > 0 ? `${data.photos.length} of 6 selected` : 'Up to 6 — they also appear on your profile gallery.'}
                    </div>
                  </div>
                  <label style={{ fontFamily: MONO, fontSize: '10px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: INK, background: 'transparent', border: '1px solid #d4c9b0', padding: '9px 14px', cursor: data.photos.length >= 6 ? 'not-allowed' : 'pointer', opacity: data.photos.length >= 6 ? 0.5 : 1, whiteSpace: 'nowrap' }}>
                    Add Photos
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp"
                      multiple
                      disabled={data.photos.length >= 6}
                      style={{ display: 'none' }}
                      onChange={e => { addPhotos(e.target.files); e.target.value = '' }}
                    />
                  </label>
                </div>

                {previews.length > 0 && (
                  <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', marginTop: '12px' }}>
                    {previews.map((src, i) => (
                      <div key={src} style={{ position: 'relative' }}>
                        <img src={src} alt={`Photo ${i + 1}`} style={{ width: '64px', height: '64px', objectFit: 'cover', borderRadius: '3px', border: '1px solid #d4c9b0', display: 'block' }} />
                        <button type="button" aria-label="Remove photo" onClick={() => setData('photos', data.photos.filter((_, j) => j !== i))} style={{ position: 'absolute', top: '-7px', right: '-7px', width: '18px', height: '18px', borderRadius: '50%', background: INK, color: '#fff', border: 'none', fontSize: '11px', lineHeight: 1, cursor: 'pointer', padding: 0 }}>×</button>
                      </div>
                    ))}
                  </div>
                )}

                {data.photos.length > 0 && (
                  <label style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', cursor: 'pointer', marginTop: '12px' }}>
                    <input type="checkbox" checked={data.keep_photo_location} onChange={e => setData('keep_photo_location', e.target.checked)} style={{ marginTop: '2px' }} />
                    <span style={{ fontSize: '12px', color: '#6b5e50', lineHeight: 1.5 }}>
                      Keep location data on these photos. <span style={{ color: '#a89874' }}>Kept photos stay private — they are never shown on your public gallery.</span>
                    </span>
                  </label>
                )}

                {photoError && <div style={errStyle}>{photoError}</div>}

                {!online && data.photos.length > 0 && (
                  <div style={{ fontFamily: MONO, fontSize: '11px', color: '#9a4a1e', marginTop: '10px' }}>
                    Photos can't be included in an offline save — they'll be skipped.
                  </div>
                )}
              </div>

              <label style={{ display: 'flex', alignItems: 'center', gap: '10px', cursor: 'pointer' }}>
                <input type="checkbox" checked={data.is_public} onChange={e => setData('is_public', e.target.checked)} />
                <span style={{ fontSize: '13px', color: '#6b5e50' }}>Show this harvest on my public trophy gallery <span style={{ color: '#a89874' }}>(location is never shown)</span></span>
              </label>

              {cwdRequired && (
                <div style={{ background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: '3px', padding: '14px 16px' }}>
                  <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#92400e', fontWeight: 700, marginBottom: '6px' }}>CWD Zone — Acknowledgment Required</div>
                  <div style={{ fontSize: '13px', color: '#6b5e50', lineHeight: 1.5, marginBottom: '10px' }}>{errors.cwd_acknowledged}</div>
                  <label style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', cursor: 'pointer' }}>
                    <input type="checkbox" checked={data.cwd_acknowledged} onChange={e => setData('cwd_acknowledged', e.target.checked)} style={{ marginTop: '3px' }} />
                    <span style={{ fontSize: '13px', color: INK }}>I acknowledge the CWD sampling requirement for this zone and will comply.</span>
                  </label>
                </div>
              )}

              <div style={{ display: 'flex', gap: '10px', marginTop: '4px' }}>
                <button type="submit" disabled={processing} style={{ flex: 1, padding: '14px', background: BLAZE, color: '#fff', border: 'none', borderRadius: '3px', fontFamily: MONO, fontSize: '13px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}>
                  {processing ? 'Logging…' : online ? 'Log Harvest' : 'Save Offline'}
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
