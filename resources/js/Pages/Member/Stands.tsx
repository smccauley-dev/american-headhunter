import { Head } from '@inertiajs/react'
import { useEffect, useRef } from 'react'
import mapboxgl from 'mapbox-gl'
import 'mapbox-gl/dist/mapbox-gl.css'

interface StandFeature {
  type: 'Feature'
  geometry: { type: 'Point'; coordinates: [number, number] }
  properties: { id: string; name: string; stand_type: string | null; elevation_ft: number | null; is_active: boolean }
}

interface Props {
  lease_id: string
  property: { title: string; county: string; state: string } | null
  boundary: { type: 'Feature'; geometry: GeoJSON.Geometry; properties: Record<string, unknown> } | null
  stands: { type: 'FeatureCollection'; features: StandFeature[] }
}

const TOKEN = import.meta.env.VITE_MAPBOX_TOKEN as string | undefined

export default function Stands({ lease_id, property, boundary, stands }: Props) {
  const container = useRef<HTMLDivElement>(null)
  const mapRef = useRef<mapboxgl.Map | null>(null)

  useEffect(() => {
    if (!TOKEN || !container.current || mapRef.current) return

    mapboxgl.accessToken = TOKEN
    const map = new mapboxgl.Map({
      container: container.current,
      style: 'mapbox://styles/mapbox/outdoors-v12',
      center: [-90, 38],
      zoom: 4,
    })
    mapRef.current = map

    map.on('load', () => {
      const bounds = new mapboxgl.LngLatBounds()

      if (boundary?.geometry) {
        map.addSource('boundary', { type: 'geojson', data: boundary as GeoJSON.Feature })
        map.addLayer({
          id: 'boundary-fill', type: 'fill', source: 'boundary',
          paint: { 'fill-color': '#C84C21', 'fill-opacity': 0.08 },
        })
        map.addLayer({
          id: 'boundary-line', type: 'line', source: 'boundary',
          paint: { 'line-color': '#C84C21', 'line-width': 2 },
        })
        extendBounds(bounds, boundary.geometry)
      }

      stands.features.forEach(f => {
        const [lng, lat] = f.geometry.coordinates
        const el = document.createElement('div')
        el.style.cssText = `width:14px;height:14px;border-radius:50%;border:2px solid #fff;background:${f.properties.is_active ? '#15803d' : '#9ca3af'};box-shadow:0 0 0 1px rgba(0,0,0,0.3);cursor:pointer`
        const popup = new mapboxgl.Popup({ offset: 14, closeButton: false }).setHTML(
          `<div style="font-family:monospace;font-size:11px;line-height:1.5">
             <strong>${escapeHtml(f.properties.name)}</strong><br/>
             ${f.properties.stand_type ? escapeHtml(f.properties.stand_type) : 'Stand'}
             ${f.properties.elevation_ft ? ` · ${f.properties.elevation_ft} ft` : ''}
           </div>`,
        )
        new mapboxgl.Marker(el).setLngLat([lng, lat]).setPopup(popup).addTo(map)
        bounds.extend([lng, lat])
      })

      if (!bounds.isEmpty()) {
        map.fitBounds(bounds, { padding: 48, maxZoom: 15 })
      }
    })

    return () => { map.remove(); mapRef.current = null }
  }, [boundary, stands])

  const standCount = stands.features.length

  return (
    <>
      <Head title="Stand Map" />

      <div style={{ minHeight: '100vh', background: '#fafaf9', display: 'flex', flexDirection: 'column' }}>

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
            Stand Map · {standCount} stand{standCount !== 1 ? 's' : ''}
          </div>
          <h1 style={{ fontFamily: "'Fraunces', Georgia, serif", fontSize: '24px', fontWeight: 400, color: '#0A1512', margin: 0 }}>
            {property?.title ?? 'Property'}
          </h1>
        </div>

        <div style={{ maxWidth: '1000px', width: '100%', margin: '0 auto', padding: '12px 16px 40px', flex: 1, boxSizing: 'border-box' }}>
          {!TOKEN ? (
            <div style={{ background: '#fff', border: '1px solid #e5e0d8', borderRadius: '4px', padding: '32px', textAlign: 'center', color: '#6b5e50', fontSize: '14px' }}>
              Map unavailable — Mapbox token not configured.
            </div>
          ) : (
            <div
              ref={container}
              style={{ width: '100%', height: '70vh', minHeight: '420px', border: '1px solid #d4c9b0', borderRadius: '4px', overflow: 'hidden' }}
            />
          )}
        </div>
      </div>
    </>
  )
}

function extendBounds(bounds: mapboxgl.LngLatBounds, geom: GeoJSON.Geometry) {
  const each = (coords: number[][]) => coords.forEach(c => bounds.extend([c[0], c[1]]))
  if (geom.type === 'Polygon') geom.coordinates.forEach(each)
  else if (geom.type === 'MultiPolygon') geom.coordinates.forEach(p => p.forEach(each))
}

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}
