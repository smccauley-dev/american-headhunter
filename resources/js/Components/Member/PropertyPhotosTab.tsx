import { router } from '@inertiajs/react'
import { useState } from 'react'
import {
  Section, INK, ACCENT,
  Modal, DropZone, SelectedFiles, PillToggle, UploadIcon, CheckIcon, XIcon,
  fieldLabel as label, fieldInput as input, modalHelper as mHelper,
  toolbarBtn as ghostBtn, toolbarInkBtn as inkBtn, toolbarDangerBtn as dangerBtn,
  fiGhostBtn as uploadBtn, fiPrimaryBtn as fiPrimary,
} from './PropertyChrome'

export interface Photo {
  id: string
  document_id: string
  caption: string | null
  tags: string[]
  is_primary: boolean
}

/** Photo thumbnail that degrades to a clean placeholder if the file is missing
 * (e.g. seeded rows with no backing file) instead of the browser's broken glyph. */
function Thumb({ documentId, alt }: { documentId: string; alt: string }) {
  const [failed, setFailed] = useState(false)
  if (failed) {
    return (
      <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '7px', color: '#a89874' }}>
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.4} strokeLinecap="round" strokeLinejoin="round"><path d="M2.25 15.75 7.41 10.59a2.25 2.25 0 0 1 3.18 0l4.16 4.16m0 0 1.66-1.66a2.25 2.25 0 0 1 3.18 0L21.75 15M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
        <span style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase' }}>Image unavailable</span>
      </div>
    )
  }
  return <img src={`/property-photos/${documentId}`} alt={alt} onError={() => setFailed(true)} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
}

export default function PropertyPhotosTab({ propertyId, photos }: { propertyId: string; photos: Photo[] }) {
  // Inline caption editing
  const [editing, setEditing] = useState<string | null>(null)
  const [caption, setCaption] = useState('')

  // ── Upload ──────────────────────────────────────────────────────────────────
  // Select-then-submit (mirrors the admin/Map modal): files collect in state via
  // drag/drop or Browse, then SUBMIT posts the batch. SUBMIT is never disabled
  // until a file is chosen — it shows a validation error instead of dead-clicking.
  const [showUpload, setShowUpload] = useState(false)
  const [uploadFiles, setUploadFiles] = useState<File[]>([])
  const [batchCaption, setBatchCaption] = useState('')
  const [importExif, setImportExif] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)

  function addFiles(list: FileList | null) {
    if (!list?.length) return
    setUploadError(null)
    setUploadFiles(prev => [...prev, ...Array.from(list)].slice(0, 20))
  }

  function resetUpload() {
    setUploadFiles([]); setBatchCaption(''); setImportExif(true); setUploadError(null)
  }

  function submitUpload() {
    if (uploadFiles.length === 0) { setUploadError('Please add at least one photo.'); return }
    const fd = new FormData()
    uploadFiles.forEach(f => fd.append('photos[]', f))
    if (batchCaption) fd.append('caption', batchCaption)
    fd.append('import_exif', importExif ? '1' : '0')
    setUploading(true)
    setUploadError(null)
    router.post(`/member/properties/${propertyId}/photos`, fd, {
      preserveScroll: true, forceFormData: true,
      onSuccess: () => { resetUpload(); setShowUpload(false) },
      onError: errs => setUploadError((errs as Record<string, string>).photos ?? 'Upload failed.'),
      onFinish: () => setUploading(false),
    })
  }

  function startEdit(p: Photo) { setEditing(p.id); setCaption(p.caption ?? '') }
  function saveCaption(id: string) {
    router.put(`/member/properties/${propertyId}/photos/${id}`, { caption }, {
      preserveScroll: true, onSuccess: () => setEditing(null),
    })
  }
  function setPrimary(id: string) {
    router.post(`/member/properties/${propertyId}/photos/${id}/primary`, {}, { preserveScroll: true })
  }
  function move(id: string, direction: 'up' | 'down') {
    router.post(`/member/properties/${propertyId}/photos/${id}/move`, { direction }, { preserveScroll: true })
  }
  function remove(id: string) {
    if (!confirm('Delete this photo?')) return
    router.delete(`/member/properties/${propertyId}/photos/${id}`, { preserveScroll: true })
  }

  const galleryDescription = 'Photos shown on the public listing. The cover photo is the first image buyers see — use Set Cover to choose it and the arrows to set display order.'

  const uploadAction = (
    <button type="button" onClick={() => { resetUpload(); setShowUpload(true) }} style={uploadBtn}>
      <UploadIcon />
      Upload Photos
    </button>
  )

  return (
    <Section title="Photo Gallery" description={galleryDescription} action={uploadAction}>
      {photos.length === 0 ? (
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
          No photos yet. Use <strong>Upload Photos</strong> above — the first photo you upload becomes the cover photo.
        </p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px' }}>
          {photos.map((p, i) => (
            <div key={p.id} style={{ border: '1px solid #d4c9b0', background: '#fff' }}>
              <div style={{ position: 'relative', aspectRatio: '4 / 3', overflow: 'hidden', background: '#ece4d4' }}>
                <Thumb documentId={p.document_id} alt={p.caption ?? ''} />
                {p.is_primary && (
                  <span style={{ position: 'absolute', top: '8px', left: '8px', fontFamily: 'var(--mono)', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 8px', background: INK, color: '#F4ECDC' }}>
                    Cover
                  </span>
                )}
              </div>
              <div style={{ padding: '10px' }}>
                {editing === p.id ? (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    <input type="text" value={caption} onChange={e => setCaption(e.target.value)} style={input} maxLength={255} placeholder="Caption" />
                    <div style={{ display: 'flex', gap: '6px' }}>
                      <button type="button" onClick={() => saveCaption(p.id)} style={inkBtn}>Save</button>
                      <button type="button" onClick={() => setEditing(null)} style={ghostBtn}>Cancel</button>
                    </div>
                  </div>
                ) : (
                  <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: p.caption ? INK : '#a89874', margin: '0 0 10px', minHeight: '20px' }}>
                    {p.caption || 'No caption'}
                  </p>
                )}
                {editing !== p.id && (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                    {!p.is_primary && <button type="button" onClick={() => setPrimary(p.id)} style={ghostBtn}>Set Cover</button>}
                    <button type="button" onClick={() => startEdit(p)} style={ghostBtn}>Caption</button>
                    <button type="button" onClick={() => move(p.id, 'up')} disabled={i === 0} style={{ ...ghostBtn, opacity: i === 0 ? 0.35 : 1 }}>↑</button>
                    <button type="button" onClick={() => move(p.id, 'down')} disabled={i === photos.length - 1} style={{ ...ghostBtn, opacity: i === photos.length - 1 ? 0.35 : 1 }}>↓</button>
                    <button type="button" onClick={() => remove(p.id)} style={dangerBtn}>Delete</button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── Upload modal ───────────────────────────────────────────────────────── */}
      {showUpload && (
        <Modal
          title="Upload Property Photos"
          onClose={() => setShowUpload(false)}
          footer={(
            <>
              <button type="button" onClick={submitUpload} disabled={uploading} style={{ ...fiPrimary, opacity: uploading ? 0.6 : 1 }}>
                <CheckIcon />
                {uploading ? 'Uploading…' : 'Submit'}
              </button>
              <button type="button" onClick={() => setShowUpload(false)} style={uploadBtn}>
                <XIcon />
                Cancel
              </button>
            </>
          )}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
            {/* Photos — drag & drop / browse */}
            <div>
              <label style={label}>Photos <span style={{ color: ACCENT }}>*</span></label>
              <DropZone onFiles={addFiles} />
              <SelectedFiles files={uploadFiles} onRemove={i => setUploadFiles(prev => prev.filter((_, idx) => idx !== i))} />
              <div style={mHelper}>JPG, PNG, or WebP — max 15 MB each, up to 20 per batch. The first photo becomes the cover photo.</div>
            </div>

            {/* Caption */}
            <div>
              <label style={label}>Caption</label>
              <input type="text" value={batchCaption} onChange={e => setBatchCaption(e.target.value)} style={input} maxLength={255} />
              <div style={mHelper}>Optional — applied to every photo in this batch. Edit photos individually afterwards.</div>
            </div>

            {/* Import EXIF toggle */}
            <div>
              <PillToggle on={importExif} onChange={setImportExif} label="Import photo metadata (EXIF)" />
              <div style={mHelper}>When on, we read the metadata each camera or phone embeds in a photo — including any GPS coordinates recorded when the picture was taken — and use it to auto-fill the photo's location. Turn it off to ignore that metadata and leave the location blank. Imported coordinates stay private to staff and lessees; they are never shown publicly unless you separately enable that.</div>
            </div>

            {uploadError && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT }}>{uploadError}</div>}
          </div>
        </Modal>
      )}
    </Section>
  )
}
