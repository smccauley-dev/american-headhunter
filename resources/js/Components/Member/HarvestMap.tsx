import { useEffect, useRef } from 'react'
import mapboxgl from 'mapbox-gl'
import 'mapbox-gl/dist/mapbox-gl.css'

// The member GPS map (SEC-024: members-only data — this component is never
// rendered on a public page). Draws the PostGIS property boundary, then three
// marker families: the landowner's georeferenced markers (their per-type
// palette), co-hunters' harvests (blaze), and sightings (olive). Popups are
// built with DOM textContent — hunter names are user-supplied, never innerHTML.

export interface MapFeature {
  id: string
  type: 'harvest' | 'sighting'
  species: string
  hunter_name: string
  is_own: boolean
  date: string | null
  photo_url?: string | null
  count?: number
  lng: number
  lat: number
}

export interface LandownerMarker {
  id: string
  label: string
  type: string
  type_label: string
  color: string
  notes: string | null
  lng: number
  lat: number
}

export interface HarvestMapData {
  boundary: { type: 'Feature'; geometry: GeoJSON.Geometry; properties: Record<string, unknown> } | null
  stands: { type: 'FeatureCollection'; features: GeoJSON.Feature[] }
  landowner_markers: LandownerMarker[]
  features: MapFeature[]
}

const HARVEST_COLOR = '#C84C21'
const SIGHTING_COLOR = '#4a5440'
const MONO = "'JetBrains Mono', Menlo, monospace"

function collectCoords(geometry: GeoJSON.Geometry, into: [number, number][]) {
  if (geometry.type === 'GeometryCollection') {
    geometry.geometries.forEach(g => collectCoords(g, into))
    return
  }
  const walk = (c: unknown) => {
    if (Array.isArray(c) && typeof c[0] === 'number') {
      into.push([c[0] as number, c[1] as number])
    } else if (Array.isArray(c)) {
      c.forEach(walk)
    }
  }
  walk((geometry as { coordinates: unknown }).coordinates)
}

function pinElement(color: string, own: boolean): HTMLDivElement {
  const el = document.createElement('div')
  el.style.cssText = `width:14px;height:14px;border-radius:50%;background:${color};`
    + `border:2px solid ${own ? '#F4ECDC' : '#0A1512'};box-shadow:0 1px 4px rgba(10,21,18,.6);cursor:pointer;`
  return el
}

function popupContent(f: MapFeature): HTMLDivElement {
  const box = document.createElement('div')
  box.style.cssText = "font-family:'Fraunces',Georgia,serif;color:#0A1512;max-width:200px;"

  if (f.type === 'harvest' && f.photo_url) {
    const img = document.createElement('img')
    img.src = f.photo_url
    img.alt = f.species
    img.style.cssText = 'width:100%;height:110px;object-fit:cover;display:block;margin-bottom:8px;border:1px solid #d4c9b0;'
    box.appendChild(img)
  }

  const title = document.createElement('div')
  title.textContent = f.type === 'sighting' && (f.count ?? 1) > 1 ? `${f.species} × ${f.count}` : f.species
  title.style.cssText = 'font-size:15px;font-weight:500;line-height:1.2;'
  box.appendChild(title)

  const kind = document.createElement('div')
  kind.textContent = f.type === 'harvest' ? 'Harvest' : 'Sighting'
  kind.style.cssText = `font-family:${MONO};font-size:9px;letter-spacing:.12em;text-transform:uppercase;`
    + `color:${f.type === 'harvest' ? HARVEST_COLOR : SIGHTING_COLOR};margin-top:3px;`
  box.appendChild(kind)

  const meta = document.createElement('div')
  meta.textContent = [f.is_own ? 'You' : f.hunter_name, f.date].filter(Boolean).join(' · ')
  meta.style.cssText = 'font-family:var(--body,Georgia);font-size:12px;color:#4a5440;margin-top:6px;'
  box.appendChild(meta)

  return box
}

function landownerPopup(m: LandownerMarker): HTMLDivElement {
  const box = document.createElement('div')
  box.style.cssText = "font-family:'Fraunces',Georgia,serif;color:#0A1512;max-width:200px;"

  const title = document.createElement('div')
  title.textContent = m.label
  title.style.cssText = 'font-size:15px;font-weight:500;line-height:1.2;'
  box.appendChild(title)

  const kind = document.createElement('div')
  kind.textContent = m.type_label
  kind.style.cssText = `font-family:${MONO};font-size:9px;letter-spacing:.12em;text-transform:uppercase;color:${m.color};margin-top:3px;`
  box.appendChild(kind)

  if (m.notes) {
    const notes = document.createElement('div')
    notes.textContent = m.notes
    notes.style.cssText = 'font-family:var(--body,Georgia);font-size:12px;color:#4a5440;margin-top:6px;line-height:1.4;'
    box.appendChild(notes)
  }

  return box
}

export default function HarvestMap({ map: data }: { map: HarvestMapData }) {
  const containerRef = useRef<HTMLDivElement>(null)
  const token = import.meta.env.VITE_MAPBOX_TOKEN as string | undefined

  useEffect(() => {
    if (!token || !containerRef.current) return

    mapboxgl.accessToken = token

    // Fit to the boundary when we have one, else to whatever points exist.
    const coords: [number, number][] = []
    if (data.boundary) collectCoords(data.boundary.geometry, coords)
    if (coords.length === 0) {
      data.features.forEach(f => coords.push([f.lng, f.lat]))
      data.landowner_markers.forEach(m => coords.push([m.lng, m.lat]))
    }

    const map = new mapboxgl.Map({
      container: containerRef.current,
      style: 'mapbox://styles/mapbox/satellite-streets-v12',
      center: coords[0] ?? [-98.5795, 39.8283],
      zoom: coords.length > 0 ? 13 : 3,
      attributionControl: true,
    })
    map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right')

    if (coords.length > 1) {
      const bounds = coords.reduce(
        (b, c) => b.extend(c),
        new mapboxgl.LngLatBounds(coords[0], coords[0]),
      )
      map.fitBounds(bounds, { padding: 48, animate: false })
    }

    map.on('load', () => {
      if (data.boundary) {
        map.addSource('boundary', { type: 'geojson', data: data.boundary as GeoJSON.Feature })
        map.addLayer({
          id: 'boundary-fill', type: 'fill', source: 'boundary',
          paint: { 'fill-color': '#C84C21', 'fill-opacity': 0.08 },
        })
        map.addLayer({
          id: 'boundary-line', type: 'line', source: 'boundary',
          paint: { 'line-color': '#C84C21', 'line-width': 2.5 },
        })
      }
    })

    const markers: mapboxgl.Marker[] = []

    data.landowner_markers.forEach(m => {
      markers.push(
        new mapboxgl.Marker({ element: pinElement(m.color, false) })
          .setLngLat([m.lng, m.lat])
          .setPopup(new mapboxgl.Popup({ offset: 12, closeButton: false }).setDOMContent(landownerPopup(m)))
          .addTo(map),
      )
    })

    data.features.forEach(f => {
      markers.push(
        new mapboxgl.Marker({ element: pinElement(f.type === 'harvest' ? HARVEST_COLOR : SIGHTING_COLOR, f.is_own) })
          .setLngLat([f.lng, f.lat])
          .setPopup(new mapboxgl.Popup({ offset: 12, closeButton: false }).setDOMContent(popupContent(f)))
          .addTo(map),
      )
    })

    return () => {
      markers.forEach(m => m.remove())
      map.remove()
    }
  }, [data, token])

  if (!token) {
    return (
      <div style={{ padding: '32px 24px', textAlign: 'center', fontFamily: MONO, fontSize: '12px', color: '#6b5e50', border: '1px dashed #d4c9b0' }}>
        Map unavailable — no Mapbox token is configured (VITE_MAPBOX_TOKEN).
      </div>
    )
  }

  return (
    <div style={{ position: 'relative' }}>
      <div ref={containerRef} style={{ width: '100%', height: 'min(70vh, 640px)', border: '1px solid #0A1512' }} />
      {/* Legend */}
      <div style={{ position: 'absolute', left: '10px', bottom: '10px', background: 'rgba(248,244,235,.94)', border: '1px solid #0A1512', padding: '8px 12px', display: 'flex', gap: '14px', alignItems: 'center' }}>
        {[
          { color: HARVEST_COLOR, label: 'Harvest' },
          { color: SIGHTING_COLOR, label: 'Sighting' },
          { color: '#92400e', label: 'Landowner' },
        ].map(item => (
          <span key={item.label} style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontFamily: MONO, fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#0A1512' }}>
            <span style={{ width: '10px', height: '10px', borderRadius: '50%', background: item.color, border: '1px solid #0A1512', display: 'inline-block' }} />
            {item.label}
          </span>
        ))}
      </div>
    </div>
  )
}
