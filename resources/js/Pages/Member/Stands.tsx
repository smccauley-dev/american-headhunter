import { Head } from '@inertiajs/react'
import { useState } from 'react'

interface Marker {
  id: string
  x_percent: number
  y_percent: number
  label: string | null
  type: string
  type_label: string
  color: string
  notes: string | null
}

interface Props {
  lease_id: string
  property: { title: string; county: string; state: string } | null
  boundary_image_url: string | null
  markers: Marker[]
}

export default function Stands({ lease_id, property, boundary_image_url, markers }: Props) {
  const [active, setActive] = useState<string | null>(null)
  const markerCount = markers.length

  return (
    <>
      <Head title="Stand Map" />

      <div style={{ minHeight: '100vh', background: '#EDE5D0', display: 'flex', flexDirection: 'column' }}>

        {/* Topbar */}
        <div style={{ background: '#0A1512', borderBottom: '1px solid #1a2e28', flexShrink: 0 }}>
          <div style={{ maxWidth: '1000px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', fontWeight: 700 }}>
              American Headhunter
            </span>
            <a href={`/member/leases/${lease_id}`} style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}>
              ← Lease Detail
            </a>
          </div>
        </div>

        <div style={{ maxWidth: '1000px', width: '100%', margin: '0 auto', padding: '24px 16px 8px' }}>
          <div style={{ fontFamily: 'monospace', fontSize: '10px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '4px' }}>
            Stand Map · {markerCount} marker{markerCount !== 1 ? 's' : ''}
          </div>
          <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '24px', fontWeight: 400, color: '#0A1512', margin: 0 }}>
            {property?.title ?? 'Property'}
          </h1>
        </div>

        <div style={{ maxWidth: '1000px', width: '100%', margin: '0 auto', padding: '12px 16px 40px', flex: 1, boxSizing: 'border-box' }}>
          {!boundary_image_url ? (
            <div style={{ background: '#F8F4EB', border: '1px solid #0A1512', boxShadow: '6px 6px 0 #0A1512', padding: '40px 32px', textAlign: 'center', color: '#6b5e50', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: '16px' }}>
              No boundary map has been uploaded for this property yet. Once the landowner adds one, your stands and markers will appear here.
            </div>
          ) : (
            <>
              <div style={{ position: 'relative', background: '#F8F4EB', border: '1px solid #0A1512', boxShadow: '8px 8px 0 #0A1512', lineHeight: 0 }}>
                <img
                  src={boundary_image_url}
                  alt={`Boundary map — ${property?.title ?? 'property'}`}
                  style={{ display: 'block', width: '100%', height: 'auto' }}
                />

                {markers.map((m) => (
                  <button
                    key={m.id}
                    type="button"
                    onClick={() => setActive(active === m.id ? null : m.id)}
                    title={m.label ? `${m.label} · ${m.type_label}` : m.type_label}
                    style={{
                      position: 'absolute',
                      left: `${m.x_percent}%`,
                      top: `${m.y_percent}%`,
                      transform: 'translate(-50%, -50%)',
                      background: 'none',
                      border: 'none',
                      padding: 0,
                      cursor: 'pointer',
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      gap: '3px',
                      zIndex: active === m.id ? 20 : 10,
                    }}
                  >
                    <span style={{ width: '15px', height: '15px', borderRadius: '50%', background: m.color, border: '2px solid #fff', boxShadow: '0 1px 4px rgba(0,0,0,0.45)' }} />
                    {m.label && (
                      <span style={{ fontFamily: 'monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.04em', color: '#fff', background: 'rgba(10,21,18,0.82)', padding: '1px 5px', borderRadius: '3px', whiteSpace: 'nowrap', maxWidth: '140px', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                        {m.label}
                      </span>
                    )}

                    {active === m.id && (m.notes || m.type_label) && (
                      <span style={{ position: 'absolute', top: '100%', marginTop: '4px', left: '50%', transform: 'translateX(-50%)', background: '#0A1512', color: '#F8F4EB', border: '1px solid #C84C21', padding: '6px 8px', borderRadius: '3px', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: '12px', lineHeight: 1.4, width: 'max-content', maxWidth: '200px', textAlign: 'left', zIndex: 30 }}>
                        <span style={{ display: 'block', fontFamily: 'monospace', fontSize: '9px', letterSpacing: '.08em', textTransform: 'uppercase', color: '#a89874', marginBottom: m.notes ? '2px' : 0 }}>
                          {m.type_label}
                        </span>
                        {m.notes}
                      </span>
                    )}
                  </button>
                ))}
              </div>

              {/* Legend */}
              {markerCount > 0 && (
                <div style={{ marginTop: '16px', display: 'flex', flexWrap: 'wrap', gap: '8px 18px' }}>
                  {markers.map((m) => (
                    <div key={`legend-${m.id}`} style={{ display: 'flex', alignItems: 'center', gap: '6px', fontFamily: "'Crimson Pro', Georgia, serif", fontSize: '13px', color: '#0A1512' }}>
                      <span style={{ width: '11px', height: '11px', borderRadius: '50%', background: m.color, border: '1px solid #0A1512', flexShrink: 0 }} />
                      <span>{m.label || m.type_label}</span>
                      <span style={{ fontFamily: 'monospace', fontSize: '9px', letterSpacing: '.06em', textTransform: 'uppercase', color: '#a89874' }}>
                        {m.type_label}
                      </span>
                    </div>
                  ))}
                </div>
              )}

              <p style={{ marginTop: '14px', fontFamily: 'monospace', fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874' }}>
                Read-only · Tap a marker for details
              </p>
            </>
          )}
        </div>
      </div>
    </>
  )
}
