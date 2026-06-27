import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import FilePondUploader from '../FilePondUploader'
import {
  Section, DocumentCheckIcon, INK, ACCENT, TAN,
  fieldLabel as label, fieldInput as input, modalHelper as helper,
  fiPrimaryBtn as primaryBtn,
} from './PropertyChrome'

export interface ProofDocument {
  id: string
  filename: string
  is_image: boolean
}

export interface OwnershipVerification {
  id: string
  owner_type: string
  owner_type_label: string
  entity_name: string | null
  status: 'submitted' | 'pending' | 'approved' | 'rejected'
  certification_name: string
  certified_at: string | null
  reviewed_at: string | null
  review_notes: string | null
  documents: ProofDocument[]
}

interface Props {
  propertyId: string
  ownership: OwnershipVerification | null
  ownerTypes: Record<string, string>
  suggestedProof: Record<string, string[]>
}

const errStyle: React.CSSProperties = {
  fontFamily: 'var(--mono)', fontSize: '10px', color: ACCENT, marginTop: '5px',
}

/** Status pill mirroring the DL / hunting-license verification chip. */
function StatusBadge({ status }: { status: OwnershipVerification['status'] }) {
  const map: Record<string, { text: string; bg: string; fg: string; border: string }> = {
    submitted: { text: 'Submitted',    bg: '#eef1fb', fg: '#3a3f86', border: '#cdd4f0' },
    pending:   { text: 'Under Review', bg: '#fdf6e3', fg: '#8a6d1a', border: '#e6d8a8' },
    approved:  { text: 'Approved ✓',   bg: '#edf6ef', fg: '#2f6b43', border: '#bcdcc6' },
    rejected:  { text: 'Rejected',     bg: '#fbecea', fg: '#a23a25', border: '#e8c4ba' },
  }
  const s = map[status]
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '4px 11px', background: s.bg, color: s.fg, border: `1px solid ${s.border}` }}>
      {s.text}
    </span>
  )
}

/** A single uploaded proof document — image preview thumbnail or a file chip, both linking to the served file. */
function DocChip({ propertyId, doc }: { propertyId: string; doc: ProofDocument }) {
  const url = `/member/properties/${propertyId}/ownership/documents/${doc.id}`
  return (
    <a href={url} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-flex', alignItems: 'center', gap: '8px', fontFamily: 'var(--mono)', fontSize: '11px', color: INK, background: '#fff', border: `1px solid ${TAN}`, padding: '7px 11px', textDecoration: 'none' }}>
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke={ACCENT} strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round">
        {doc.is_image
          ? <><rect x="3" y="3" width="18" height="18" rx="1" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></>
          : <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /></>}
      </svg>
      <span style={{ maxWidth: '220px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{doc.filename}</span>
    </a>
  )
}

export default function OwnershipSection({ propertyId, ownership, ownerTypes, suggestedProof }: Props) {
  const pondRef = useRef<any>(null)

  const [ownerType, setOwnerType] = useState<string>(ownership?.owner_type ?? 'individual')
  const [entityName, setEntityName] = useState<string>(ownership?.entity_name ?? '')
  const [certName, setCertName] = useState<string>('')
  const [certified, setCertified] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  // FilePond's built-in image-preview canvas comes up blank in this inline uploader
  // (it reserves the preview slot but never paints the bitmap — no JS error), so we
  // suppress it (allowImagePreview={false}) and render our own thumbnails straight
  // from the dropped File objects via object URLs. previewsRef mirrors state so we
  // can revoke the URLs on unmount without a stale closure.
  const [previews, setPreviews] = useState<{ key: string; name: string; url: string | null }[]>([])
  const previewsRef = useRef<{ key: string; name: string; url: string | null }[]>([])

  function onFilesChange(items: any[]) {
    setFormError(null)
    previewsRef.current.forEach(p => p.url && URL.revokeObjectURL(p.url))
    const next = items.map((it) => {
      const f: File = it.file
      const isImage = ['image/jpeg', 'image/png', 'image/webp'].includes(f.type)
      return { key: it.id as string, name: f.name, url: isImage ? URL.createObjectURL(f) : null }
    })
    previewsRef.current = next
    setPreviews(next)
  }

  useEffect(() => () => { previewsRef.current.forEach(p => p.url && URL.revokeObjectURL(p.url)) }, [])

  // Approved is final — the verified summary is all that shows, no resubmit form.
  // A submission in flight (submitted / under review) is locked pending a decision.
  // The submit form appears only for a rejected submission or a property with no
  // proof on file yet.
  const isApproved = ownership?.status === 'approved'
  const isLocked = ownership?.status === 'submitted' || ownership?.status === 'pending'
  const showForm = !isApproved && !isLocked
  const needsEntity = ownerType === 'company' || ownerType === 'manager'

  function submit() {
    const items: any[] = pondRef.current?.getFiles() ?? []
    if (items.length === 0) { setFormError('Upload at least one proof document.'); return }
    const ids = items.map(f => f.serverId).filter(Boolean)
    if (ids.length !== items.length) { setFormError('Please wait for all documents to finish uploading.'); return }

    setSubmitting(true)
    setFormError(null)
    setFieldErrors({})
    router.post(`/member/properties/${propertyId}/ownership`, {
      owner_type: ownerType,
      entity_name: needsEntity ? entityName : null,
      tmp_files: ids,
      certification_name: certName,
      certification_accepted: certified,
    }, {
      preserveScroll: true,
      onSuccess: () => { pondRef.current?.removeFiles(); setCertName(''); setCertified(false) },
      onError: (errs) => setFieldErrors(errs as Record<string, string>),
      onFinish: () => setSubmitting(false),
    })
  }

  const description = 'Before a property can go Active and appear publicly, our staff verify that you own or manage it. Upload proof and certify it under penalty of perjury — your listing stays a private draft until the proof is approved.'

  return (
    <Section title="Proof of Ownership" icon={<DocumentCheckIcon />} description={description} action={ownership ? <StatusBadge status={ownership.status} /> : undefined}>

      {/* ── Current submission summary ─────────────────────────────────────────── */}
      {ownership && (
        <div style={{ border: `1px solid ${TAN}`, background: '#fbf8f1', padding: '16px', marginBottom: showForm ? '22px' : 0 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '14px', marginBottom: ownership.documents.length ? '14px' : 0 }}>
            <div>
              <div style={label}>Submitted As</div>
              <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>{ownership.owner_type_label}</div>
            </div>
            {ownership.entity_name && (
              <div>
                <div style={label}>Owner / Entity</div>
                <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>{ownership.entity_name}</div>
              </div>
            )}
            <div>
              <div style={label}>Certified By</div>
              <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
                {ownership.certification_name}{ownership.certified_at ? ` · ${ownership.certified_at}` : ''}
              </div>
            </div>
          </div>

          {ownership.documents.length > 0 && (
            <div>
              <div style={label}>Submitted Documents</div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                {ownership.documents.map(d => <DocChip key={d.id} propertyId={propertyId} doc={d} />)}
              </div>
            </div>
          )}

          {ownership.status === 'rejected' && ownership.review_notes && (
            <div style={{ marginTop: '14px', padding: '11px 13px', background: '#fbecea', border: '1px solid #e8c4ba' }}>
              <div style={{ ...label, color: '#a23a25' }}>Reason for Rejection</div>
              <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#7a2c1c', lineHeight: 1.5 }}>{ownership.review_notes}</div>
            </div>
          )}

          {ownership.status === 'approved' && (
            <div style={{ marginTop: '14px', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#2f6b43' }}>
              Ownership verified{ownership.reviewed_at ? ` on ${ownership.reviewed_at}` : ''}. This property can now go Active.
            </div>
          )}
        </div>
      )}

      {isLocked && (
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: '16px 0 0', lineHeight: 1.5 }}>
          {ownership?.status === 'submitted'
            ? <>Your proof of ownership has been <strong>submitted</strong> and is awaiting staff review. We'll notify you once a decision is made.</>
            : <>Your proof of ownership is <strong>under review</strong> by staff. We'll notify you once a decision is made.</>}
        </p>
      )}

      {showForm && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
          {ownership && (
            <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.14em', textTransform: 'uppercase', color: TAN, borderTop: '1px solid #e5ddd0', paddingTop: '20px' }}>
              {ownership.status === 'rejected' ? 'Resubmit Proof' : 'Submit New Proof'}
            </div>
          )}

          {/* Owner type */}
          <div>
            <label style={label}>I am the…</label>
            <select value={ownerType} onChange={e => setOwnerType(e.target.value)} style={input}>
              {Object.entries(ownerTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            {fieldErrors.owner_type && <div style={errStyle}>{fieldErrors.owner_type}</div>}
          </div>

          {/* Entity / owner name (company + manager) */}
          {needsEntity && (
            <div>
              <label style={label}>{ownerType === 'company' ? 'Company / Entity Name' : 'Name of Owner You Represent'}</label>
              <input
                type="text" value={entityName} onChange={e => setEntityName(e.target.value)} maxLength={200}
                placeholder={ownerType === 'company' ? 'North Forty Land Holdings, LLC' : 'Jane Q. Landowner'}
                style={input}
              />
              <div style={helper}>
                {ownerType === 'company'
                  ? 'The legal entity shown on the deed or tax record.'
                  : 'The owner whose property you manage — your authorization should name them.'}
              </div>
              {fieldErrors.entity_name && <div style={errStyle}>{fieldErrors.entity_name}</div>}
            </div>
          )}

          {/* Suggested documents */}
          <div style={{ background: '#fbf8f1', border: '1px solid #e5ddd0', padding: '13px 15px' }}>
            <div style={{ ...label, color: '#3d6b54' }}>Documents That Work</div>
            <ul style={{ margin: '4px 0 0', paddingLeft: '18px', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#3d6b54', lineHeight: 1.6 }}>
              {(suggestedProof[ownerType] ?? []).map((s, i) => <li key={i}>{s}</li>)}
            </ul>
          </div>

          {/* Upload */}
          <div>
            <label style={label}>Proof Documents <span style={{ color: ACCENT }}>*</span></label>
            <FilePondUploader
              ref={pondRef}
              allowMultiple
              allowImagePreview={false}
              maxFiles={10}
              maxFileSize="15MB"
              acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf']}
              name="document"
              labelIdle='Drag &amp; Drop deeds, tax records, plats or agreements or <span class="filepond--label-action">Browse</span>'
              onupdatefiles={onFilesChange}
              processUrl={`/member/properties/${propertyId}/ownership/temp`}
              revertUrl={`/member/properties/${propertyId}/ownership/temp`}
            />
            {previews.length > 0 && (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', marginTop: '14px' }}>
                {previews.map(p => (
                  <div key={p.key} style={{ width: '120px' }}>
                    <div style={{ width: '120px', height: '140px', border: `1px solid ${TAN}`, background: '#fff', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      {p.url
                        ? <img src={p.url} alt={p.name} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
                        : <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke={TAN} strokeWidth={1.4} strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /></svg>}
                    </div>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', color: '#6b5e50', marginTop: '5px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name}</div>
                  </div>
                ))}
              </div>
            )}
            <div style={helper}>JPG, PNG, WebP, HEIC, or PDF — max 15 MB each, up to 10 documents.</div>
            {fieldErrors.tmp_files && <div style={errStyle}>{fieldErrors.tmp_files}</div>}
          </div>

          {/* Penalty-of-perjury certification */}
          <div style={{ border: `1px solid ${INK}`, background: '#fff', padding: '16px' }}>
            <div style={{ ...label, color: INK, marginBottom: '8px' }}>Certification</div>
            <label style={{ display: 'flex', gap: '11px', alignItems: 'flex-start', cursor: 'pointer' }}>
              <input
                type="checkbox" checked={certified} onChange={e => setCertified(e.target.checked)}
                style={{ marginTop: '3px', width: '16px', height: '16px', accentColor: ACCENT, flexShrink: 0 }}
              />
              <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: INK, lineHeight: 1.5 }}>
                I certify, under penalty of perjury, that the documents I am submitting are current and accurate, and that
                the property listed is owned or managed by me. I understand that submitting false or fraudulent proof of
                ownership may result in removal from the platform and may carry legal consequences.
              </span>
            </label>
            {fieldErrors.certification_accepted && <div style={errStyle}>{fieldErrors.certification_accepted}</div>}

            <div style={{ marginTop: '14px' }}>
              <label style={label}>Type Your Full Legal Name to Sign</label>
              <input type="text" value={certName} onChange={e => setCertName(e.target.value)} maxLength={200} placeholder="Jane Q. Landowner" style={input} />
              {fieldErrors.certification_name && <div style={errStyle}>{fieldErrors.certification_name}</div>}
            </div>
          </div>

          {formError && <div style={errStyle}>{formError}</div>}

          <div>
            <button type="button" onClick={submit} disabled={submitting} style={{ ...primaryBtn, opacity: submitting ? 0.6 : 1 }}>
              {submitting ? 'Submitting…' : 'Submit for Review'}
            </button>
          </div>
        </div>
      )}
    </Section>
  )
}
