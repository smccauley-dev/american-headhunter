import { router } from '@inertiajs/react'
import { useState } from 'react'
import {
  Section, INK, ACCENT, TAN,
  Modal, DropZone, SelectedFiles, PillToggle, UploadIcon, CheckIcon, XIcon,
  fieldLabel as label, fieldInput as input, modalHelper as mHelper,
  toolbarBtn as ghostBtn, toolbarDangerBtn as dangerBtn,
  fiGhostBtn as uploadBtn, fiPrimaryBtn as fiPrimary,
} from './PropertyChrome'

export interface Photo {
  id: string
  document_id: string
  caption: string | null
  tags: string[]
  is_primary: boolean
  latitude: number | null
  longitude: number | null
}

/** Mirrors the admin photo tag suggestions (PropertyFormV2::photoTagSuggestions). */
const TAG_SUGGESTIONS = ['aerial', 'habitat', 'food plot', 'stand', 'blind', 'trail camera', 'water', 'creek', 'pond', 'access', 'road', 'gate', 'cabin', 'lodging', 'harvest', 'wildlife', 'boundary', 'terrain']

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

/** Tag editor — type a tag, Enter to add, × to remove (mirrors Filament TagsInput). */
function TagsField({ tags, onChange }: { tags: string[]; onChange: (t: string[]) => void }) {
  const [draft, setDraft] = useState('')
  function add() {
    const t = draft.trim()
    if (t && !tags.includes(t)) onChange([...tags, t])
    setDraft('')
  }
  return (
    <div style={{ border: `1px solid ${TAN}`, background: '#fff' }}>
      <input
        type="text" value={draft} list="photo-tag-suggestions"
        onChange={e => setDraft(e.target.value)}
        onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); add() } }}
        onBlur={add}
        placeholder="New tag"
        style={{ ...input, border: 'none', background: 'transparent' }}
      />
      <datalist id="photo-tag-suggestions">
        {TAG_SUGGESTIONS.map(s => <option key={s} value={s} />)}
      </datalist>
      {tags.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', padding: '8px', borderTop: `1px solid ${TAN}` }}>
          {tags.map(t => (
            <span key={t} style={{ display: 'inline-flex', alignItems: 'center', gap: '5px', fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.04em', textTransform: 'uppercase', color: '#6b5d40', background: '#f5f1eb', border: '1px solid #e5e0d8', borderRadius: '999px', padding: '2px 7px' }}>
              {t}
              <button type="button" onClick={() => onChange(tags.filter(x => x !== t))} style={{ border: 'none', background: 'none', cursor: 'pointer', color: '#9a8c6a', fontSize: '12px', lineHeight: 1, padding: 0 }}>×</button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

export default function PropertyPhotosTab({ propertyId, photos }: { propertyId: string; photos: Photo[] }) {
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

  // ── Edit photo details ────────────────────────────────────────────────────────
  const [editing, setEditing] = useState<Photo | null>(null)
  const [eCaption, setECaption] = useState('')
  const [eTags, setETags] = useState<string[]>([])
  const [eLat, setELat] = useState('')
  const [eLng, setELng] = useState('')
  const [ePrimary, setEPrimary] = useState(false)
  const [saving, setSaving] = useState(false)

  function openEdit(p: Photo) {
    setEditing(p)
    setECaption(p.caption ?? '')
    setETags(p.tags ?? [])
    setELat(p.latitude !== null ? String(p.latitude) : '')
    setELng(p.longitude !== null ? String(p.longitude) : '')
    setEPrimary(p.is_primary)
  }

  function submitEdit() {
    if (!editing) return
    setSaving(true)
    router.put(`/member/properties/${propertyId}/photos/${editing.id}`, {
      caption: eCaption.trim() || null,
      tags: eTags,
      latitude: eLat.trim() === '' ? null : eLat.trim(),
      longitude: eLng.trim() === '' ? null : eLng.trim(),
      is_primary: ePrimary,
    }, {
      preserveScroll: true,
      onSuccess: () => setEditing(null),
      onFinish: () => setSaving(false),
    })
  }

  function move(id: string, direction: 'up' | 'down') {
    router.post(`/member/properties/${propertyId}/photos/${id}/move`, { direction }, { preserveScroll: true })
  }
  function remove(id: string) {
    if (!confirm('Delete this photo?')) return
    router.delete(`/member/properties/${propertyId}/photos/${id}`, { preserveScroll: true })
  }

  const galleryDescription = 'Photos shown on the public listing. The primary photo is the cover image buyers see first — open Edit to set it, plus captions, tags and location, and use the arrows to set display order.'

  const uploadAction = (
    <button type="button" onClick={() => { resetUpload(); setShowUpload(true) }} style={uploadBtn}>
      <UploadIcon />
      Upload Photos
    </button>
  )

  const total = photos.length

  return (
    <Section title="Photo Gallery" description={galleryDescription} action={uploadAction}>
      {total === 0 ? (
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
          No photos yet. Use <strong>Upload Photos</strong> above — the first photo you upload becomes the cover photo.
        </p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: '16px' }}>
          {photos.map((p, i) => (
            <div key={p.id} style={{ border: '1px solid #d4c9b0', background: '#fff', display: 'flex', flexDirection: 'column' }}>
              <div style={{ position: 'relative', aspectRatio: '16 / 10', overflow: 'hidden', background: '#ece4d4' }}>
                <Thumb documentId={p.document_id} alt={p.caption ?? ''} />
                {p.is_primary && (
                  <span style={{ position: 'absolute', top: '8px', left: '8px', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', background: INK, color: '#fff', border: `1px solid ${TAN}` }}>
                    ★ Primary
                  </span>
                )}
                <span style={{ position: 'absolute', top: '8px', right: '8px', fontFamily: 'var(--mono)', fontSize: '9px', padding: '2px 7px', background: 'rgba(10,21,18,0.65)', color: '#fff' }}>
                  {String(i + 1).padStart(2, '0')} / {String(total).padStart(2, '0')}
                </span>
              </div>
              <div style={{ padding: '10px', display: 'flex', flexDirection: 'column', gap: '8px', flex: 1 }}>
                <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', fontStyle: p.caption ? 'normal' : 'italic', color: p.caption ? INK : '#a89874', margin: 0, minHeight: '20px' }}>
                  {p.caption || 'No caption'}
                </p>

                {p.tags.length > 0 && (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '5px' }}>
                    {p.tags.map(t => (
                      <span key={t} style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.04em', textTransform: 'uppercase', color: '#6b5d40', background: '#f5f1eb', border: '1px solid #e5e0d8', borderRadius: '999px', padding: '2px 7px' }}>{t}</span>
                    ))}
                  </div>
                )}

                <div style={{ fontFamily: 'var(--mono)', fontSize: '11px' }}>
                  {p.latitude !== null && p.longitude !== null ? (
                    <a href={`https://www.google.com/maps?q=${p.latitude},${p.longitude}`} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', color: ACCENT, textDecoration: 'none' }}>
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0Z" /><circle cx="12" cy="10" r="3" /></svg>
                      {p.latitude.toFixed(6)}, {p.longitude.toFixed(6)}
                    </a>
                  ) : (
                    <span style={{ color: '#c4bdac' }}>No location</span>
                  )}
                </div>

                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: 'auto', paddingTop: '4px' }}>
                  <button type="button" onClick={() => move(p.id, 'up')} disabled={i === 0} title="Move earlier" style={{ ...ghostBtn, opacity: i === 0 ? 0.35 : 1 }}>←</button>
                  <button type="button" onClick={() => move(p.id, 'down')} disabled={i === total - 1} title="Move later" style={{ ...ghostBtn, opacity: i === total - 1 ? 0.35 : 1 }}>→</button>
                  <button type="button" onClick={() => openEdit(p)} style={ghostBtn}>Edit</button>
                  <button type="button" onClick={() => remove(p.id)} style={dangerBtn}>Delete</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── Edit photo details modal ────────────────────────────────────────────── */}
      {editing && (
        <Modal
          title="Edit Photo Details"
          onClose={() => setEditing(null)}
          footer={(
            <>
              <button type="button" onClick={submitEdit} disabled={saving} style={{ ...fiPrimary, opacity: saving ? 0.6 : 1 }}>
                <CheckIcon />
                {saving ? 'Saving…' : 'Submit'}
              </button>
              <button type="button" onClick={() => setEditing(null)} style={uploadBtn}>
                <XIcon />
                Cancel
              </button>
            </>
          )}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
            {/* Caption / Description */}
            <div>
              <label style={label}>Caption / Description</label>
              <textarea value={eCaption} onChange={e => setECaption(e.target.value)} maxLength={255} rows={3} style={{ ...input, minHeight: '70px', resize: 'vertical' }} />
            </div>

            {/* Tags */}
            <div>
              <label style={label}>Tags</label>
              <TagsField tags={eTags} onChange={setETags} />
              <div style={mHelper}>Press Enter after each tag. Used for gallery filtering.</div>
            </div>

            {/* GPS */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
              <div>
                <label style={label}>Latitude</label>
                <input type="text" inputMode="decimal" value={eLat} onChange={e => setELat(e.target.value)} placeholder="30.267153" style={input} />
                <div style={mHelper}>Where the photo was taken (WGS84). Auto-filled from the photo's EXIF GPS data when available.</div>
              </div>
              <div>
                <label style={label}>Longitude</label>
                <input type="text" inputMode="decimal" value={eLng} onChange={e => setELng(e.target.value)} placeholder="-97.743057" style={input} />
                <div style={mHelper}>Negative values are West.</div>
              </div>
            </div>

            {/* Primary toggle */}
            <div>
              <PillToggle on={ePrimary} onChange={setEPrimary} disabled={editing.is_primary} label="Primary (cover) photo" />
              <div style={mHelper}>
                {editing.is_primary
                  ? 'This is the current primary photo. Set another photo as primary to change it.'
                  : 'Make this the cover photo shown on the public listing.'}
              </div>
            </div>
          </div>
        </Modal>
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
