import { Head, router, usePage } from '@inertiajs/react'
import OfflineQueueBanner from '@/Components/Member/OfflineQueueBanner'

interface Sighting {
  id: string
  species: string
  count: number
  sighting_date: string | null
  property_title: string
  notes: string | null
}

interface Props {
  sightings: Sighting[]
  new_url: string
  harvest_url: string
}

interface PageProps {
  flash: { success: string | null; error: string | null }
  [key: string]: unknown
}

const INK = '#0a1512'
const BLAZE = '#c84c21'
const MONO = "'JetBrains Mono', Menlo, monospace"
const DISPLAY = "'Fraunces', Georgia, serif"

function Topbar() {
  return (
    <div style={{ background: INK, borderBottom: '1px solid #1a2e28' }}>
      <div style={{ maxWidth: '640px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <span style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: BLAZE, fontWeight: 700 }}>
          American Headhunter
        </span>
        <a href="/member" style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}>
          Member Portal
        </a>
      </div>
    </div>
  )
}

export default function SightingIndex({ sightings, new_url, harvest_url }: Props) {
  const { flash } = usePage<PageProps>().props

  return (
    <>
      <Head title="Wildlife Sightings" />
      <div style={{ minHeight: '100vh', background: '#faf7f2' }}>
        <Topbar />
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

          <OfflineQueueBanner />

          {/* Header */}
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: '24px', gap: '12px', flexWrap: 'wrap' }}>
            <div>
              <div style={{ fontFamily: MONO, fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
                Field Log
              </div>
              <h1 style={{ fontFamily: DISPLAY, fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>
                Wildlife Sightings
              </h1>
            </div>
            <div style={{ display: 'flex', gap: '10px' }}>
              <a href={harvest_url} style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: INK, textDecoration: 'none', border: '1px solid #d4c9b0', padding: '10px 16px' }}>
                Harvest
              </a>
              <button onClick={() => router.visit(new_url)} style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#fff', background: BLAZE, border: 'none', padding: '10px 16px', cursor: 'pointer' }}>
                Log Sighting
              </button>
            </div>
          </div>

          {sightings.length === 0 ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '32px 24px', textAlign: 'center' }}>
              <div style={{ fontFamily: DISPLAY, fontSize: '18px', color: INK, marginBottom: '8px' }}>
                No sightings logged yet
              </div>
              <p style={{ fontSize: '14px', color: '#6b5e50', lineHeight: 1.5, margin: '0 0 16px' }}>
                Log the animals you see in the field — even the ones you don't take. Over a season it builds a picture of what's moving on your property.
              </p>
              <button onClick={() => router.visit(new_url)} style={{ fontFamily: MONO, fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#fff', background: BLAZE, border: 'none', padding: '11px 20px', cursor: 'pointer' }}>
                Log Your First Sighting
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
              {sightings.map(s => (
                <div key={s.id} style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '16px 20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' }}>
                  <div>
                    <div style={{ fontFamily: DISPLAY, fontSize: '17px', color: INK, lineHeight: 1.2 }}>
                      {s.species}
                      {s.count > 1 && (
                        <span style={{ fontFamily: MONO, fontSize: '11px', color: BLAZE, marginLeft: '8px' }}>×{s.count}</span>
                      )}
                    </div>
                    <div style={{ fontSize: '13px', color: '#6b5e50', marginTop: '3px' }}>
                      {s.property_title}
                    </div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontFamily: MONO, fontSize: '12px', color: '#6b5e50' }}>{s.sighting_date}</div>
                  </div>
                </div>
              ))}
            </div>
          )}

        </div>
      </div>
    </>
  )
}
