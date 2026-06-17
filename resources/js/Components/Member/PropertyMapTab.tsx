import { useForm, router } from '@inertiajs/react'
import { useRef, useState } from 'react'
import {
  Section, INK, ACCENT, TAN,
  Modal, SANS, UploadIcon,
  fieldLabel as label, fieldInput as input,
  toolbarBtn as ghostBtn, toolbarActiveBtn as activeBtn, toolbarInkBtn as inkBtn, toolbarDangerBtn as dangerBtn,
  fiGhostBtn as uploadBtn, fiPrimaryBtn as fiPrimary, modalHelper as mHelper,
} from './PropertyChrome'

export interface MapMarker {
  id: string
  label: string
  marker_type: string
  type_label: string
  x_percent: number
  y_percent: number
  color: string
  notes: string | null
  latitude: number | null
  longitude: number | null
}

export interface MapImage {
  id: string
  document_id: string
  description: string | null
  is_boundary: boolean
  latitude: number | null
  longitude: number | null
  show_coords_publicly: boolean
  markers: MapMarker[]
}

export interface DeletedMapImage {
  id: string
  document_id: string
  description: string | null
  deleted_at: string | null
}

const meta: React.CSSProperties = { fontFamily: 'monospace', fontSize: '11px', color: '#9ca3af' }
const restoreBtn: React.CSSProperties = { ...ghostBtn, color: '#065f46', borderColor: '#6ee7b7' }

const DRAG_THRESHOLD = 1.2 // percent of image moved before a press counts as a drag

type MarkerFields = { label: string; marker_type: string; color: string; use_color: boolean; latitude: string; longitude: string; notes: string }
type DetailFields = { description: string; latitude: string; longitude: string; show_coords_publicly: boolean; is_boundary: boolean }

export default function PropertyMapTab({ propertyId, images, deletedImages, markerTypes, markerColors }: {
  propertyId: string
  images: MapImage[]
  deletedImages: DeletedMapImage[]
  markerTypes: Record<string, string>
  markerColors: Record<string, string>
}) {
  const typeKeys = Object.keys(markerTypes)
  const wrapRef = useRef<HTMLDivElement>(null)

  const [selectedId, setSelectedId] = useState<string>(images[0]?.id ?? '')
  const selected = images.find(i => i.id === selectedId) ?? images[0] ?? null

  const [addMode, setAddMode] = useState(false)
  const [override, setOverride] = useState<Record<string, { x: number; y: number }>>({})
  const drag = useRef<{ id: string; startX: number; startY: number; moved: boolean } | null>(null)

  // Modals
  const [showUpload, setShowUpload] = useState(false)
  const [markerModal, setMarkerModal] = useState<{ mode: 'add'; x: number; y: number } | { mode: 'edit'; marker: MapMarker } | null>(null)
  const [showDetails, setShowDetails] = useState(false)

  // ── Upload ──────────────────────────────────────────────────────────────────
  // Select-then-submit (mirrors the admin modal): files are collected by drag/drop
  // or Browse into state, then SUBMIT posts the batch. SUBMIT is never disabled
  // until a selection exists — clicking with no files shows a validation error
  // instead of being a dead, greyed-out button.
  const [description, setDescription] = useState('')
  const [importExif, setImportExif] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [uploadFiles, setUploadFiles] = useState<File[]>([])
  const [dragOver, setDragOver] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  function addFiles(list: FileList | null) {
    if (!list?.length) return
    setUploadError(null)
    setUploadFiles(prev => [...prev, ...Array.from(list)].slice(0, 10))
  }

  function resetUpload() {
    setUploadFiles([]); setDescription(''); setImportExif(true); setUploadError(null)
    if (fileRef.current) fileRef.current.value = ''
  }

  function submitUpload() {
    if (uploadFiles.length === 0) { setUploadError('Please add at least one map image.'); return }
    const fd = new FormData()
    uploadFiles.forEach(f => fd.append('images[]', f))
    if (description) fd.append('description', description)
    fd.append('import_exif', importExif ? '1' : '0')
    setUploading(true)
    setUploadError(null)
    router.post(`/member/properties/${propertyId}/map-images`, fd, {
      preserveScroll: true, forceFormData: true,
      onSuccess: () => { resetUpload(); setShowUpload(false) },
      onError: errs => setUploadError((errs as Record<string, string>).images ?? 'Upload failed.'),
      onFinish: () => setUploading(false),
    })
  }

  // ── Marker form ───────────────────────────────────────────────────────────────
  const markerForm = useForm<MarkerFields>({
    label: '', marker_type: typeKeys[0], color: markerColors[typeKeys[0]] ?? '#374151', use_color: false, latitude: '', longitude: '', notes: '',
  })

  function openAddMarker(x: number, y: number) {
    markerForm.clearErrors()
    markerForm.setData({ label: '', marker_type: typeKeys[0], color: markerColors[typeKeys[0]] ?? '#374151', use_color: false, latitude: '', longitude: '', notes: '' })
    setMarkerModal({ mode: 'add', x, y })
  }
  function openEditMarker(m: MapMarker) {
    markerForm.clearErrors()
    markerForm.setData({
      label: m.label, marker_type: m.marker_type, color: m.color, use_color: m.color !== (markerColors[m.marker_type] ?? '#374151'),
      latitude: m.latitude?.toString() ?? '', longitude: m.longitude?.toString() ?? '', notes: m.notes ?? '',
    })
    setMarkerModal({ mode: 'edit', marker: m })
  }
  function closeMarker() { setMarkerModal(null); markerForm.clearErrors() }

  function markerPayload() {
    const d = markerForm.data
    return {
      label: d.label,
      marker_type: d.marker_type,
      color: d.use_color ? d.color : undefined,
      latitude: d.latitude === '' ? undefined : d.latitude,
      longitude: d.longitude === '' ? undefined : d.longitude,
      notes: d.notes,
    }
  }
  function submitMarker(e: React.FormEvent) {
    e.preventDefault()
    if (!markerModal) return
    if (markerModal.mode === 'add' && selected) {
      router.post(`/member/properties/${propertyId}/map-images/${selected.id}/markers`,
        { ...markerPayload(), x_percent: markerModal.x, y_percent: markerModal.y },
        { preserveScroll: true, onSuccess: closeMarker, onError: errs => markerForm.setError(errs as never) })
    } else if (markerModal.mode === 'edit') {
      router.put(`/member/properties/${propertyId}/markers/${markerModal.marker.id}`, markerPayload(),
        { preserveScroll: true, onSuccess: closeMarker, onError: errs => markerForm.setError(errs as never) })
    }
  }
  function deleteMarker(id: string) {
    if (!confirm('Delete this marker?')) return
    router.delete(`/member/properties/${propertyId}/markers/${id}`, { preserveScroll: true, onSuccess: closeMarker })
  }

  // ── Edit details form ─────────────────────────────────────────────────────────
  const detailForm = useForm<DetailFields>({ description: '', latitude: '', longitude: '', show_coords_publicly: false, is_boundary: false })
  function openDetails() {
    if (!selected) return
    detailForm.clearErrors()
    detailForm.setData({
      description: selected.description ?? '',
      latitude: selected.latitude?.toString() ?? '',
      longitude: selected.longitude?.toString() ?? '',
      show_coords_publicly: selected.show_coords_publicly,
      is_boundary: selected.is_boundary,
    })
    setShowDetails(true)
  }
  function submitDetails(e: React.FormEvent) {
    e.preventDefault()
    if (!selected) return
    router.put(`/member/properties/${propertyId}/map-images/${selected.id}`, {
      description: detailForm.data.description,
      latitude: detailForm.data.latitude === '' ? undefined : detailForm.data.latitude,
      longitude: detailForm.data.longitude === '' ? undefined : detailForm.data.longitude,
      show_coords_publicly: detailForm.data.show_coords_publicly,
      is_boundary: detailForm.data.is_boundary,
    }, { preserveScroll: true, onSuccess: () => setShowDetails(false), onError: errs => detailForm.setError(errs as never) })
  }

  // ── Image ops ─────────────────────────────────────────────────────────────────
  function deleteImage(id: string) {
    if (!confirm('Delete this map image and its markers?')) return
    router.delete(`/member/properties/${propertyId}/map-images/${id}`, {
      preserveScroll: true,
      onSuccess: () => { if (selectedId === id) setSelectedId(images.find(i => i.id !== id)?.id ?? '') },
    })
  }
  function restoreImage(id: string) {
    router.post(`/member/properties/${propertyId}/map-images/${id}/restore`, {}, { preserveScroll: true })
  }

  // ── Marker drag / canvas click ──────────────────────────────────────────────────
  function pct(e: React.PointerEvent | React.MouseEvent): { x: number; y: number } {
    const rect = wrapRef.current!.getBoundingClientRect()
    const x = Math.min(100, Math.max(0, ((e.clientX - rect.left) / rect.width) * 100))
    const y = Math.min(100, Math.max(0, ((e.clientY - rect.top) / rect.height) * 100))
    return { x, y }
  }
  function onCanvasClick(e: React.MouseEvent) {
    if (!addMode) return
    const p = pct(e)
    setAddMode(false)
    openAddMarker(p.x, p.y)
  }
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
    if (drag.current.moved) setOverride(o => ({ ...o, [drag.current!.id]: { x: p.x, y: p.y } }))
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
      openEditMarker(m)
    }
  }

  const uploadAction = (
    <button type="button" onClick={() => { resetUpload(); setShowUpload(true) }} style={uploadBtn}>
      <UploadIcon />
      Upload Map Images
    </button>
  )

  const boundaryDescription = 'The boundary map is shown on the public listing (without markers). Add markers for amenities, game locations, stands, and other points of interest — markers are admin/member only.'

  return (
    <Section title="Boundary Map" description={boundaryDescription} action={uploadAction}>
      {images.length === 0 && deletedImages.length === 0 ? (
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
          No map images yet. Use <strong>Upload Map Images</strong> above — the first upload becomes the boundary map.
        </p>
      ) : null}

      {selected && (
        <>
          {/* Toolbar */}
          <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px', marginBottom: '10px' }}>
            <button type="button" onClick={() => setAddMode(a => !a)} style={addMode ? activeBtn : ghostBtn}>
              {addMode ? '✛ Click the map to place — or cancel' : '✛ Add Marker'}
            </button>
            <button type="button" onClick={openDetails} style={ghostBtn}>Edit Details</button>
            <a href={`/member/properties/${propertyId}/map-images/${selected.id}/download`} style={ghostBtn}>Download</a>
            <button type="button" onClick={() => deleteImage(selected.id)} style={dangerBtn}>Delete</button>
            <span style={{ ...meta, marginLeft: 'auto' }}>
              {selected.markers.length} marker{selected.markers.length === 1 ? '' : 's'} · drag a pin to move it · click a pin to edit
            </span>
          </div>

          {/* Canvas */}
          <div
            ref={wrapRef}
            onClick={onCanvasClick}
            onPointerMove={onPinPointerMove}
            style={{ position: 'relative', border: `1px solid ${INK}`, overflow: 'hidden', background: '#ece4d4', userSelect: 'none', cursor: addMode ? 'crosshair' : 'default', touchAction: 'none' }}
          >
            <img src={`/member/properties/${propertyId}/map-images/${selected.document_id}`} alt={selected.description ?? 'Property map'} draggable={false} style={{ display: 'block', width: '100%', height: 'auto', pointerEvents: 'none' }} />

            {selected.is_boundary && (
              <span style={{ position: 'absolute', top: '10px', left: '10px', background: INK, color: '#fff', border: `1px solid ${TAN}`, fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', lineHeight: 1.4, whiteSpace: 'nowrap', zIndex: 10 }}>Boundary Map</span>
            )}

            {selected.markers.map(m => {
              const pos = override[m.id] ?? { x: m.x_percent, y: m.y_percent }
              return (
                <div
                  key={m.id}
                  onPointerDown={e => onPinPointerDown(e, m)}
                  onPointerUp={e => onPinPointerUp(e, m)}
                  onClick={e => e.stopPropagation()}
                  title={`${m.label} (${m.type_label})`}
                  style={{ position: 'absolute', left: `${pos.x}%`, top: `${pos.y}%`, transform: 'translate(-50%, -50%)', zIndex: 5, cursor: 'grab', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '2px' }}
                >
                  <span style={{ display: 'block', width: '14px', height: '14px', borderRadius: '50%', background: m.color, border: '2px solid #fff', boxShadow: '0 1px 4px rgba(0,0,0,0.45)' }} />
                  <span style={{ background: 'rgba(10,21,18,0.8)', color: '#fff', fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.05em', padding: '2px 6px', whiteSpace: 'nowrap', maxWidth: '160px', overflow: 'hidden', textOverflow: 'ellipsis', pointerEvents: 'none' }}>{m.label}</span>
                </div>
              )
            })}
          </div>

          {/* Selected image meta */}
          <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '14px', marginTop: '10px' }}>
            <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: selected.description ? '#374151' : '#9ca3af', fontStyle: selected.description ? 'normal' : 'italic' }}>
              {selected.description || 'No description'}
            </span>
            {selected.latitude !== null && selected.longitude !== null && (
              <a href={`https://maps.google.com/?q=${selected.latitude},${selected.longitude}`} target="_blank" rel="noopener noreferrer" style={{ ...meta, color: '#6b7280', textDecoration: 'none' }}>
                📍 {selected.latitude.toFixed(6)}, {selected.longitude.toFixed(6)}
              </a>
            )}
          </div>
        </>
      )}

      {/* All Map Images — carousel */}
      {images.length > 1 && (
        <div style={{ marginTop: '18px' }}>
          <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.08em', marginBottom: '8px' }}>All Map Images</div>
          <div style={{ display: 'flex', gap: '10px', overflowX: 'auto', paddingBottom: '6px' }}>
            {images.map(img => (
              <div key={img.id} onClick={() => { setSelectedId(img.id); setAddMode(false) }}
                style={{ flex: '0 0 150px', cursor: 'pointer', border: `2px solid ${selected && img.id === selected.id ? TAN : '#e5e0d8'}`, overflow: 'hidden', background: '#fff' }}>
                <div style={{ position: 'relative', aspectRatio: '16/10', background: '#ece4d4' }}>
                  <img src={`/member/properties/${propertyId}/map-images/${img.document_id}`} alt={img.description ?? 'Map image'} loading="lazy" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />
                  {img.is_boundary && (
                    <span style={{ position: 'absolute', top: '4px', left: '4px', background: INK, color: '#fff', fontFamily: 'var(--mono)', fontSize: '8px', fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', padding: '2px 5px', lineHeight: 1.3 }}>Boundary</span>
                  )}
                </div>
                <div style={{ padding: '5px 8px', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '12px', color: '#6b7280', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {img.description || 'No description'}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Deleted map images — recovery section */}
      {deletedImages.length > 0 && (
        <details style={{ marginTop: '14px' }}>
          <summary style={{ cursor: 'pointer', fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.08em', userSelect: 'none' }}>
            {deletedImages.length === 1 ? '1 deleted map image' : `${deletedImages.length} deleted map images`} — click to expand
          </summary>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', marginTop: '10px' }}>
            {deletedImages.map(img => (
              <div key={img.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 12px', background: '#fafafa', border: '1px solid #e5e7eb', opacity: 0.85 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', minWidth: 0 }}>
                  <img src={`/member/properties/${propertyId}/map-images/${img.document_id}`} alt="" style={{ width: '56px', height: '36px', objectFit: 'cover', flexShrink: 0 }} />
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', fontWeight: 600, color: '#6b7280', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{img.description || 'No description'}</div>
                    <div style={{ ...meta }}>Deleted {img.deleted_at} — markers preserved</div>
                  </div>
                </div>
                <button type="button" onClick={() => restoreImage(img.id)} style={{ ...restoreBtn, flexShrink: 0, marginLeft: '12px' }}>Restore</button>
              </div>
            ))}
          </div>
        </details>
      )}

      {/* ── Upload modal ───────────────────────────────────────────────────────── */}
      {showUpload && (
        <Modal
          title="Upload Map Images"
          onClose={() => setShowUpload(false)}
          footer={(
            <>
              <button type="button" onClick={submitUpload} disabled={uploading} style={{ ...fiPrimary, opacity: uploading ? 0.6 : 1 }}>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                {uploading ? 'Uploading…' : 'Submit'}
              </button>
              <button type="button" onClick={() => setShowUpload(false)} style={uploadBtn}>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M6 18 18 6M6 6l12 12" /></svg>
                Cancel
              </button>
            </>
          )}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
            {/* Map Images — drag & drop / browse */}
            <div>
              <label style={label}>Map Images <span style={{ color: ACCENT }}>*</span></label>
              <div
                onClick={() => fileRef.current?.click()}
                onDragOver={e => { e.preventDefault(); setDragOver(true) }}
                onDragLeave={() => setDragOver(false)}
                onDrop={e => { e.preventDefault(); setDragOver(false); addFiles(e.dataTransfer.files) }}
                style={{
                  border: `1px dashed ${dragOver ? INK : TAN}`,
                  background: dragOver ? 'rgba(10,21,18,0.03)' : '#faf7f2',
                  padding: '28px 16px', textAlign: 'center', cursor: 'pointer',
                }}
              >
                <div style={{ fontFamily: SANS, fontSize: '13px', color: '#6b5e50' }}>
                  Drag &amp; Drop your files or <span style={{ color: ACCENT, textDecoration: 'underline' }}>Browse</span>
                </div>
              </div>
              {uploadFiles.length > 0 && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', marginTop: '10px' }}>
                  {uploadFiles.map((f, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', fontFamily: 'var(--mono)', fontSize: '11px', color: INK }}>
                      <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.name}</span>
                      <button type="button" onClick={() => setUploadFiles(prev => prev.filter((_, idx) => idx !== i))} style={{ border: 'none', background: 'none', cursor: 'pointer', color: '#9ca3af', fontSize: '15px', lineHeight: 1, flexShrink: 0 }}>×</button>
                    </div>
                  ))}
                </div>
              )}
              <div style={mHelper}>JPG, PNG, or WebP — max 15 MB each. The first map image on a property becomes the boundary map.</div>
            </div>

            {/* Description */}
            <div>
              <label style={label}>Description</label>
              <input type="text" value={description} onChange={e => setDescription(e.target.value)} style={input} maxLength={255} />
              <div style={mHelper}>Optional — applied to every image in this batch. Edit individually afterwards.</div>
            </div>

            {/* Import EXIF toggle */}
            <div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <button
                  type="button" role="switch" aria-checked={importExif} onClick={() => setImportExif(v => !v)}
                  style={{ position: 'relative', width: '40px', height: '22px', borderRadius: '999px', border: 'none', cursor: 'pointer', flexShrink: 0, background: importExif ? '#6b7856' : '#c9bfa9', transition: 'background .15s' }}
                >
                  <span style={{ position: 'absolute', top: '2px', left: importExif ? '20px' : '2px', width: '18px', height: '18px', borderRadius: '50%', background: '#fff', transition: 'left .15s', boxShadow: '0 1px 2px rgba(0,0,0,0.2)' }} />
                </button>
                <span style={{ ...label, marginBottom: 0 }}>Import image metadata (EXIF)</span>
              </div>
              <div style={mHelper}>When on, we read the metadata each camera or phone embeds in a photo — including any GPS coordinates recorded when the picture was taken — and use it to auto-fill the photo's location. Turn it off to ignore that metadata and leave the location blank. Imported coordinates stay private to staff and lessees; they are never shown publicly unless you separately enable that.</div>
            </div>

            {uploadError && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT }}>{uploadError}</div>}

            <input ref={fileRef} type="file" accept="image/*" multiple style={{ display: 'none' }} onChange={e => { addFiles(e.target.files); if (fileRef.current) fileRef.current.value = '' }} />
          </div>
        </Modal>
      )}

      {/* ── Marker modal (add / edit) ───────────────────────────────────────────── */}
      {markerModal && (
        <Modal title={markerModal.mode === 'edit' ? 'Edit Marker' : 'Add Marker'} onClose={closeMarker}>
          <form onSubmit={submitMarker} style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            <div>
              <label style={label}>Label</label>
              <input type="text" value={markerForm.data.label} onChange={e => markerForm.setData('label', e.target.value)} style={input} maxLength={120} placeholder="North gate, Box blind #3…" />
              {markerForm.errors.label && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{markerForm.errors.label}</div>}
            </div>
            <div>
              <label style={label}>Type</label>
              <select value={markerForm.data.marker_type}
                onChange={e => {
                  const t = e.target.value
                  markerForm.setData(d => ({ ...d, marker_type: t, color: d.use_color ? d.color : (markerColors[t] ?? '#374151') }))
                }}
                style={input}>
                {typeKeys.map(k => <option key={k} value={k}>{markerTypes[k]}</option>)}
              </select>
            </div>
            <div>
              <label style={{ ...label, display: 'flex', alignItems: 'center', gap: '8px', textTransform: 'none', letterSpacing: 0, fontSize: '13px', color: '#6b5e50' }}>
                <input type="checkbox" checked={markerForm.data.use_color} onChange={e => markerForm.setData('use_color', e.target.checked)} />
                Custom color (otherwise uses the type’s default)
              </label>
              {markerForm.data.use_color && (
                <input type="color" value={markerForm.data.color} onChange={e => markerForm.setData('color', e.target.value)} style={{ width: '60px', height: '34px', padding: 0, border: '1px solid #d4c9b0', background: '#fff', cursor: 'pointer' }} />
              )}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <label style={label}>Latitude (optional)</label>
                <input type="number" step="any" value={markerForm.data.latitude} onChange={e => markerForm.setData('latitude', e.target.value)} style={input} placeholder="34.123456" />
                {markerForm.errors.latitude && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{markerForm.errors.latitude}</div>}
              </div>
              <div>
                <label style={label}>Longitude (optional)</label>
                <input type="number" step="any" value={markerForm.data.longitude} onChange={e => markerForm.setData('longitude', e.target.value)} style={input} placeholder="-84.123456" />
                {markerForm.errors.longitude && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{markerForm.errors.longitude}</div>}
              </div>
            </div>
            <div>
              <label style={label}>Notes</label>
              <textarea rows={2} value={markerForm.data.notes} onChange={e => markerForm.setData('notes', e.target.value)} style={{ ...input, resize: 'vertical' }} maxLength={500} />
            </div>
            <div style={{ display: 'flex', gap: '10px' }}>
              <button type="submit" disabled={markerForm.processing} style={{ ...inkBtn, padding: '9px 22px', fontSize: '10px', opacity: markerForm.processing ? 0.7 : 1 }}>
                {markerForm.processing ? 'Saving…' : markerModal.mode === 'edit' ? 'Save Marker' : 'Add Marker'}
              </button>
              {markerModal.mode === 'edit' && <button type="button" onClick={() => deleteMarker(markerModal.marker.id)} style={{ ...dangerBtn, padding: '9px 18px', fontSize: '10px' }}>Delete</button>}
              <button type="button" onClick={closeMarker} style={{ ...ghostBtn, padding: '9px 22px', fontSize: '10px' }}>Cancel</button>
            </div>
          </form>
        </Modal>
      )}

      {/* ── Edit details modal ──────────────────────────────────────────────────── */}
      {showDetails && selected && (
        <Modal title="Edit Map Image" onClose={() => setShowDetails(false)}>
          <form onSubmit={submitDetails} style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            <div>
              <label style={label}>Description</label>
              <textarea rows={2} value={detailForm.data.description} onChange={e => detailForm.setData('description', e.target.value)} style={{ ...input, resize: 'vertical' }} maxLength={255} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
              <div>
                <label style={label}>Latitude</label>
                <input type="number" step="any" value={detailForm.data.latitude} onChange={e => detailForm.setData('latitude', e.target.value)} style={input} placeholder="34.123456" />
                {detailForm.errors.latitude && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{detailForm.errors.latitude}</div>}
              </div>
              <div>
                <label style={label}>Longitude</label>
                <input type="number" step="any" value={detailForm.data.longitude} onChange={e => detailForm.setData('longitude', e.target.value)} style={input} placeholder="-84.123456" />
                {detailForm.errors.longitude && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '4px' }}>{detailForm.errors.longitude}</div>}
              </div>
            </div>
            <label style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer', fontFamily: 'var(--body)', fontSize: '14px', color: '#6b5e50' }}>
              <input type="checkbox" checked={detailForm.data.show_coords_publicly} onChange={e => detailForm.setData('show_coords_publicly', e.target.checked)} />
              Show coordinates on the public listing
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: selected.is_boundary ? 'default' : 'pointer', fontFamily: 'var(--body)', fontSize: '14px', color: selected.is_boundary ? '#9ca3af' : '#6b5e50' }}>
              <input type="checkbox" checked={detailForm.data.is_boundary} disabled={selected.is_boundary} onChange={e => detailForm.setData('is_boundary', e.target.checked)} />
              {selected.is_boundary ? 'This is the boundary map' : 'Make this the boundary map'}
            </label>
            <div style={{ display: 'flex', gap: '10px' }}>
              <button type="submit" disabled={detailForm.processing} style={{ ...inkBtn, padding: '9px 22px', fontSize: '10px', opacity: detailForm.processing ? 0.7 : 1 }}>
                {detailForm.processing ? 'Saving…' : 'Save'}
              </button>
              <button type="button" onClick={() => setShowDetails(false)} style={{ ...ghostBtn, padding: '9px 22px', fontSize: '10px' }}>Cancel</button>
            </div>
          </form>
        </Modal>
      )}
    </Section>
  )
}
