import { useForm, router } from '@inertiajs/react'
import { useRef, useState } from 'react'
import { Section, INK, ACCENT } from './PropertyChrome'

export interface MapMarker {
  id: string
  label: string
  marker_type: string
  type_label: string
  x_percent: number
  y_percent: number
  color: string
  notes: string | null
}

export interface MapImage {
  id: string
  document_id: string
  description: string | null
  is_boundary: boolean
  markers: MapMarker[]
}

const input: React.CSSProperties = {
  width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: INK,
  background: '#fff', border: '1px solid #d4c9b0', padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
}

const label: React.CSSProperties = {
  display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600,
  letterSpacing: '.12em', textTransform: 'uppercase', color: '#a89874', marginBottom: '5px',
}

const ghostBtn: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em',
  textTransform: 'uppercase', padding: '7px 13px', background: 'transparent',
  color: INK, border: '1px solid #d4c9b0', cursor: 'pointer',
}
const inkBtn: React.CSSProperties = { ...ghostBtn, background: INK, color: '#F4ECDC', borderColor: INK }
const dangerBtn: React.CSSProperties = { ...ghostBtn, color: ACCENT, borderColor: 'rgba(200,76,33,0.4)' }

const DRAG_THRESHOLD = 1.2 // percent of image moved before a press counts as a drag

export default function PropertyMapTab({ propertyId, images, markerTypes }: {
  propertyId: string
  images: MapImage[]
  markerTypes: Record<string, string>
}) {
  const typeKeys = Object.keys(markerTypes)
  const wrapRef = useRef<HTMLDivElement>(null)

  const [selectedId, setSelectedId] = useState<string>(images[0]?.id ?? '')
  const selected = images.find(i => i.id === selectedId) ?? images[0] ?? null

  const [placing, setPlacing] = useState(false)
  const [pending, setPending] = useState<{ x: number; y: number } | null>(null)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [override, setOverride] = useState<Record<string, { x: number; y: number }>>({})
  const drag = useRef<{ id: string; startX: number; startY: number; moved: boolean } | null>(null)

  // Upload state — plain FormData + router.post (Inertia's useForm does not reliably
  // carry File[] through its data clone; the working profile uploader uses this same
  // pattern).
  const [files, setFiles] = useState<File[]>([])
  const [description, setDescription] = useState('')
  const [importExif, setImportExif] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const markerForm = useForm<{ label: string; marker_type: string; notes: string }>({
    label: '', marker_type: typeKeys[0], notes: '',
  })

  function submitUpload(e: React.FormEvent) {
    e.preventDefault()
    if (files.length === 0) return
    const fd = new FormData()
    files.forEach(f => fd.append('images[]', f))
    if (description) fd.append('description', description)
    fd.append('import_exif', importExif ? '1' : '0')
    setUploading(true)
    setUploadError(null)
    router.post(`/member/properties/${propertyId}/map-images`, fd, {
      preserveScroll: true, forceFormData: true,
      onSuccess: () => {
        setFiles([]); setDescription('')
        if (fileRef.current) fileRef.current.value = ''
      },
      onError: errs => setUploadError(errs.images ?? 'Upload failed.'),
      onFinish: () => setUploading(false),
    })
  }

  function pct(e: React.PointerEvent): { x: number; y: number } {
    const rect = wrapRef.current!.getBoundingClientRect()
    const x = Math.min(100, Math.max(0, ((e.clientX - rect.left) / rect.width) * 100))
    const y = Math.min(100, Math.max(0, ((e.clientY - rect.top) / rect.height) * 100))
    return { x, y }
  }

  // ── Image-level pointer: place a new marker (only in placing mode) ──────────
  function onImagePointerUp(e: React.PointerEvent) {
    if (drag.current) return // pin drag handled separately
    if (!placing) return
    const p = pct(e)
    setPending({ x: p.x, y: p.y })
    markerForm.setData({ label: '', marker_type: typeKeys[0], notes: '' })
    setPlacing(false)
    setEditingId(null)
  }

  // ── Pin drag ────────────────────────────────────────────────────────────────
  function onPinPointerDown(e: React.PointerEvent, m: MapMarker) {
    e.stopPropagation()
    ;(e.target as HTMLElement).setPointerCapture(e.pointerId)
    const p = pct(e)
    drag.current = { id: m.id, startX: p.x, startY: p.y, moved: false }
  }
  function onPinPointerMove(e: React.PointerEvent) {
    if (!drag.current) return
    const p = pct(e)
    if (Math.abs(p.x - drag.current.startX) > DRAG_THRESHOLD || Math.abs(p.y - drag.current.startY) > DRAG_THRESHOLD) {
      drag.current.moved = true
    }
    if (drag.current.moved) {
      setOverride(o => ({ ...o, [drag.current!.id]: { x: p.x, y: p.y } }))
    }
  }
  function onPinPointerUp(e: React.PointerEvent, m: MapMarker) {
    e.stopPropagation()
    const d = drag.current
    drag.current = null
    if (!d) return
    if (d.moved) {
      const p = pct(e)
      router.post(`/member/properties/${propertyId}/markers/${m.id}/move`, { x_percent: p.x, y_percent: p.y }, {
        preserveScroll: true,
        onFinish: () => setOverride(o => { const n = { ...o }; delete n[m.id]; return n }),
      })
    } else {
      openEdit(m)
    }
  }

  function openEdit(m: MapMarker) {
    markerForm.setData({ label: m.label, marker_type: m.marker_type, notes: m.notes ?? '' })
    setEditingId(m.id)
    setPending(null)
    setPlacing(false)
  }
  function cancelMarkerForm() { setPending(null); setEditingId(null); markerForm.clearErrors() }

  function submitMarker(e: React.FormEvent) {
    e.preventDefault()
    if (editingId) {
      markerForm.put(`/member/properties/${propertyId}/markers/${editingId}`, { preserveScroll: true, onSuccess: cancelMarkerForm })
    } else if (pending && selected) {
      router.post(`/member/properties/${propertyId}/map-images/${selected.id}/markers`, {
        label: markerForm.data.label, marker_type: markerForm.data.marker_type,
        notes: markerForm.data.notes, x_percent: pending.x, y_percent: pending.y,
      }, { preserveScroll: true, onSuccess: cancelMarkerForm })
    }
  }
  function deleteMarker(id: string) {
    if (!confirm('Delete this marker?')) return
    router.delete(`/member/properties/${propertyId}/markers/${id}`, { preserveScroll: true, onSuccess: cancelMarkerForm })
  }

  function setBoundary(id: string) {
    router.post(`/member/properties/${propertyId}/map-images/${id}/boundary`, {}, { preserveScroll: true })
  }
  function deleteImage(id: string) {
    if (!confirm('Delete this map image and its markers?')) return
    router.delete(`/member/properties/${propertyId}/map-images/${id}`, {
      preserveScroll: true,
      onSuccess: () => { if (selectedId === id) setSelectedId(images.find(i => i.id !== id)?.id ?? '') },
    })
  }

  return (
    <Section title="Map">
      <form onSubmit={submitUpload} style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginBottom: '22px', borderBottom: '1px solid #e5ddd0', paddingBottom: '20px' }}>
        <input ref={fileRef} type="file" accept="image/*" multiple onChange={e => setFiles(Array.from(e.target.files ?? []))} style={{ fontFamily: 'var(--mono)', fontSize: '12px', color: INK }} />
        <input type="text" value={description} onChange={e => setDescription(e.target.value)} style={input} placeholder="Description for this batch (optional)" maxLength={255} />
        <label style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer', fontFamily: 'var(--body)', fontSize: '14px', color: '#6b5e50' }}>
          <input type="checkbox" checked={importExif} onChange={e => setImportExif(e.target.checked)} />
          Import location from image metadata (EXIF GPS)
        </label>
        {uploadError && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT }}>{uploadError}</div>}
        <div>
          <button type="submit" disabled={uploading || files.length === 0} style={{ ...inkBtn, padding: '10px 22px', fontSize: '10px', opacity: uploading || files.length === 0 ? 0.6 : 1 }}>
            {uploading ? 'Uploading…' : 'Upload Map Images'}
          </button>
          <span style={{ fontFamily: 'var(--body)', fontSize: '13px', color: '#6b5e50', marginLeft: '12px' }}>The first map image becomes the boundary map.</span>
        </div>
      </form>

      {images.length === 0 ? (
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
          No map images yet. Upload a hand-drawn map, aerial photo, or plat to place stands, cameras and access points.
        </p>
      ) : (
        <>
          {/* Image selector */}
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', marginBottom: '18px' }}>
            {images.map(img => (
              <button key={img.id} type="button" onClick={() => { setSelectedId(img.id); cancelMarkerForm() }}
                style={{ position: 'relative', width: '88px', height: '66px', padding: 0, cursor: 'pointer', overflow: 'hidden', background: '#ece4d4', border: img.id === selected?.id ? `2px solid ${INK}` : '1px solid #d4c9b0' }}>
                <img src={`/member/properties/${propertyId}/map-images/${img.document_id}`} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
                {img.is_boundary && (
                  <span style={{ position: 'absolute', bottom: 0, left: 0, right: 0, fontFamily: 'var(--mono)', fontSize: '7px', fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', padding: '2px', background: INK, color: '#F4ECDC', textAlign: 'center' }}>Boundary</span>
                )}
              </button>
            ))}
          </div>

          {selected && (
            <>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px', flexWrap: 'wrap' }}>
                <button type="button" onClick={() => { setPlacing(p => !p); setPending(null); setEditingId(null) }} style={placing ? { ...inkBtn } : ghostBtn}>
                  {placing ? 'Click map to place…' : '+ Add Marker'}
                </button>
                {!selected.is_boundary && <button type="button" onClick={() => setBoundary(selected.id)} style={ghostBtn}>Set as Boundary</button>}
                <button type="button" onClick={() => deleteImage(selected.id)} style={dangerBtn}>Delete Image</button>
                <span style={{ fontFamily: 'var(--body)', fontSize: '13px', color: '#6b5e50' }}>Click a marker to edit · drag to move</span>
              </div>

              <div
                ref={wrapRef}
                onPointerUp={onImagePointerUp}
                onPointerMove={onPinPointerMove}
                style={{ position: 'relative', border: '1px solid #d4c9b0', background: '#ece4d4', userSelect: 'none', cursor: placing ? 'crosshair' : 'default', touchAction: 'none' }}
              >
                <img src={`/member/properties/${propertyId}/map-images/${selected.document_id}`} alt={selected.description ?? ''} draggable={false} style={{ width: '100%', display: 'block', pointerEvents: 'none' }} />
                {selected.markers.map(m => {
                  const pos = override[m.id] ?? { x: m.x_percent, y: m.y_percent }
                  return (
                    <div
                      key={m.id}
                      onPointerDown={e => onPinPointerDown(e, m)}
                      onPointerUp={e => onPinPointerUp(e, m)}
                      title={m.label}
                      style={{ position: 'absolute', left: `${pos.x}%`, top: `${pos.y}%`, transform: 'translate(-50%, -50%)', cursor: 'grab', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '2px' }}
                    >
                      <span style={{ width: '16px', height: '16px', borderRadius: '50%', background: m.color, border: '2px solid #fff', boxShadow: '0 1px 3px rgba(0,0,0,0.4)' }} />
                      <span style={{ fontFamily: 'var(--mono)', fontSize: '8px', fontWeight: 700, letterSpacing: '.04em', color: '#fff', background: 'rgba(10,21,18,0.78)', padding: '1px 5px', whiteSpace: 'nowrap' }}>{m.label}</span>
                    </div>
                  )
                })}
                {pending && (
                  <span style={{ position: 'absolute', left: `${pending.x}%`, top: `${pending.y}%`, transform: 'translate(-50%, -50%)', width: '16px', height: '16px', borderRadius: '50%', background: ACCENT, border: '2px solid #fff', boxShadow: '0 1px 3px rgba(0,0,0,0.4)' }} />
                )}
              </div>

              {/* Marker form (add or edit) */}
              {(pending || editingId) && (
                <form onSubmit={submitMarker} style={{ border: `1px solid ${ACCENT}`, background: '#fff', padding: '16px', marginTop: '16px', display: 'flex', flexDirection: 'column', gap: '12px' }}>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: INK }}>
                    {editingId ? 'Edit Marker' : 'New Marker'}
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '12px' }}>
                    <div>
                      <label style={label}>Label</label>
                      <input type="text" value={markerForm.data.label} onChange={e => markerForm.setData('label', e.target.value)} style={input} maxLength={120} placeholder="North gate, Box blind #3…" />
                      {markerForm.errors.label && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{markerForm.errors.label}</div>}
                    </div>
                    <div>
                      <label style={label}>Type</label>
                      <select value={markerForm.data.marker_type} onChange={e => markerForm.setData('marker_type', e.target.value)} style={input}>
                        {typeKeys.map(k => <option key={k} value={k}>{markerTypes[k]}</option>)}
                      </select>
                    </div>
                  </div>
                  <div>
                    <label style={label}>Notes</label>
                    <textarea rows={2} value={markerForm.data.notes} onChange={e => markerForm.setData('notes', e.target.value)} style={{ ...input, resize: 'vertical' }} maxLength={500} />
                  </div>
                  <div style={{ display: 'flex', gap: '10px' }}>
                    <button type="submit" disabled={markerForm.processing} style={{ ...inkBtn, padding: '9px 22px', fontSize: '10px', opacity: markerForm.processing ? 0.7 : 1 }}>
                      {markerForm.processing ? 'Saving…' : editingId ? 'Save Marker' : 'Add Marker'}
                    </button>
                    {editingId && <button type="button" onClick={() => deleteMarker(editingId)} style={{ ...dangerBtn, padding: '9px 18px', fontSize: '10px' }}>Delete</button>}
                    <button type="button" onClick={cancelMarkerForm} style={{ ...ghostBtn, padding: '9px 22px', fontSize: '10px' }}>Cancel</button>
                  </div>
                </form>
              )}
            </>
          )}
        </>
      )}
    </Section>
  )
}
