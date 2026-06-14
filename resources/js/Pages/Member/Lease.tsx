import { Head, useForm, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { createPortal } from 'react-dom'

interface Signer {
  name: string
  role: string
  status: string
  signed_at: string | null
}

interface AccessInfo {
  gate_code?: string
  cabin_code?: string
  wifi_ssid?: string
  wifi_password?: string
  directions?: string
}

interface LeaseDocument {
  id: string
  tag: string
  tag_label: string
  tag_badge_style: string
  original_filename: string | null
  size_bytes: number | null
  created_at: string | null
  download_url: string
  delete_url: string
}

interface StandMarker {
  id: string
  x_percent: number
  y_percent: number
  label: string | null
  type: string
  type_label: string
  color: string
  notes: string | null
  latitude: number | null
  longitude: number | null
}

interface StandMap {
  image_url: string
  markers: StandMarker[]
}

interface Props {
  lease: {
    id: string
    status: string
    start_date: string
    end_date: string
    total_price: string
    auto_renew: boolean
  }
  property: {
    id: string
    title: string
    county: string
    state: string
    acres: string | number
    rules: string[]
  } | null
  access_info: AccessInfo | null
  signers: Signer[]
  sign_url: string | null
  is_lessor: boolean
  documents: LeaseDocument[]
  document_tags: Record<string, string>
  upload_url: string
  check_in: {
    open: { checked_in_at: string } | null
    check_in_url: string
    check_out_url: string
  } | null
  qr: { png_url: string } | null
  stand_map: StandMap | null
  email_qr_url: string | null
}

const STATUS_LABEL: Record<string, string> = {
  active: 'Active',
  pending_signatures: 'Awaiting Signatures',
  expired: 'Expired',
  terminated: 'Terminated',
  cancelled: 'Cancelled',
}

const STATUS_COLOR: Record<string, string> = {
  active: '#4a7c59',
  pending_signatures: '#b8934a',
  expired: '#a89874',
  terminated: '#a89874',
  cancelled: '#a89874',
}

// ── Theme tokens — parchment field-record system (see docs/design_system.md) ──
const PAPER  = '#F8F4EB'
const INK    = '#0A1512'
const ACCENT = '#C84C21'
const TAN    = '#a89874'
const DIVIDER = '#e5ddd0'
const FIELD_BORDER = '#d4c9b0'
const OLIVE  = '#4a5440'
const BRASS  = '#b8934a'

const themeVars = {
  '--ah-accent': ACCENT,
  '--ah-paper': PAPER,
  '--ah-ink': INK,
} as React.CSSProperties

// Field-record card shell — 1px ink border + 8px solid ink drop shadow.
const fieldCard: React.CSSProperties = {
  position: 'relative',
  background: PAPER,
  border: `1px solid ${INK}`,
  boxShadow: `6px 6px 0 ${INK}`,
  marginBottom: '24px',
}

function DashedInset() {
  return <div style={{ position: 'absolute', inset: 6, border: `1px dashed ${TAN}`, pointerEvents: 'none', zIndex: 1 }} />
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div style={fieldCard}>
      <DashedInset />
      <div style={{ position: 'relative', zIndex: 2, padding: '18px 24px' }}>
        <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', borderBottom: `1px solid ${DIVIDER}`, paddingBottom: '6px' }}>
          {title}
        </div>
        {children}
      </div>
    </div>
  )
}

function AccessField({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ marginBottom: '14px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, marginBottom: '5px' }}>
        {label}
      </div>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '16px', fontWeight: 700, color: INK, letterSpacing: '.05em', background: '#fff', padding: '8px 12px', border: `1px solid ${FIELD_BORDER}`, display: 'inline-block', minWidth: '120px' }}>
        {value}
      </div>
    </div>
  )
}

const btnAccent: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '10px 18px',
  background: ACCENT, color: '#fff', border: `1px solid ${ACCENT}`,
  fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase',
  cursor: 'pointer', textDecoration: 'none',
}

const btnDark: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '10px 18px',
  background: INK, color: '#F4ECDC', border: `1px solid ${INK}`,
  fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase',
  cursor: 'pointer', textDecoration: 'none',
}

const btnGhost: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '8px 14px',
  background: 'transparent', color: OLIVE, border: `1px solid ${FIELD_BORDER}`,
  fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase',
  cursor: 'pointer', textDecoration: 'none',
}

function DocumentRow({ doc, isLessor, isLast }: { doc: LeaseDocument; isLessor: boolean; isLast: boolean }) {
  const [confirming, setConfirming] = useState(false)
  const [typed, setTyped] = useState('')
  const [deleting, setDeleting] = useState(false)

  function handleDelete() {
    if (typed !== 'DELETE') return
    setDeleting(true)
    router.delete(doc.delete_url, {
      onFinish: () => { setDeleting(false); setConfirming(false); setTyped('') },
    })
  }

  const badgeProps = Object.fromEntries(
    doc.tag_badge_style.split(';').filter(Boolean).map(s => {
      const [k, v] = s.split(':')
      return [k.trim().replace(/-([a-z])/g, (_: string, c: string) => c.toUpperCase()), v.trim()]
    })
  )

  return (
    <div style={{ padding: '12px 0', borderBottom: isLast ? 'none' : `1px dotted ${FIELD_BORDER}` }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', minWidth: 0 }}>
          <svg style={{ width: '20px', height: '20px', flexShrink: 0, color: ACCENT }} fill="currentColor" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/>
          </svg>
          <div style={{ minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
              <span style={{ fontFamily: 'var(--body)', fontSize: '15px', fontWeight: 600, color: INK }}>{doc.tag_label}</span>
              <span style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, padding: '1px 6px', ...badgeProps }}>
                {doc.tag.toUpperCase().replace(/_/g, ' ')}
              </span>
            </div>
            <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: TAN, marginTop: '3px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '240px' }}>
              {doc.original_filename ?? 'document.pdf'}
              {doc.size_bytes ? ` · ${Math.round(doc.size_bytes / 1024)} KB` : ''}
              {doc.created_at ? ` · ${doc.created_at}` : ''}
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexShrink: 0, marginLeft: '12px' }}>
          <a href={doc.download_url} style={{ ...btnGhost, padding: '6px 12px', fontSize: '10px' }}>
            <svg style={{ width: '12px', height: '12px' }} fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download
          </a>

          {isLessor && !confirming && (
            <button
              onClick={() => setConfirming(true)}
              style={{ ...btnGhost, padding: '6px 12px', fontSize: '10px', color: '#b91c1c', borderColor: '#d8a39a' }}
            >
              <svg style={{ width: '12px', height: '12px' }} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              Delete
            </button>
          )}
        </div>
      </div>

      {/* Inline delete confirmation */}
      {confirming && (
        <div style={{ marginTop: '12px', padding: '14px', background: '#fbf3f1', border: '1px solid #d8a39a' }}>
          <div style={{ fontFamily: 'var(--body)', fontSize: '14px', fontWeight: 600, color: '#b91c1c', marginBottom: '4px' }}>
            Confirm document removal
          </div>
          <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: '#9a3412', marginBottom: '10px' }}>
            This document will be soft-deleted and permanently removed after 30 days. Type <strong>DELETE</strong> to confirm.
          </div>
          <input
            type="text"
            value={typed}
            onChange={e => setTyped(e.target.value)}
            placeholder="Type DELETE"
            style={{ padding: '6px 10px', border: '1px solid #d8a39a', fontFamily: 'var(--mono)', fontSize: '13px', marginBottom: '10px', width: '160px' }}
          />
          <div style={{ display: 'flex', gap: '8px' }}>
            <button
              onClick={handleDelete}
              disabled={typed !== 'DELETE' || deleting}
              style={{ padding: '8px 16px', background: typed === 'DELETE' ? '#b91c1c' : '#e5ddd0', color: typed === 'DELETE' ? '#fff' : TAN, border: 'none', fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', cursor: typed === 'DELETE' && !deleting ? 'pointer' : 'not-allowed' }}
            >
              {deleting ? 'Removing…' : 'Confirm Delete'}
            </button>
            <button onClick={() => { setConfirming(false); setTyped('') }} style={{ ...btnGhost, padding: '8px 16px' }}>
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function UploadDocumentForm({ uploadUrl, tags }: { uploadUrl: string; tags: Record<string, string> }) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<{
    document: File | null
    tag: string
    notes: string
  }>({ document: null, tag: '', notes: '' })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(uploadUrl, {
      forceFormData: true,
      onSuccess: () => { reset(); setOpen(false) },
    })
  }

  const labelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }

  if (!open) {
    return (
      <button onClick={() => setOpen(true)} style={btnDark}>
        + Upload Document
      </button>
    )
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '12px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', fontWeight: 600 }}>
        Upload Document
      </div>

      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>Document Type *</label>
        <select value={data.tag} onChange={e => setData('tag', e.target.value)} required style={{ ...inputStyle, cursor: 'pointer' }}>
          <option value="">Select type…</option>
          {Object.entries(tags).map(([value, label]) => (
            <option key={value} value={value}>{label}</option>
          ))}
        </select>
        {errors.tag && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.tag}</div>}
      </div>

      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>File (PDF, max 20 MB) *</label>
        <input type="file" accept="application/pdf" required onChange={e => setData('document', e.target.files?.[0] ?? null)} style={{ fontFamily: 'var(--body)', fontSize: '14px' }} />
        {errors.document && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.document}</div>}
      </div>

      <div style={{ marginBottom: '16px' }}>
        <label style={labelStyle}>Notes (optional)</label>
        <textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={2} maxLength={500} placeholder="Optional note about this document…" style={{ ...inputStyle, resize: 'vertical' }} />
      </div>

      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Uploading…' : 'Upload'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>
          Cancel
        </button>
      </div>
    </form>
  )
}

function StandMapModal({ map, propertyTitle, onClose }: { map: StandMap; propertyTitle: string; onClose: () => void }) {
  const [active, setActive] = useState<string | null>(null)
  const count = map.markers.length

  // Full-screen overlay portaled to <body>: an opaque page that fully covers
  // the lease page (including its topbar) so there's a single banner, and it
  // escapes the lease page's nested stacking contexts.
  return createPortal(
    <div
      className="topo-bg"
      style={{ ...themeVars, position: 'fixed', inset: 0, zIndex: 1000, backgroundColor: '#EDE5D0', display: 'flex', flexDirection: 'column' }}
    >
      {/* Top banner — identical to the member-portal topbar on the lease page */}
      <div style={{ flexShrink: 0, background: INK, borderBottom: `1px solid ${BRASS}` }}>
        <div style={{ maxWidth: '1160px', width: '100%', margin: '0 auto', padding: '0 24px', height: '64px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
            <div style={{ position: 'relative', width: '42px', height: '42px', flexShrink: 0, margin: '5px' }}>
              <div style={{ position: 'absolute', top: -5, left: -5, width: 9, height: 9, borderTop: `1.5px solid ${TAN}`, borderLeft: `1.5px solid ${TAN}` }} />
              <div style={{ position: 'absolute', bottom: -5, right: -5, width: 9, height: 9, borderBottom: `1.5px solid ${TAN}`, borderRight: `1.5px solid ${TAN}` }} />
              <div style={{ width: '42px', height: '42px', border: `1px solid ${TAN}`, display: 'flex', alignItems: 'center', justifyContent: 'center', background: INK }}>
                <span style={{ fontFamily: 'var(--display)', fontSize: '15px', fontWeight: 500, color: '#F4ECDC', letterSpacing: '.05em' }}>AH</span>
              </div>
            </div>
            <div>
              <div style={{ fontFamily: 'var(--display)', fontSize: '17px', fontWeight: 400, color: '#F4ECDC', lineHeight: 1.1 }}>
                American Headhunter
              </div>
              <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.22em', textTransform: 'uppercase', color: '#6b9e8f', marginTop: '3px' }}>
                Member Portal
              </div>
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Close stand map"
            style={{ background: 'transparent', border: 'none', fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, cursor: 'pointer', padding: 0 }}
          >
            ← Back to Lease
          </button>
        </div>
      </div>

      {/* Scrollable page content */}
      <div style={{ flex: 1, overflow: 'auto' }}>
        <div style={{ maxWidth: '1160px', width: '100%', margin: '0 auto', padding: '32px 24px 56px' }}>
          <div style={{ marginBottom: '18px' }}>
            <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: ACCENT, marginBottom: '4px' }}>
              Stand Map
            </div>
            <h1 style={{ fontFamily: 'var(--display)', fontSize: '28px', fontWeight: 400, color: INK, margin: 0 }}>
              {propertyTitle}
            </h1>
          </div>

          <div style={fieldCard}>
            <DashedInset />
            <div style={{ position: 'relative', zIndex: 2, padding: '18px 24px' }}>
              <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '8px', borderBottom: `1px solid ${DIVIDER}`, paddingBottom: '6px' }}>
                Boundary Map · {count} marker{count !== 1 ? 's' : ''}
              </div>
              <p style={{ fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE, margin: '0 0 14px', lineHeight: 1.5 }}>
                Marked stands, amenities, and points of interest. Tap a marker for its details.
              </p>

              <div style={{ position: 'relative', border: `1px solid ${INK}`, lineHeight: 0 }}>
            <img src={map.image_url} alt={`Boundary map — ${propertyTitle}`} style={{ display: 'block', width: '100%', height: 'auto' }} />

            {map.markers.map(m => {
              const isActive = active === m.id
              return (
                <div
                  key={m.id}
                  style={{
                    position: 'absolute', left: `${m.x_percent}%`, top: `${m.y_percent}%`,
                    transform: 'translate(-50%, -50%)',
                    zIndex: isActive ? 30 : 10,
                  }}
                >
                  {/* Floating info popup — appears above the selected marker */}
                  {isActive && (
                    <div
                      style={{
                        position: 'absolute', bottom: '100%', left: '50%', transform: 'translateX(-50%)',
                        marginBottom: '10px', width: 'max-content', maxWidth: '230px',
                        background: PAPER, border: `1px solid ${INK}`, boxShadow: '4px 6px 14px rgba(10,21,18,0.45)',
                        padding: '12px 14px', textAlign: 'left', lineHeight: 'normal', zIndex: 40,
                      }}
                    >
                      <button
                        onClick={() => setActive(null)}
                        aria-label="Close marker details"
                        style={{ position: 'absolute', top: '6px', right: '8px', background: 'transparent', border: 'none', color: TAN, fontSize: '14px', lineHeight: 1, cursor: 'pointer', padding: 0 }}
                      >
                        ✕
                      </button>
                      <div style={{ fontFamily: 'var(--display)', fontSize: '16px', fontWeight: 500, color: INK, lineHeight: 1.2, paddingRight: '14px' }}>
                        {m.label || m.type_label}
                      </div>
                      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.12em', textTransform: 'uppercase', color: ACCENT, marginTop: '4px' }}>
                        {m.type_label}
                      </div>
                      {m.notes && (
                        <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE, lineHeight: 1.5, marginTop: '8px' }}>
                          {m.notes}
                        </div>
                      )}
                      {m.latitude !== null && m.longitude !== null && (
                        <a
                          href={`https://maps.google.com/?q=${m.latitude},${m.longitude}`}
                          target="_blank"
                          rel="noopener"
                          style={{ display: 'inline-block', fontFamily: 'var(--mono)', fontSize: '11px', color: OLIVE, textDecoration: 'none', marginTop: '8px' }}
                        >
                          📍 {m.latitude.toFixed(6)}, {m.longitude.toFixed(6)}
                        </a>
                      )}
                      {/* pointer triangle — paper fill with a thin ink edge */}
                      <span style={{ position: 'absolute', top: '100%', left: '50%', transform: 'translateX(-50%)', width: 0, height: 0, borderLeft: '7px solid transparent', borderRight: '7px solid transparent', borderTop: `7px solid ${INK}` }} />
                      <span style={{ position: 'absolute', top: 'calc(100% - 1.5px)', left: '50%', transform: 'translateX(-50%)', width: 0, height: 0, borderLeft: '6px solid transparent', borderRight: '6px solid transparent', borderTop: `6px solid ${PAPER}` }} />
                    </div>
                  )}

                  {/* Marker — dot + label, matching the admin boundary map. The
                      selection bounding box + red ring mark the active one. */}
                  <button
                    type="button"
                    onClick={() => setActive(isActive ? null : m.id)}
                    title={m.label ? `${m.label} · ${m.type_label}` : m.type_label}
                    style={{
                      display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '3px',
                      background: 'transparent', border: 'none', padding: 0, cursor: 'pointer',
                    }}
                  >
                    <span
                      style={{
                        display: 'block', width: '14px', height: '14px', borderRadius: '50%',
                        background: m.color, border: '2px solid #fff',
                        boxShadow: '0 1px 4px rgba(0,0,0,0.45)',
                      }}
                    />
                    {m.label && (
                      <span
                        style={{
                          background: 'rgba(10,21,18,0.8)', color: '#fff',
                          fontFamily: 'var(--mono)', fontSize: '9px', lineHeight: 1.5, letterSpacing: '.05em',
                          padding: '2px 6px', borderRadius: '3px', whiteSpace: 'nowrap',
                          maxWidth: '160px', overflow: 'hidden', textOverflow: 'ellipsis',
                        }}
                      >
                        {m.label}
                      </span>
                    )}
                  </button>
                </div>
              )
            })}
          </div>

          {/* Legend — also selects */}
          {count > 0 && (
            <div style={{ marginTop: '16px', paddingTop: '14px', borderTop: `1px solid ${DIVIDER}`, display: 'flex', flexWrap: 'wrap', gap: '8px 10px' }}>
              {map.markers.map(m => {
                const isActive = active === m.id
                return (
                  <button
                    key={`lg-${m.id}`}
                    type="button"
                    onClick={() => setActive(isActive ? null : m.id)}
                    style={{ display: 'flex', alignItems: 'center', gap: '7px', fontFamily: 'var(--body)', fontSize: '14px', color: INK, cursor: 'pointer', background: 'transparent', border: 'none', padding: '4px 8px' }}
                  >
                    <span style={{ width: '12px', height: '12px', borderRadius: '50%', background: m.color, border: `1px solid ${INK}`, flexShrink: 0 }} />
                    <span style={{ fontWeight: 600 }}>{m.label || m.type_label}</span>
                  </button>
                )
              })}
            </div>
          )}
            </div>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  )
}

function FieldAccess({
  leaseId, checkIn, qr, standMap, propertyTitle, emailQrUrl,
}: {
  leaseId: string
  checkIn: NonNullable<Props['check_in']>
  qr: Props['qr']
  standMap: StandMap | null
  propertyTitle: string
  emailQrUrl: string | null
}) {
  const [busy, setBusy] = useState(false)
  const [locating, setLocating] = useState(false)
  const [showQr, setShowQr] = useState(false)
  const [showStands, setShowStands] = useState(false)
  const isOpen = checkIn.open !== null

  function withPosition(cb: (coords: { lat: number; lng: number } | null) => void) {
    if (!navigator.geolocation) { cb(null); return }
    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      pos => { setLocating(false); cb({ lat: pos.coords.latitude, lng: pos.coords.longitude }) },
      () => { setLocating(false); cb(null) },
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    )
  }

  function checkInNow() {
    setBusy(true)
    withPosition(coords => {
      router.post(checkIn.check_in_url, { lease_id: leaseId, lat: coords?.lat ?? null, lng: coords?.lng ?? null },
        { onFinish: () => setBusy(false) })
    })
  }

  function checkOutNow() {
    setBusy(true)
    router.post(checkIn.check_out_url, { lease_id: leaseId }, { onFinish: () => setBusy(false) })
  }

  function emailQr() {
    if (!emailQrUrl) return
    setBusy(true)
    router.post(emailQrUrl, {}, { onFinish: () => setBusy(false) })
  }

  return (
    <Section title="Field Access">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '12px' }}>
        <div>
          <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, marginBottom: '4px' }}>
            Check-In Status
          </div>
          <div style={{ fontFamily: 'var(--display)', fontSize: '18px', fontWeight: 500, color: isOpen ? '#4a7c59' : INK }}>
            {isOpen ? 'Checked In' : 'Not Checked In'}
          </div>
        </div>
        {!isOpen ? (
          <button onClick={checkInNow} disabled={busy || locating} style={{ ...btnAccent, opacity: busy || locating ? 0.7 : 1, cursor: busy || locating ? 'not-allowed' : 'pointer' }}>
            {locating ? 'Locating…' : busy ? 'Checking In…' : 'Check In'}
          </button>
        ) : (
          <button onClick={checkOutNow} disabled={busy} style={{ ...btnDark, opacity: busy ? 0.7 : 1, cursor: busy ? 'not-allowed' : 'pointer' }}>
            {busy ? 'Checking Out…' : 'Check Out'}
          </button>
        )}
      </div>

      <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', marginTop: '16px' }}>
        {standMap && (
          <button onClick={() => setShowStands(true)} style={btnGhost}>
            View Stand Map
          </button>
        )}
        {qr && (
          <button onClick={() => setShowQr(v => !v)} style={btnGhost}>
            {showQr ? 'Hide Gate QR' : 'Show Gate QR'}
          </button>
        )}
        {emailQrUrl && (
          <button onClick={emailQr} disabled={busy} style={{ ...btnGhost, color: INK, borderColor: BRASS, opacity: busy ? 0.7 : 1, cursor: busy ? 'not-allowed' : 'pointer' }}>
            Email QR to Hunter
          </button>
        )}
      </div>

      {qr && showQr && (
        <div style={{ marginTop: '16px', textAlign: 'center', background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '20px' }}>
          <img src={qr.png_url} alt="Property check-in QR code" width={200} height={200} style={{ display: 'inline-block' }} />
          <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE, marginTop: '10px', lineHeight: 1.5 }}>
            Scan this at the gate to check in. Post it at the entrance or save it to your phone.
          </div>
        </div>
      )}

      {standMap && showStands && (
        <StandMapModal map={standMap} propertyTitle={propertyTitle} onClose={() => setShowStands(false)} />
      )}
    </Section>
  )
}

export default function Lease({ lease, property, access_info, signers, sign_url, is_lessor, documents, document_tags, upload_url, check_in, qr, stand_map, email_qr_url }: Props) {
  const { flash } = usePage<{ flash: { success: string | null; error: string | null } }>().props
  const statusColor = STATUS_COLOR[lease.status] ?? TAN
  const statusLabel = STATUS_LABEL[lease.status] ?? lease.status
  const allSigned   = signers.every(s => s.status === 'signed')

  return (
    <>
      <Head title={property?.title ?? 'Lease Detail'} />

      <div className="topo-bg" style={{ ...themeVars, minHeight: '100vh', backgroundColor: '#EDE5D0' }}>

        {/* Topbar */}
        <div style={{ background: INK, borderBottom: `1px solid ${BRASS}` }}>
          <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '0 24px', height: '64px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
              <div style={{ position: 'relative', width: '42px', height: '42px', flexShrink: 0, margin: '5px' }}>
                <div style={{ position: 'absolute', top: -5, left: -5, width: 9, height: 9, borderTop: `1.5px solid ${TAN}`, borderLeft: `1.5px solid ${TAN}` }} />
                <div style={{ position: 'absolute', bottom: -5, right: -5, width: 9, height: 9, borderBottom: `1.5px solid ${TAN}`, borderRight: `1.5px solid ${TAN}` }} />
                <div style={{ width: '42px', height: '42px', border: `1px solid ${TAN}`, display: 'flex', alignItems: 'center', justifyContent: 'center', background: INK }}>
                  <span style={{ fontFamily: 'var(--display)', fontSize: '15px', fontWeight: 500, color: '#F4ECDC', letterSpacing: '.05em' }}>AH</span>
                </div>
              </div>
              <div>
                <div style={{ fontFamily: 'var(--display)', fontSize: '17px', fontWeight: 400, color: '#F4ECDC', lineHeight: 1.1 }}>
                  American Headhunter
                </div>
                <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.22em', textTransform: 'uppercase', color: '#6b9e8f', marginTop: '3px' }}>
                  Member Portal
                </div>
              </div>
            </div>
            <a href="/member" style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, textDecoration: 'none' }}>
              ← My Leases
            </a>
          </div>
        </div>

        <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '40px 24px 80px' }}>

          {/* Header — dark field-record plate */}
          <div style={{ position: 'relative', background: INK, boxShadow: `6px 6px 0 ${BRASS}`, marginBottom: '24px' }}>
            <div style={{ position: 'absolute', inset: 6, border: `1px dashed ${TAN}`, pointerEvents: 'none' }} />
            <div style={{ position: 'relative', padding: '28px 28px' }}>
              <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: ACCENT, marginBottom: '8px' }}>
                Hunting Lease
              </div>
              <h1 style={{ fontFamily: 'var(--display)', fontSize: '28px', fontWeight: 400, color: '#F4ECDC', margin: '0 0 6px' }}>
                {property?.title ?? 'Hunting Property'}
              </h1>
              {property && (
                <div style={{ fontFamily: 'var(--body)', fontSize: '15px', color: '#a89874', marginBottom: '14px' }}>
                  {property.county} County, {property.state}
                  {property.acres ? ` · ${Number(property.acres).toLocaleString()} acres` : ''}
                </div>
              )}
              <span style={{
                display: 'inline-block', padding: '4px 12px', border: `1px solid ${statusColor}`,
                fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em',
                textTransform: 'uppercase', color: statusColor,
              }}>
                {statusLabel}
              </span>
            </div>
          </div>

          {/* Flash */}
          {flash?.success && (
            <div style={{ background: '#eef5ee', border: '1px solid #a9c8af', padding: '12px 16px', marginBottom: '24px', fontFamily: 'var(--body)', fontSize: '15px', color: '#3a6b48' }}>
              {flash.success}
            </div>
          )}
          {flash?.error && (
            <div style={{ background: '#fbf3f1', border: '1px solid #d8a39a', padding: '12px 16px', marginBottom: '24px', fontFamily: 'var(--body)', fontSize: '15px', color: '#b91c1c' }}>
              {flash.error}
            </div>
          )}

          {/* Field Access — check-in, stand map, gate QR (active leases only) */}
          {check_in && (
            <FieldAccess
              leaseId={lease.id}
              checkIn={check_in}
              qr={qr}
              standMap={stand_map}
              propertyTitle={property?.title ?? 'Property'}
              emailQrUrl={email_qr_url}
            />
          )}

          {/* Sign CTA — only when pending and user hasn't signed */}
          {sign_url && (
            <div style={{ position: 'relative', background: '#fbf1e9', border: `1px solid ${ACCENT}`, padding: '18px 24px', marginBottom: '24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '12px' }}>
              <div>
                <div style={{ fontFamily: 'var(--display)', fontSize: '17px', fontWeight: 500, color: '#9a3412', marginBottom: '2px' }}>Your signature is required</div>
                <div style={{ fontFamily: 'var(--body)', fontSize: '15px', color: '#9a3412' }}>Sign the lease agreement to activate your access.</div>
              </div>
              <a href={sign_url} style={{ ...btnAccent, whiteSpace: 'nowrap' }}>
                Sign Now
              </a>
            </div>
          )}

          {/* Lease Terms */}
          <Section title="Lease Terms">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px' }}>
              {([
                ['Start Date', lease.start_date],
                ['End Date', lease.end_date],
                ['Total Price', `$${lease.total_price}`],
                ['Auto-Renew', lease.auto_renew ? 'Enabled' : 'Disabled'],
              ] as [string, string][]).map(([label, value]) => (
                <div key={label}>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: TAN, marginBottom: '5px' }}>{label}</div>
                  <div style={{ fontFamily: 'var(--body)', fontSize: '16px', fontWeight: 600, color: INK }}>{value}</div>
                </div>
              ))}
            </div>
          </Section>

          {/* Signing Status — shown when pending or not all signed */}
          {signers.length > 0 && (lease.status === 'pending_signatures' || !allSigned) && (
            <Section title="Signatures">
              {signers.map((signer, i) => {
                const isSigned = signer.status === 'signed'
                return (
                  <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 0', borderBottom: i < signers.length - 1 ? `1px dotted ${FIELD_BORDER}` : 'none' }}>
                    <div style={{ width: '28px', height: '28px', borderRadius: '50%', background: isSigned ? '#eef5ee' : '#fbf1e9', border: `2px solid ${isSigned ? '#4a7c59' : BRASS}`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                      <span style={{ fontSize: '14px', color: isSigned ? '#4a7c59' : BRASS, fontWeight: 700 }}>
                        {isSigned ? '✓' : '○'}
                      </span>
                    </div>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontFamily: 'var(--body)', fontSize: '15px', fontWeight: 600, color: INK }}>{signer.name}</div>
                      <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', color: TAN, letterSpacing: '.06em' }}>{signer.role}</div>
                    </div>
                    <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.08em', color: isSigned ? '#4a7c59' : BRASS }}>
                      {isSigned ? 'Signed' : 'Pending'}
                    </div>
                  </div>
                )
              })}
            </Section>
          )}

          {/* Access Info — active leases only */}
          {access_info && Object.keys(access_info).length > 0 && (
            <div style={fieldCard}>
              <DashedInset />
              <div style={{ position: 'relative', zIndex: 2 }}>
                <div style={{ padding: '14px 24px', borderBottom: `1px solid ${DIVIDER}`, display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <span style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: ACCENT, fontWeight: 700 }}>
                    Property Access
                  </span>
                  <span style={{ fontFamily: 'var(--mono)', fontSize: '9px', color: '#6b9e8f', letterSpacing: '.06em' }}>· Keep confidential</span>
                </div>
                <div style={{ padding: '20px 24px' }}>
                  {access_info.gate_code && <AccessField label="Gate Code" value={access_info.gate_code} />}
                  {access_info.cabin_code && <AccessField label="Cabin Code" value={access_info.cabin_code} />}
                  {access_info.wifi_ssid && <AccessField label="WiFi Network" value={access_info.wifi_ssid} />}
                  {access_info.wifi_password && <AccessField label="WiFi Password" value={access_info.wifi_password} />}
                  {access_info.directions && (
                    <div>
                      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, marginBottom: '6px' }}>Directions</div>
                      <div style={{ fontFamily: 'var(--body)', fontSize: '15px', color: INK, lineHeight: 1.6, background: '#fff', padding: '12px', border: `1px solid ${FIELD_BORDER}` }}>
                        {access_info.directions}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* Lease Documents */}
          {(documents.length > 0 || is_lessor) && (
            <Section title="Lease Documents">
              {documents.length === 0 && (
                <p style={{ fontFamily: 'var(--body)', fontSize: '15px', color: TAN, fontStyle: 'italic', margin: '0 0 12px' }}>
                  No documents attached yet.
                </p>
              )}

              {documents.map((doc, i) => (
                <DocumentRow
                  key={doc.id}
                  doc={doc}
                  isLessor={is_lessor}
                  isLast={i === documents.length - 1}
                />
              ))}

              {is_lessor && (
                <div style={{ marginTop: documents.length > 0 ? '16px' : '0' }}>
                  <UploadDocumentForm uploadUrl={upload_url} tags={document_tags} />
                </div>
              )}
            </Section>
          )}

          {/* Property Rules */}
          {(property?.rules?.length ?? 0) > 0 && (
            <Section title="Property Rules">
              <ul style={{ margin: 0, padding: 0, listStyle: 'none' }}>
                {property!.rules.map((rule, i) => (
                  <li key={i} style={{ display: 'flex', gap: '10px', padding: '9px 0', borderBottom: i < property!.rules.length - 1 ? `1px dotted ${FIELD_BORDER}` : 'none', fontFamily: 'var(--body)', fontSize: '15px', color: INK, lineHeight: 1.5 }}>
                    <span style={{ color: ACCENT, fontWeight: 700, flexShrink: 0 }}>·</span>
                    {rule}
                  </li>
                ))}
              </ul>
            </Section>
          )}

        </div>
      </div>
    </>
  )
}
