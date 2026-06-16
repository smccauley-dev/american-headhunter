import { useForm, router } from '@inertiajs/react'
import { useState, useRef } from 'react'
import { Section, INK, ACCENT } from './PropertyChrome'

export interface Photo {
  id: string
  document_id: string
  caption: string | null
  tags: string[]
  is_primary: boolean
}

const ghostBtn: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em',
  textTransform: 'uppercase', padding: '6px 11px', background: 'transparent',
  color: INK, border: '1px solid #d4c9b0', cursor: 'pointer',
}

const input: React.CSSProperties = {
  width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: INK,
  background: '#fff', border: '1px solid #d4c9b0', padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
}

export default function PropertyPhotosTab({ propertyId, photos }: { propertyId: string; photos: Photo[] }) {
  const fileRef = useRef<HTMLInputElement>(null)
  const [editing, setEditing] = useState<string | null>(null)
  const [caption, setCaption] = useState('')

  const upload = useForm<{ photos: File[]; caption: string; import_exif: boolean }>({
    photos: [], caption: '', import_exif: true,
  })

  function submitUpload(e: React.FormEvent) {
    e.preventDefault()
    if (upload.data.photos.length === 0) return
    upload.post(`/member/properties/${propertyId}/photos`, {
      preserveScroll: true, forceFormData: true,
      onSuccess: () => { upload.reset(); if (fileRef.current) fileRef.current.value = '' },
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

  return (
    <Section title="Photos">
      <form onSubmit={submitUpload} style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginBottom: '22px', borderBottom: '1px solid #e5ddd0', paddingBottom: '20px' }}>
        <input
          ref={fileRef}
          type="file"
          accept="image/*"
          multiple
          onChange={e => upload.setData('photos', Array.from(e.target.files ?? []))}
          style={{ fontFamily: 'var(--mono)', fontSize: '12px', color: INK }}
        />
        <input
          type="text"
          value={upload.data.caption}
          onChange={e => upload.setData('caption', e.target.value)}
          style={input}
          placeholder="Caption applied to this batch (optional)"
          maxLength={255}
        />
        <label style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer', fontFamily: 'var(--body)', fontSize: '14px', color: '#6b5e50' }}>
          <input type="checkbox" checked={upload.data.import_exif} onChange={e => upload.setData('import_exif', e.target.checked)} />
          Import location from photo metadata (EXIF GPS)
        </label>
        {upload.errors.photos && <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT }}>{upload.errors.photos}</div>}
        <div>
          <button type="submit" disabled={upload.processing || upload.data.photos.length === 0} style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 22px', background: INK, color: '#F4ECDC', border: 'none', cursor: upload.processing || upload.data.photos.length === 0 ? 'not-allowed' : 'pointer', opacity: upload.processing || upload.data.photos.length === 0 ? 0.6 : 1 }}>
            {upload.processing ? 'Uploading…' : 'Upload Photos'}
          </button>
        </div>
      </form>

      {photos.length === 0 ? (
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
          No photos yet. The first photo you upload becomes the cover photo.
        </p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px' }}>
          {photos.map((p, i) => (
            <div key={p.id} style={{ border: '1px solid #d4c9b0', background: '#fff' }}>
              <div style={{ position: 'relative', aspectRatio: '4 / 3', overflow: 'hidden', background: '#ece4d4' }}>
                <img src={`/property-photos/${p.document_id}`} alt={p.caption ?? ''} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
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
                      <button type="button" onClick={() => saveCaption(p.id)} style={{ ...ghostBtn, background: INK, color: '#F4ECDC', borderColor: INK }}>Save</button>
                      <button type="button" onClick={() => setEditing(null)} style={ghostBtn}>Cancel</button>
                    </div>
                  </div>
                ) : (
                  <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: p.caption ? INK : '#a89874', margin: '0 0 10px', minHeight: '20px' }}>
                    {p.caption || 'No caption'}
                  </p>
                )}
                {editing !== p.id && (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '5px' }}>
                    {!p.is_primary && <button type="button" onClick={() => setPrimary(p.id)} style={ghostBtn}>Set Cover</button>}
                    <button type="button" onClick={() => startEdit(p)} style={ghostBtn}>Caption</button>
                    <button type="button" onClick={() => move(p.id, 'up')} disabled={i === 0} style={{ ...ghostBtn, opacity: i === 0 ? 0.35 : 1 }}>↑</button>
                    <button type="button" onClick={() => move(p.id, 'down')} disabled={i === photos.length - 1} style={{ ...ghostBtn, opacity: i === photos.length - 1 ? 0.35 : 1 }}>↓</button>
                    <button type="button" onClick={() => remove(p.id)} style={{ ...ghostBtn, color: ACCENT, borderColor: 'rgba(200,76,33,0.4)' }}>Delete</button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </Section>
  )
}
