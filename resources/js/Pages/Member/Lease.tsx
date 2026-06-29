import { Head, useForm, router, usePage } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { formatPhone, telHref } from '@/lib/phone'
import FilePondUploader from '@/Components/FilePondUploader'
import LandownerFinance, { type LandownerFinanceData } from '@/Components/Member/LandownerFinance'

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

interface ContactParty {
  name: string | null
  phone: string | null
  email: string | null
  role?: string
  role_label?: string
}

interface LocalContact {
  type: string
  type_label: string
  name: string | null
  organization: string | null
  phone: string | null
  email: string | null
  address: string | null
  notes: string | null
}

interface ContactDirectory {
  landowner: ContactParty | null
  managers: ContactParty[]
  contacts: LocalContact[]
}

interface ApplicationMessage {
  role: string
  sender_name: string
  is_me: boolean
  message: string
  sent_at: string | null
}

interface Communications {
  messages: ApplicationMessage[]
  message_url: string
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
  deposit: {
    status: string | null
    amount: string
    refunded: string | null
    forfeited: string | null
    can_pay: boolean
    pay_url: string
    forfeit: {
      trust_status: string
      fault: string | null
      amount: string
      reason: string | null
      category: string | null
      contest_deadline: string | null
      has_insurance: boolean
      can_contest: boolean
      can_opt_out: boolean
    } | null
    dispute: { status: string; filed_at: string | null } | null
    contest_url: string
    opt_out_url: string
  } | null
  landowner_deposit: {
    status: string
    amount: string
    remaining: string
    refunded: string
    forfeited: string
    can_release: boolean
    can_forfeit: boolean
    // Landowner-borne, non-recoverable Stripe processing cost on a refund
    // (fee_schedules 'security_deposit', payer=landowner). Null when no rule applies.
    release_fee: { amount: string; pct: number; flat: string } | null
    claim: {
      amount: string
      reason: string | null
      trust_status: string | null
      contest_deadline: string | null
      dispute_status: string | null
    } | null
    lease_terminated: boolean
    release_url: string
    forfeit_url: string
  } | null
  damage_claims: {
    claims: {
      claim_type: string
      status: string
      amount: string
      approved: string | null
      description: string
      filed_at: string | null
    }[]
    file_url: string
  } | null
  incidents: {
    reports: {
      id: string
      incident_number: string | null
      incident_type: string
      severity: string
      items: { type: string | null; severity: string | null; occurred_at: string | null; occurred_at_input: string | null }[]
      parties: { full_name: string; is_minor: boolean }[]
      status: string
      occurred_at: string | null
      occurred_at_input: string | null
      location_description: string | null
      description: string
      injuries_reported: boolean
      authorities_notified: boolean
      authority_report_number: string | null
      reported_at: string | null
      photos: { id: string; url: string }[]
      can_edit: boolean
      edit_url: string | null
    }[]
    report_url: string
  } | null
  booking_deposit: {
    status: string | null
    amount: string
    paid: boolean
    remaining_balance: string
  } | null
  lease_payment: {
    balance: string
    balance_due: boolean
    landowner_charges_enabled: boolean
    surcharge: string | null
    total_charge: string | null
    can_pay: boolean
    pay_url: string
    payments: { amount: string; status: string; paid_at: string | null }[]
  } | null
  landowner_finance: LandownerFinanceData | null
  contacts: ContactDirectory | null
  signers: Signer[]
  sign_url: string | null
  signed_lease_url: string | null
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
  communications: Communications | null
}

const STATUS_LABEL: Record<string, string> = {
  active: 'Active',
  pending_signatures: 'Awaiting Signatures',
  pending_payment: 'Payment Pending',
  expired: 'Expired',
  terminated: 'Terminated',
  cancelled: 'Cancelled',
}

const STATUS_COLOR: Record<string, string> = {
  active: '#4a7c59',
  pending_signatures: '#b8934a',
  pending_payment: '#C84C21',
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
        <FilePondUploader
          name="document"
          maxFileSize="20MB"
          acceptedFileTypes={['application/pdf']}
          labelIdle='Drag &amp; Drop your PDF or <span class="filepond--label-action">Browse</span>'
          onupdatefiles={items => setData('document', items[0]?.file ?? null)}
        />
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

// ── Forfeiture contest + insurance opt-out (lessee) ──────────────────────────
type DepositProp = NonNullable<Props['deposit']>

function ContestForfeitureForm({ url }: { url: string }) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<{ description: string; evidence: File[] }>({ description: '', evidence: [] })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(url, { forceFormData: true, onSuccess: () => { reset(); setOpen(false) } })
  }

  const labelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }

  if (!open) {
    return <button onClick={() => setOpen(true)} style={btnDark}>Contest this forfeiture</button>
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '12px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', fontWeight: 600 }}>
        Contest the Forfeiture
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>Why is this forfeiture incorrect? *</label>
        <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} maxLength={2000} required placeholder="Explain what happened — our team will review your evidence." style={{ ...inputStyle, resize: 'vertical' }} />
        {errors.description && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.description}</div>}
      </div>
      <div style={{ marginBottom: '16px' }}>
        <label style={labelStyle}>Photo evidence (optional, up to 10)</label>
        <FilePondUploader
          name="evidence"
          allowMultiple
          maxFiles={10}
          maxFileSize="10MB"
          acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
          labelIdle='Drag &amp; Drop photos or <span class="filepond--label-action">Browse</span>'
          onupdatefiles={items => setData('evidence', items.map(i => i.file as File))}
        />
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Filing…' : 'File Contest'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>Cancel</button>
      </div>
    </form>
  )
}

function OptOutForm({ url, hasInsurance }: { url: string; hasInsurance: boolean }) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<{ disposition: string; insurer_name: string; policy_number: string }>({ disposition: 'refund', insurer_name: '', policy_number: '' })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(url, { onSuccess: () => { reset(); setOpen(false) } })
  }

  const labelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }

  if (!open) {
    return <button onClick={() => setOpen(true)} style={btnGhost}>Settle via insurance</button>
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '12px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', fontWeight: 600 }}>
        Settle via Insurance — No Fault
      </div>
      <p style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: 0, marginBottom: '14px' }}>
        Opting out closes this without the dispute process and records no Trust Score change for either party. Choose how the held deposit is settled.
      </p>
      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>Settlement *</label>
        <select value={data.disposition} onChange={e => setData('disposition', e.target.value)} required style={{ ...inputStyle, cursor: 'pointer' }}>
          <option value="refund">Refund the deposit to me</option>
          <option value="keep">Let the forfeiture stand</option>
        </select>
      </div>
      {!hasInsurance && (
        <>
          <div style={{ marginBottom: '12px' }}>
            <label style={labelStyle}>Insurer name *</label>
            <input value={data.insurer_name} onChange={e => setData('insurer_name', e.target.value)} maxLength={120} style={inputStyle} />
            {errors.insurer_name && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.insurer_name}</div>}
          </div>
          <div style={{ marginBottom: '12px' }}>
            <label style={labelStyle}>Policy number</label>
            <input value={data.policy_number} onChange={e => setData('policy_number', e.target.value)} maxLength={80} style={inputStyle} />
          </div>
        </>
      )}
      {errors.opt_out && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginBottom: '10px' }}>{errors.opt_out}</div>}
      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Settling…' : 'Settle via Insurance'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>Cancel</button>
      </div>
    </form>
  )
}

function ForfeitureNotice({ deposit }: { deposit: DepositProp }) {
  const f = deposit.forfeit
  if (!f) return null

  const STATUS_COPY: Record<string, string> = {
    applied: 'The forfeiture was upheld and a Trust Score penalty was applied.',
    waived: 'The forfeiture was overturned — your deposit was returned.',
    opted_out: 'Settled via insurance — no fault was recorded.',
    reversed: 'The forfeiture penalty was reversed and your Trust Score restored.',
  }

  return (
    <div style={{ marginTop: '16px', borderTop: `1px solid ${DIVIDER}`, paddingTop: '16px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#9a3412', marginBottom: '6px', fontWeight: 600 }}>
        Forfeiture Claimed — ${f.amount}
      </div>
      {f.reason && <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: INK, marginBottom: '6px' }}>{f.reason}</div>}

      {f.trust_status === 'pending' ? (
        <>
          {deposit.dispute ? (
            <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: BRASS }}>
              Under review — you filed a contest on {deposit.dispute.filed_at}. We'll notify you of the outcome.
            </div>
          ) : (
            <>
              <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginBottom: '12px' }}>
                The money is held and no Trust Score penalty applies yet.
                {f.contest_deadline ? ` You have until ${f.contest_deadline} to contest.` : ''}
              </div>
              <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                {f.can_contest && <ContestForfeitureForm url={deposit.contest_url} />}
                {f.can_opt_out && <OptOutForm url={deposit.opt_out_url} hasInsurance={f.has_insurance} />}
              </div>
            </>
          )}
        </>
      ) : (
        <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE }}>
          {STATUS_COPY[f.trust_status] ?? 'Resolved.'}
        </div>
      )}
    </div>
  )
}

// ── Damage claims (lessor) ───────────────────────────────────────────────────
function FileDamageClaimForm({ url }: { url: string }) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<{ claim_type: string; amount: string; description: string; evidence: File[]; insurer_name: string; policy_number: string }>(
    { claim_type: 'property_damage', amount: '', description: '', evidence: [], insurer_name: '', policy_number: '' },
  )

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(url, { forceFormData: true, onSuccess: () => { reset(); setOpen(false) } })
  }

  const labelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }

  if (!open) {
    return <button onClick={() => setOpen(true)} style={btnDark}>+ File Damage Claim</button>
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '12px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', fontWeight: 600 }}>
        File a Damage Claim
      </div>
      <div style={{ display: 'flex', gap: '12px', marginBottom: '12px', flexWrap: 'wrap' }}>
        <div style={{ flex: '1 1 200px' }}>
          <label style={labelStyle}>Type *</label>
          <select value={data.claim_type} onChange={e => setData('claim_type', e.target.value)} required style={{ ...inputStyle, cursor: 'pointer' }}>
            <option value="property_damage">Property damage</option>
            <option value="equipment_damage">Equipment damage</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div style={{ flex: '1 1 140px' }}>
          <label style={labelStyle}>Amount (USD) *</label>
          <input type="number" step="0.01" min="0.01" value={data.amount} onChange={e => setData('amount', e.target.value)} required style={inputStyle} />
          {errors.amount && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.amount}</div>}
        </div>
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>Description *</label>
        <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} maxLength={2000} required style={{ ...inputStyle, resize: 'vertical' }} />
        {errors.description && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.description}</div>}
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>Photo evidence (optional, up to 10)</label>
        <FilePondUploader
          name="evidence"
          allowMultiple
          maxFiles={10}
          maxFileSize="10MB"
          acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
          labelIdle='Drag &amp; Drop photos or <span class="filepond--label-action">Browse</span>'
          onupdatefiles={items => setData('evidence', items.map(i => i.file as File))}
        />
      </div>
      <div style={{ display: 'flex', gap: '12px', marginBottom: '16px', flexWrap: 'wrap' }}>
        <div style={{ flex: '1 1 200px' }}>
          <label style={labelStyle}>Insurer name (optional)</label>
          <input value={data.insurer_name} onChange={e => setData('insurer_name', e.target.value)} maxLength={120} style={inputStyle} />
        </div>
        <div style={{ flex: '1 1 140px' }}>
          <label style={labelStyle}>Policy number</label>
          <input value={data.policy_number} onChange={e => setData('policy_number', e.target.value)} maxLength={80} style={inputStyle} />
        </div>
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Filing…' : 'File Claim'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>Cancel</button>
      </div>
    </form>
  )
}

const CLAIM_STATUS_LABEL: Record<string, string> = {
  submitted: 'Submitted', under_review: 'Under review', approved: 'Approved', denied: 'Denied', paid: 'Paid', covered: 'Covered by insurance',
}

function DamageClaimsSection({ data }: { data: NonNullable<Props['damage_claims']> }) {
  return (
    <Section title="Damage Claims">
      {data.claims.length > 0 ? (
        <div style={{ marginBottom: '14px' }}>
          {data.claims.map((c, i) => (
            <div key={i} style={{ borderBottom: i < data.claims.length - 1 ? `1px solid ${DIVIDER}` : 'none', paddingBottom: '12px', marginBottom: '12px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: '12px', flexWrap: 'wrap' }}>
                <div style={{ fontFamily: 'var(--body)', fontSize: '16px', fontWeight: 700, color: INK }}>
                  ${c.amount}{c.approved ? ` · $${c.approved} approved` : ''}
                </div>
                <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.08em', textTransform: 'uppercase', color: TAN }}>
                  {CLAIM_STATUS_LABEL[c.status] ?? c.status}{c.filed_at ? ` · ${c.filed_at}` : ''}
                </div>
              </div>
              <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>{c.description}</div>
            </div>
          ))}
        </div>
      ) : (
        <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: TAN, marginBottom: '14px' }}>
          No damage claims filed for this lease.
        </div>
      )}
      <FileDamageClaimForm url={data.file_url} />
    </Section>
  )
}

// ── Security deposit management (lessor) ─────────────────────────────────────
function LandownerDepositSection({ data }: { data: NonNullable<Props['landowner_deposit']> }) {
  const [releasing, setReleasing] = useState(false)
  const showNudge = data.lease_terminated && data.status === 'held' && !data.claim

  return (
    <Section title="Security Deposit">
      {showNudge && (
        <div style={{ background: '#fbf1e9', border: `1px solid ${BRASS}`, padding: '12px 14px', marginBottom: '16px' }}>
          <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: '#7a5a1e' }}>
            This lease has ended, but ${data.remaining} is still held. Release it to the hunter, or file a forfeiture claim if there's damage owed.
          </div>
        </div>
      )}

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '14px' }}>
        <div>
          <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: TAN, marginBottom: '5px' }}>
            {data.status === 'held' ? 'Held' : 'Deposit'}
          </div>
          <div style={{ fontFamily: 'var(--body)', fontSize: '22px', fontWeight: 700, color: INK }}>${data.amount}</div>
          {data.status === 'held' && !data.claim && (
            <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>
              ${data.remaining} held as refundable collateral.
            </div>
          )}
          {data.can_release && data.release_fee && (
            <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: BRASS, marginTop: '6px' }}>
              On release, Stripe's processing fee (~${data.release_fee.amount} · {data.release_fee.pct}% + ${data.release_fee.flat}) is non-refundable and is the landowner's cost.
            </div>
          )}
          {data.status === 'released' && (
            <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>
              Released — ${data.refunded} refunded to the hunter.
            </div>
          )}
          {data.status === 'forfeited' && (
            <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: '#9a3412', marginTop: '4px' }}>
              ${data.forfeited} forfeited{Number(data.refunded) > 0 ? ` · $${data.refunded} refunded` : ''}.
            </div>
          )}
        </div>

        {data.can_release && (
          <button
            type="button"
            disabled={releasing}
            onClick={() => {
              if (!confirm(
                `Release $${data.remaining} back to the hunter? This refunds the deposit in full and can't be undone.` +
                (data.release_fee ? `\n\nStripe keeps ~$${data.release_fee.amount} (${data.release_fee.pct}% + $${data.release_fee.flat}) in non-refundable processing — the landowner's cost.` : '')
              )) return
              setReleasing(true)
              router.post(data.release_url, {}, { preserveScroll: true, onFinish: () => setReleasing(false) })
            }}
            style={{ ...btnAccent, whiteSpace: 'nowrap', opacity: releasing ? 0.6 : 1 }}
          >
            {releasing ? 'Releasing…' : 'Release to Hunter'}
          </button>
        )}
      </div>

      {data.claim && (
        <div style={{ marginTop: '16px', borderTop: `1px solid ${DIVIDER}`, paddingTop: '16px' }}>
          <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: '#9a3412', marginBottom: '6px', fontWeight: 600 }}>
            Forfeiture Claim Filed — ${data.claim.amount}
          </div>
          {data.claim.reason && <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: INK, marginBottom: '6px' }}>{data.claim.reason}</div>}
          <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: data.claim.dispute_status ? BRASS : OLIVE }}>
            {data.claim.dispute_status
              ? 'The hunter contested this claim — our team is reviewing the evidence.'
              : data.claim.trust_status === 'pending'
                ? `Awaiting the hunter's response${data.claim.contest_deadline ? ` until ${data.claim.contest_deadline}` : ''}. The money stays held until it resolves.`
                : 'This claim has been resolved.'}
          </div>
        </div>
      )}

      {data.can_forfeit && <FileForfeitureClaimForm url={data.forfeit_url} maxAmount={data.remaining} />}
    </Section>
  )
}

function FileForfeitureClaimForm({ url, maxAmount }: { url: string; maxAmount: string }) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<{ amount: string; reason: string; category: string }>(
    { amount: maxAmount, reason: '', category: '' },
  )

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(url, { preserveScroll: true, onSuccess: () => { reset(); setOpen(false) } })
  }

  const labelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }

  if (!open) {
    return <div style={{ marginTop: '16px' }}><button onClick={() => setOpen(true)} style={btnDark}>+ File Forfeiture Claim</button></div>
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '16px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '6px', fontWeight: 600 }}>
        File a Forfeiture Claim
      </div>
      <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginBottom: '14px' }}>
        This files a claim against the hunter's deposit — the money stays held and the hunter can contest it with evidence. Nothing is paid out until it's resolved.
      </div>
      <div style={{ display: 'flex', gap: '12px', marginBottom: '12px', flexWrap: 'wrap' }}>
        <div style={{ flex: '1 1 140px' }}>
          <label style={labelStyle}>Amount (USD) *</label>
          <input type="number" step="0.01" min="0.01" max={maxAmount} value={data.amount} onChange={e => setData('amount', e.target.value)} required style={inputStyle} />
          {errors.amount && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.amount}</div>}
          <div style={{ fontFamily: 'var(--body)', fontSize: '12px', color: TAN, marginTop: '4px' }}>Up to ${maxAmount} held.</div>
        </div>
        <div style={{ flex: '1 1 160px' }}>
          <label style={labelStyle}>Category (optional)</label>
          <input value={data.category} onChange={e => setData('category', e.target.value)} maxLength={60} placeholder="e.g. cleaning, damage" style={inputStyle} />
        </div>
      </div>
      <div style={{ marginBottom: '16px' }}>
        <label style={labelStyle}>Reason *</label>
        <textarea value={data.reason} onChange={e => setData('reason', e.target.value)} rows={3} maxLength={2000} required style={{ ...inputStyle, resize: 'vertical' }} />
        {errors.reason && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.reason}</div>}
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Filing…' : 'File Claim'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>Cancel</button>
      </div>
    </form>
  )
}

const INCIDENT_TYPE_LABEL: Record<string, string> = {
  hunting_accident: 'Hunting accident', trespassing: 'Trespassing', property_damage: 'Property damage',
  wildlife_encounter: 'Wildlife encounter', medical: 'Medical', fire: 'Fire', other: 'Other',
}
const INCIDENT_STATUS_LABEL: Record<string, string> = {
  open: 'Open', investigating: 'Investigating', resolved: 'Resolved', closed: 'Closed',
}
const INCIDENT_TYPES: { value: string; label: string }[] = [
  { value: 'hunting_accident', label: 'Hunting accident' },
  { value: 'trespassing', label: 'Trespassing' },
  { value: 'property_damage', label: 'Property damage' },
  { value: 'wildlife_encounter', label: 'Wildlife encounter' },
  { value: 'medical', label: 'Medical' },
  { value: 'fire', label: 'Fire' },
  { value: 'other', label: 'Other' },
]
const INCIDENT_SEVERITIES = ['minor', 'moderate', 'serious', 'critical']

type IncidentItem = { type: string; severity: string; occurred_at: string }

/** Combine a report's line-item types into one title, e.g. "Fire · Medical". */
function incidentTitle(items: { type?: string | null }[] | undefined, fallback: string): string {
  const labels = (items ?? []).map(it => INCIDENT_TYPE_LABEL[it.type ?? ''] ?? it.type).filter(Boolean) as string[]
  const unique = [...new Set(labels)]
  return unique.length > 0 ? unique.join(' · ') : (INCIDENT_TYPE_LABEL[fallback] ?? fallback)
}

/**
 * Dynamic line-item editor: one real event can be several things at once (a fire AND
 * a medical injury), so each row carries its own type, severity, and when it occurred.
 * At least one row is always present.
 */
function IncidentItemsEditor({ items, onChange, errors }: { items: IncidentItem[]; onChange: (items: IncidentItem[]) => void; errors?: Record<string, string> }) {
  const update = (idx: number, patch: Partial<IncidentItem>) => onChange(items.map((it, i) => (i === idx ? { ...it, ...patch } : it)))
  const add = () => onChange([...items, { type: 'medical', severity: 'minor', occurred_at: '' }])
  const remove = (idx: number) => onChange(items.filter((_, i) => i !== idx))

  return (
    <div style={{ marginBottom: '12px' }}>
      <label style={incidentLabelStyle}>What kind of incident? *</label>
      <div style={{ fontFamily: 'var(--body)', fontSize: '12px', color: TAN, marginBottom: '8px' }}>
        One event can be several things at once — add a row for each (e.g. a fire and a medical injury).
      </div>
      {items.map((it, idx) => {
        const whenError = errors?.[`items.${idx}.occurred_at`]
        return (
          <div key={idx} style={{ display: 'flex', gap: '8px', marginBottom: '8px', flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div style={{ flex: '1 1 170px' }}>
              <label style={incidentLabelStyle}>Type *</label>
              <select value={it.type} onChange={e => update(idx, { type: e.target.value })} required style={{ ...incidentInputStyle, cursor: 'pointer' }}>
                {INCIDENT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <div style={{ flex: '1 1 120px' }}>
              <label style={incidentLabelStyle}>Severity *</label>
              <select value={it.severity} onChange={e => update(idx, { severity: e.target.value })} required style={{ ...incidentInputStyle, cursor: 'pointer' }}>
                {INCIDENT_SEVERITIES.map(s => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
              </select>
            </div>
            <div style={{ flex: '1 1 170px' }}>
              <label style={incidentLabelStyle}>When *</label>
              <input type="datetime-local" value={it.occurred_at} onChange={e => update(idx, { occurred_at: e.target.value })} required style={incidentInputStyle} />
              {whenError && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{whenError}</div>}
            </div>
            {items.length > 1 && (
              <button type="button" onClick={() => remove(idx)} style={{ ...btnGhost, padding: '8px 10px' }}>Remove</button>
            )}
          </div>
        )
      })}
      <button type="button" onClick={add} style={{ ...btnGhost, marginTop: '2px' }}>+ Add another type</button>
    </div>
  )
}

type Photo = { id: string; url: string }

/**
 * Full-screen photo viewer: one large main image with a thumbnail strip beneath.
 * Click a thumbnail to swap it into the main slot; click the backdrop / × / Esc to close.
 */
function PhotoLightbox({ photos, index, onClose }: { photos: Photo[]; index: number; onClose: () => void }) {
  const [active, setActive] = useState(index)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return createPortal(
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 1100, background: 'rgba(0,0,0,0.88)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '32px 24px' }}>
      <button type="button" onClick={onClose} aria-label="Close" style={{ position: 'absolute', top: '16px', right: '20px', width: '40px', height: '40px', border: 'none', background: 'transparent', color: '#fff', fontSize: '28px', lineHeight: 1, cursor: 'pointer' }}>×</button>
      <div onClick={e => e.stopPropagation()} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '14px', maxWidth: '1000px', width: '100%' }}>
        <img src={photos[active].url} alt="Incident evidence" style={{ maxWidth: '100%', maxHeight: '72vh', objectFit: 'contain' }} />
        {photos.length > 1 && (
          <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap', justifyContent: 'center' }}>
            {photos.map((p, i) => (
              <button key={p.id} type="button" onClick={() => setActive(i)} style={{ width: '64px', height: '64px', padding: 0, cursor: 'pointer', background: 'transparent', border: `2px solid ${i === active ? ACCENT : 'rgba(255,255,255,0.35)'}` }}>
                <img src={p.url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
              </button>
            ))}
          </div>
        )}
      </div>
    </div>,
    document.body,
  )
}

/** A row of clickable photo thumbnails that open the {@link PhotoLightbox} at the chosen image. */
function PhotoThumbs({ photos, size = 56 }: { photos: Photo[]; size?: number }) {
  const [open, setOpen] = useState<number | null>(null)
  if (photos.length === 0) return null
  return (
    <>
      <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
        {photos.map((p, i) => (
          <button key={p.id} type="button" onClick={() => setOpen(i)} style={{ width: `${size}px`, height: `${size}px`, padding: 0, cursor: 'pointer', background: 'transparent', border: `1px solid ${FIELD_BORDER}` }}>
            <img src={p.url} alt="Incident evidence" style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
          </button>
        ))}
      </div>
      {open !== null && <PhotoLightbox photos={photos} index={open} onClose={() => setOpen(null)} />}
    </>
  )
}

type Party = { full_name: string; is_minor: boolean }

/**
 * Dynamic editor for the people involved in an incident — one row per person.
 * We capture a full name and a single "under 18" flag (no date of birth is stored).
 * Parties are optional; start with no rows.
 */
function PartiesEditor({ parties, onChange, errors }: { parties: Party[]; onChange: (parties: Party[]) => void; errors?: Record<string, string> }) {
  const update = (idx: number, patch: Partial<Party>) => onChange(parties.map((p, i) => (i === idx ? { ...p, ...patch } : p)))
  const add = () => onChange([...parties, { full_name: '', is_minor: false }])
  const remove = (idx: number) => onChange(parties.filter((_, i) => i !== idx))

  return (
    <div style={{ marginBottom: '12px' }}>
      <label style={incidentLabelStyle}>Parties involved (optional)</label>
      <div style={{ fontFamily: 'var(--body)', fontSize: '12px', color: TAN, marginBottom: '8px' }}>
        The people involved in this incident. Tick "Under 18" for any minor — no date of birth is recorded.
      </div>
      {parties.map((p, idx) => {
        const nameError = errors?.[`parties.${idx}.full_name`]
        return (
          <div key={idx} style={{ display: 'flex', gap: '10px', marginBottom: '8px', alignItems: 'flex-end' }}>
            <div style={{ flex: '0 1 380px', minWidth: 0 }}>
              <label style={incidentLabelStyle}>Full name *</label>
              <input value={p.full_name} onChange={e => update(idx, { full_name: e.target.value })} maxLength={200} style={incidentInputStyle} />
              {nameError && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{nameError}</div>}
            </div>
            <label style={{ ...incidentCheckRow, flexShrink: 0, whiteSpace: 'nowrap', paddingBottom: '9px' }}>
              <input type="checkbox" checked={p.is_minor} onChange={e => update(idx, { is_minor: e.target.checked })} />
              Under 18
            </label>
            <button type="button" onClick={() => remove(idx)} style={{ ...btnGhost, flexShrink: 0, padding: '8px 10px' }}>Remove</button>
          </div>
        )
      })}
      <button type="button" onClick={add} style={{ ...btnGhost, marginTop: '2px' }}>+ Add a person</button>
    </div>
  )
}

type IncidentFormData = { items: IncidentItem[]; parties: Party[]; location_description: string; description: string; injuries_reported: boolean; authorities_notified: boolean; authority_report_number: string; evidence: File[] }

function ReportIncidentForm({ url }: { url: string }) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<IncidentFormData>(
    { items: [{ type: 'trespassing', severity: 'minor', occurred_at: '' }], parties: [], location_description: '', description: '', injuries_reported: false, authorities_notified: false, authority_report_number: '', evidence: [] },
  )

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(url, { forceFormData: true, onSuccess: () => { reset(); setOpen(false) } })
  }

  const labelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
  const inputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }
  const checkRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: '8px', fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE }

  if (!open) {
    return <button onClick={() => setOpen(true)} style={btnDark}>+ Report an Incident</button>
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '12px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', fontWeight: 600 }}>
        Report a Safety Incident
      </div>
      <IncidentItemsEditor items={data.items} onChange={items => setData('items', items)} errors={errors as Record<string, string>} />
      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>Location on the property (optional)</label>
        <input value={data.location_description} onChange={e => setData('location_description', e.target.value)} maxLength={500} style={inputStyle} />
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label style={labelStyle}>What happened *</label>
        <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} maxLength={2000} required style={{ ...inputStyle, resize: 'vertical' }} />
        {errors.description && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.description}</div>}
      </div>
      <PartiesEditor parties={data.parties} onChange={parties => setData('parties', parties)} errors={errors as Record<string, string>} />
      <div style={{ display: 'flex', gap: '20px', marginBottom: '12px', flexWrap: 'wrap' }}>
        <label style={checkRow}>
          <input type="checkbox" checked={data.injuries_reported} onChange={e => setData('injuries_reported', e.target.checked)} />
          Injuries occurred
        </label>
        <label style={checkRow}>
          <input type="checkbox" checked={data.authorities_notified} onChange={e => setData('authorities_notified', e.target.checked)} />
          Authorities notified
        </label>
      </div>
      {data.authorities_notified && (
        <div style={{ marginBottom: '12px' }}>
          <label style={labelStyle}>Authority report number (optional)</label>
          <input value={data.authority_report_number} onChange={e => setData('authority_report_number', e.target.value)} maxLength={100} style={inputStyle} />
        </div>
      )}
      <div style={{ marginBottom: '16px' }}>
        <label style={labelStyle}>Photo evidence (optional, up to 10)</label>
        <FilePondUploader
          name="evidence"
          allowMultiple
          maxFiles={10}
          maxFileSize="10MB"
          acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
          labelIdle='Drag &amp; Drop photos or <span class="filepond--label-action">Browse</span>'
          onupdatefiles={items => setData('evidence', items.map(i => i.file as File))}
        />
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Submitting…' : 'Submit Report'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>Cancel</button>
      </div>
    </form>
  )
}

type IncidentReportRow = NonNullable<Props['incidents']>['reports'][number]

function EditIncidentForm({ report }: { report: IncidentReportRow }) {
  const [open, setOpen] = useState(false)
  const initialItems: IncidentItem[] = (report.items && report.items.length > 0)
    ? report.items.map(it => ({ type: it.type ?? 'other', severity: it.severity ?? 'minor', occurred_at: it.occurred_at_input ?? '' }))
    : [{ type: report.incident_type, severity: report.severity, occurred_at: report.occurred_at_input ?? '' }]
  const { data, setData, post, processing, errors, reset } = useForm<IncidentFormData>(
    {
      items: initialItems,
      parties: (report.parties ?? []).map(p => ({ full_name: p.full_name, is_minor: p.is_minor })),
      location_description: report.location_description ?? '',
      description: report.description,
      injuries_reported: report.injuries_reported,
      authorities_notified: report.authorities_notified,
      authority_report_number: report.authority_report_number ?? '',
      evidence: [],
    },
  )

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(report.edit_url!, { forceFormData: true, onSuccess: () => { reset('evidence'); setOpen(false) } })
  }

  if (!open) {
    return <button onClick={() => setOpen(true)} style={btnGhost}>Edit</button>
  }

  return (
    <form onSubmit={submit} style={{ background: '#fff', border: `1px solid ${FIELD_BORDER}`, padding: '18px', marginTop: '12px' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '14px', fontWeight: 600 }}>
        Edit Incident {report.incident_number ?? ''} — every change is recorded
      </div>
      <IncidentItemsEditor items={data.items} onChange={items => setData('items', items)} errors={errors as Record<string, string>} />
      <div style={{ marginBottom: '12px' }}>
        <label style={incidentLabelStyle}>Location on the property (optional)</label>
        <input value={data.location_description} onChange={e => setData('location_description', e.target.value)} maxLength={500} style={incidentInputStyle} />
      </div>
      <div style={{ marginBottom: '12px' }}>
        <label style={incidentLabelStyle}>What happened *</label>
        <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={3} maxLength={2000} required style={{ ...incidentInputStyle, resize: 'vertical' }} />
        {errors.description && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.description}</div>}
      </div>
      <PartiesEditor parties={data.parties} onChange={parties => setData('parties', parties)} errors={errors as Record<string, string>} />
      <div style={{ display: 'flex', gap: '20px', marginBottom: '12px', flexWrap: 'wrap' }}>
        <label style={incidentCheckRow}>
          <input type="checkbox" checked={data.injuries_reported} onChange={e => setData('injuries_reported', e.target.checked)} />
          Injuries occurred
        </label>
        <label style={incidentCheckRow}>
          <input type="checkbox" checked={data.authorities_notified} onChange={e => setData('authorities_notified', e.target.checked)} />
          Authorities notified
        </label>
      </div>
      {data.authorities_notified && (
        <div style={{ marginBottom: '12px' }}>
          <label style={incidentLabelStyle}>Authority report number (optional)</label>
          <input value={data.authority_report_number} onChange={e => setData('authority_report_number', e.target.value)} maxLength={100} style={incidentInputStyle} />
        </div>
      )}
      {report.photos.length > 0 && (
        <div style={{ marginBottom: '16px' }}>
          <label style={incidentLabelStyle}>Photos on file (permanent — cannot be removed)</label>
          <PhotoThumbs photos={report.photos} size={72} />
        </div>
      )}
      <div style={{ marginBottom: '16px' }}>
        <label style={incidentLabelStyle}>Add more photos (optional, up to 10)</label>
        <FilePondUploader
          name="evidence"
          allowMultiple
          maxFiles={10}
          maxFileSize="10MB"
          acceptedFileTypes={['image/jpeg', 'image/png', 'image/webp']}
          labelIdle='Drag &amp; Drop photos or <span class="filepond--label-action">Browse</span>'
          onupdatefiles={items => setData('evidence', items.map(i => i.file as File))}
        />
      </div>
      <div style={{ display: 'flex', gap: '8px' }}>
        <button type="submit" disabled={processing} style={{ ...btnAccent, opacity: processing ? 0.7 : 1, cursor: processing ? 'not-allowed' : 'pointer' }}>
          {processing ? 'Saving…' : 'Save Changes'}
        </button>
        <button type="button" onClick={() => { reset(); setOpen(false) }} style={btnGhost}>Cancel</button>
      </div>
    </form>
  )
}

const incidentLabelStyle: React.CSSProperties = { display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '5px' }
const incidentInputStyle: React.CSSProperties = { width: '100%', padding: '8px 10px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box' }
const incidentCheckRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: '8px', fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE }

function IncidentsSection({ data }: { data: NonNullable<Props['incidents']> }) {
  return (
    <Section title="Safety Incidents">
      {data.reports.length > 0 ? (
        <div style={{ marginBottom: '14px' }}>
          {data.reports.map((r, i) => (
            <div key={r.id} style={{ borderBottom: i < data.reports.length - 1 ? `1px solid ${DIVIDER}` : 'none', paddingBottom: '12px', marginBottom: '12px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: '12px', flexWrap: 'wrap' }}>
                <div style={{ fontFamily: 'var(--body)', fontSize: '16px', fontWeight: 700, color: INK }}>
                  {incidentTitle(r.items, r.incident_type)}
                  <span style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.08em', textTransform: 'uppercase', color: TAN, marginLeft: '8px' }}>
                    {r.severity}{r.injuries_reported ? ' · injuries' : ''}
                  </span>
                </div>
                <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.08em', textTransform: 'uppercase', color: TAN }}>
                  {INCIDENT_STATUS_LABEL[r.status] ?? r.status}{r.occurred_at ? ` · ${r.occurred_at}` : ''}
                </div>
              </div>
              {r.incident_number && (
                <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.1em', color: ACCENT, marginTop: '3px' }}>{r.incident_number}</div>
              )}
              {(r.items?.length ?? 0) > 1 && (
                <div style={{ marginTop: '6px', display: 'flex', flexDirection: 'column', gap: '2px' }}>
                  {r.items!.map((it, idx) => (
                    <div key={idx} style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.04em', color: TAN }}>
                      {INCIDENT_TYPE_LABEL[it.type ?? ''] ?? it.type} — {it.severity}{it.occurred_at ? ` · ${it.occurred_at}` : ''}
                    </div>
                  ))}
                </div>
              )}
              <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>{r.description}</div>
              {r.location_description && (
                <div style={{ fontFamily: 'var(--body)', fontSize: '12px', color: TAN, marginTop: '2px' }}>Location: {r.location_description}</div>
              )}
              {(r.parties?.length ?? 0) > 0 && (
                <div style={{ fontFamily: 'var(--body)', fontSize: '12px', color: TAN, marginTop: '2px' }}>
                  Parties: {r.parties.map((p, idx) => (
                    <span key={idx}>{idx > 0 ? ', ' : ''}{p.full_name}{p.is_minor ? ' (under 18)' : ''}</span>
                  ))}
                </div>
              )}
              {r.photos.length > 0 && (
                <div style={{ marginTop: '8px' }}>
                  <PhotoThumbs photos={r.photos} />
                </div>
              )}
              {r.can_edit && (
                <div style={{ marginTop: '10px' }}>
                  <EditIncidentForm report={r} />
                </div>
              )}
            </div>
          ))}
        </div>
      ) : (
        <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: TAN, marginBottom: '14px' }}>
          No incidents reported for this lease.
        </div>
      )}
      <ReportIncidentForm url={data.report_url} />
    </Section>
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
              <div style={{ fontFamily: 'var(--display)', fontSize: '17px', fontWeight: 400, color: '#F4ECDC', letterSpacing: '.01em', lineHeight: 1.1 }}>
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

function ContactLine({ label, party }: { label: string; party: ContactParty }) {
  return (
    <div style={{ padding: '12px 0', borderBottom: `1px dotted ${FIELD_BORDER}` }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, marginBottom: '4px' }}>
        {label}
      </div>
      <div style={{ fontFamily: 'var(--body)', fontSize: '16px', fontWeight: 600, color: INK }}>
        {party.name || '—'}
      </div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px 18px', marginTop: '4px' }}>
        {party.phone && (
          <a href={`tel:${telHref(party.phone)}`} style={{ fontFamily: 'var(--mono)', fontSize: '13px', color: OLIVE, textDecoration: 'none' }}>
            ☎ {formatPhone(party.phone)}
          </a>
        )}
        {party.email && (
          <a href={`mailto:${party.email}`} style={{ fontFamily: 'var(--mono)', fontSize: '13px', color: OLIVE, textDecoration: 'none' }}>
            ✉ {party.email}
          </a>
        )}
      </div>
    </div>
  )
}

function LocalContactRow({ contact, isLast }: { contact: LocalContact; isLast: boolean }) {
  const heading = contact.organization || contact.name || contact.type_label
  return (
    <div style={{ padding: '12px 0', borderBottom: isLast ? 'none' : `1px dotted ${FIELD_BORDER}` }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase', color: ACCENT, marginBottom: '4px' }}>
        {contact.type_label}
      </div>
      <div style={{ fontFamily: 'var(--body)', fontSize: '16px', fontWeight: 600, color: INK }}>
        {heading}
      </div>
      {contact.organization && contact.name && (
        <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE, marginTop: '2px' }}>{contact.name}</div>
      )}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px 18px', marginTop: '4px' }}>
        {contact.phone && (
          <a href={`tel:${telHref(contact.phone)}`} style={{ fontFamily: 'var(--mono)', fontSize: '13px', color: OLIVE, textDecoration: 'none' }}>
            ☎ {formatPhone(contact.phone)}
          </a>
        )}
        {contact.email && (
          <a href={`mailto:${contact.email}`} style={{ fontFamily: 'var(--mono)', fontSize: '13px', color: OLIVE, textDecoration: 'none' }}>
            ✉ {contact.email}
          </a>
        )}
      </div>
      {contact.address && (
        <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: INK, marginTop: '6px', lineHeight: 1.5 }}>{contact.address}</div>
      )}
      {contact.notes && (
        <div style={{ fontFamily: 'var(--body)', fontSize: '14px', color: OLIVE, marginTop: '4px', lineHeight: 1.5, fontStyle: 'italic' }}>{contact.notes}</div>
      )}
    </div>
  )
}

function ContactsSection({ contacts }: { contacts: ContactDirectory }) {
  const hasParties = contacts.landowner !== null || contacts.managers.length > 0
  const hasLocal   = contacts.contacts.length > 0

  if (!hasParties && !hasLocal) return null

  return (
    <Section title="Contacts">
      {contacts.landowner && <ContactLine label="Landowner" party={contacts.landowner} />}
      {contacts.managers.map((m, i) => (
        <ContactLine key={`mgr-${i}`} label={m.role_label || 'Property Manager'} party={m} />
      ))}

      {hasLocal && (
        <div style={{ marginTop: hasParties ? '16px' : '0' }}>
          {hasParties && (
            <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: TAN, marginBottom: '8px', borderBottom: `1px solid ${DIVIDER}`, paddingBottom: '6px' }}>
              Emergency &amp; Local Contacts
            </div>
          )}
          {contacts.contacts.map((c, i) => (
            <LocalContactRow key={`loc-${i}`} contact={c} isLast={i === contacts.contacts.length - 1} />
          ))}
        </div>
      )}
    </Section>
  )
}

function CommunicationsSection({ data, isLessor }: { data: Communications; isLessor: boolean }) {
  const { data: form, setData, post, processing, errors, reset } = useForm({ message: '' })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(data.message_url, { preserveScroll: true, onSuccess: () => reset('message') })
  }

  const otherParty = isLessor ? 'hunter' : 'landowner'

  return (
    <Section title="Communications">
      {data.messages.length === 0 ? (
        <p style={{ fontFamily: 'var(--body)', fontSize: '15px', color: TAN, fontStyle: 'italic', margin: '0 0 16px' }}>
          No messages yet.
        </p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginBottom: '20px' }}>
          {data.messages.map((m, i) => {
            const mine = m.is_me
            const who = mine ? 'You' : (m.role === 'admin' ? 'Staff' : m.sender_name)
            return (
              <div key={i} style={{ alignSelf: mine ? 'flex-end' : 'flex-start', maxWidth: '80%', background: mine ? INK : '#fff', color: mine ? '#F4ECDC' : INK, border: `1px solid ${mine ? INK : FIELD_BORDER}`, padding: '12px 14px' }}>
                <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: mine ? TAN : '#9c9388', marginBottom: '6px' }}>
                  {who}{m.sent_at ? ` · ${m.sent_at}` : ''}
                </div>
                <div style={{ fontFamily: 'var(--body)', fontSize: '15px', lineHeight: 1.5, whiteSpace: 'pre-wrap' }}>{m.message}</div>
              </div>
            )
          })}
        </div>
      )}

      <form onSubmit={submit} style={{ borderTop: `1px solid ${DIVIDER}`, paddingTop: '18px' }}>
        <label htmlFor="lease-message" style={{ display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: OLIVE, marginBottom: '6px' }}>
          Message the {otherParty}
        </label>
        <textarea
          id="lease-message"
          rows={3}
          value={form.message}
          onChange={e => setData('message', e.target.value)}
          placeholder={`Write a message to the ${otherParty}…`}
          style={{ width: '100%', padding: '10px 12px', border: `1px solid ${FIELD_BORDER}`, fontFamily: 'var(--body)', fontSize: '15px', background: '#fff', boxSizing: 'border-box', resize: 'vertical', lineHeight: 1.5 }}
        />
        {errors.message && <div style={{ color: '#b91c1c', fontFamily: 'var(--body)', fontSize: '13px', marginTop: '4px' }}>{errors.message}</div>}
        <div style={{ marginTop: '12px' }}>
          <button type="submit" disabled={processing || !form.message.trim()} style={{ ...btnDark, opacity: (processing || !form.message.trim()) ? 0.6 : 1, cursor: (processing || !form.message.trim()) ? 'not-allowed' : 'pointer' }}>
            {processing ? 'Sending…' : 'Send Message'}
          </button>
        </div>
      </form>
    </Section>
  )
}

export default function Lease({ lease, property, access_info, deposit, landowner_deposit, booking_deposit, lease_payment, landowner_finance, contacts, signers, sign_url, signed_lease_url, is_lessor, documents, document_tags, upload_url, check_in, qr, stand_map, email_qr_url, communications, damage_claims, incidents }: Props) {
  const { flash } = usePage<{ flash: { success: string | null; error: string | null } }>().props
  const statusColor = STATUS_COLOR[lease.status] ?? TAN
  const statusLabel = STATUS_LABEL[lease.status] ?? lease.status
  const allSigned   = signers.every(s => s.status === 'signed')
  const [payingDeposit, setPayingDeposit] = useState(false)
  const [payingLease, setPayingLease] = useState(false)

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
                <div style={{ fontFamily: 'var(--display)', fontSize: '17px', fontWeight: 400, color: '#F4ECDC', letterSpacing: '.01em', lineHeight: 1.1 }}>
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

        <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '32px 24px 80px' }}>

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

            {signed_lease_url && (
              <div style={{ marginTop: '18px', paddingTop: '16px', borderTop: `1px solid ${DIVIDER}` }}>
                <a href={signed_lease_url} style={btnDark}>
                  <svg style={{ width: '14px', height: '14px' }} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  Download Signed Lease
                </a>
              </div>
            )}
          </Section>

          {/* Security Deposit — lessee only; amount derives from the listing */}
          {deposit && (
            <Section title="Security Deposit">
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '14px' }}>
                <div>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: TAN, marginBottom: '5px' }}>
                    {deposit.can_pay ? 'Amount Due' : 'Deposit'}
                  </div>
                  <div style={{ fontFamily: 'var(--body)', fontSize: '22px', fontWeight: 700, color: INK }}>${deposit.amount}</div>
                  {deposit.status === 'held' && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>
                      Held in full — refundable at the end of your lease.
                    </div>
                  )}
                  {deposit.status === 'released' && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>
                      Released — ${deposit.refunded} refunded to you.
                    </div>
                  )}
                  {(deposit.status === 'forfeited' || deposit.status === 'partially_released') && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: '#9a3412', marginTop: '4px' }}>
                      ${deposit.forfeited} forfeited{Number(deposit.refunded) > 0 ? ` · $${deposit.refunded} refunded` : ''}.
                    </div>
                  )}
                </div>

                {deposit.can_pay ? (
                  <button
                    type="button"
                    disabled={payingDeposit}
                    onClick={() => {
                      setPayingDeposit(true)
                      router.post(deposit.pay_url, {}, { onFinish: () => setPayingDeposit(false) })
                    }}
                    style={{ ...btnAccent, whiteSpace: 'nowrap', opacity: payingDeposit ? 0.6 : 1 }}
                  >
                    {payingDeposit ? 'Redirecting…' : 'Pay Deposit'}
                  </button>
                ) : (
                  <span style={{
                    display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '10px 18px',
                    background: deposit.status === 'held' ? OLIVE : 'transparent',
                    border: `1px solid ${deposit.status === 'held' ? OLIVE : TAN}`,
                    fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.08em',
                    textTransform: 'uppercase', color: deposit.status === 'held' ? '#F4ECDC' : TAN,
                  }}>
                    {deposit.status === 'held' && (
                      <svg width="11" height="11" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M13.5 4.5 6.5 11.5 3 8" stroke="#F4ECDC" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    )}
                    {deposit.status === 'held' ? 'Held' : deposit.status === 'released' ? 'Released' : deposit.status === 'partially_released' ? 'Partial' : 'Forfeited'}
                  </span>
                )}
              </div>
              {deposit.forfeit && <ForfeitureNotice deposit={deposit} />}
            </Section>
          )}

          {/* Booking Fee — lessee only; paid in the apply portal to claim the spot,
              held by the platform and credited toward the lease total. Informational
              here — there is no pay action (payment happens before the lease exists). */}
          {booking_deposit && (
            <Section title="Booking Fee">
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '14px' }}>
                <div>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: TAN, marginBottom: '5px' }}>
                    Booking Fee
                  </div>
                  <div style={{ fontFamily: 'var(--body)', fontSize: '22px', fontWeight: 700, color: INK }}>${booking_deposit.amount}</div>
                  <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: booking_deposit.paid ? OLIVE : TAN, marginTop: '4px' }}>
                    {booking_deposit.paid
                      ? `Paid to claim your spot — credited toward your total. Remaining balance $${booking_deposit.remaining_balance}.`
                      : 'Booking fee for this lease.'}
                  </div>
                </div>

                {booking_deposit.paid && (
                  <span style={{
                    display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '10px 18px',
                    background: OLIVE, border: `1px solid ${OLIVE}`,
                    fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.08em',
                    textTransform: 'uppercase', color: '#F4ECDC',
                  }}>
                    <svg width="11" height="11" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                      <path d="M13.5 4.5 6.5 11.5 3 8" stroke="#F4ECDC" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                    Held
                  </span>
                )}
              </div>
            </Section>
          )}

          {lease_payment && (
            <Section title="Lease Balance">
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '14px' }}>
                <div>
                  <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '.1em', color: TAN, marginBottom: '5px' }}>
                    {lease_payment.balance_due ? 'Balance Due' : 'Lease Balance'}
                  </div>
                  <div style={{ fontFamily: 'var(--body)', fontSize: '22px', fontWeight: 700, color: INK }}>${lease_payment.balance}</div>
                  {lease_payment.balance_due && lease_payment.can_pay && lease_payment.surcharge && Number(lease_payment.surcharge) > 0 && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: TAN, marginTop: '4px' }}>
                      Plus a ${lease_payment.surcharge} processing fee — ${lease_payment.total_charge} total.
                    </div>
                  )}
                  {lease_payment.balance_due && !lease_payment.landowner_charges_enabled && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: TAN, marginTop: '4px', fontStyle: 'italic' }}>
                      Awaiting landowner payout setup — you'll be able to pay once it's complete.
                    </div>
                  )}
                  {lease.status === 'pending_payment' && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: ACCENT, marginTop: '8px', fontWeight: 700 }}>
                      Your lease is signed but not yet active. Check-in, the gate code, and the stand map unlock once this balance is paid.
                    </div>
                  )}
                  {!lease_payment.balance_due && (
                    <div style={{ fontFamily: 'var(--body)', fontSize: '13px', color: OLIVE, marginTop: '4px' }}>
                      Paid in full.
                    </div>
                  )}
                </div>

                {lease_payment.can_pay ? (
                  <button
                    type="button"
                    disabled={payingLease}
                    onClick={() => {
                      setPayingLease(true)
                      router.post(lease_payment.pay_url, {}, { onFinish: () => setPayingLease(false) })
                    }}
                    style={{ ...btnAccent, whiteSpace: 'nowrap', opacity: payingLease ? 0.6 : 1 }}
                  >
                    {payingLease ? 'Redirecting…' : 'Pay Lease Balance'}
                  </button>
                ) : lease_payment.balance_due ? (
                  <span style={{
                    display: 'inline-flex', alignItems: 'center', padding: '10px 18px',
                    background: 'transparent', border: `1px solid ${TAN}`,
                    fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 700, letterSpacing: '.08em',
                    textTransform: 'uppercase', color: TAN,
                  }}>
                    Not Yet Payable
                  </span>
                ) : null}
              </div>

              {lease_payment.payments.length > 0 && (
                <div style={{ marginTop: '16px', borderTop: `1px dotted ${FIELD_BORDER}`, paddingTop: '12px' }}>
                  {lease_payment.payments.map((p, i) => (
                    <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '6px 0' }}>
                      <span style={{ fontFamily: 'var(--body)', fontSize: '14px', color: INK }}>
                        ${p.amount}{p.paid_at ? ` · ${p.paid_at}` : ''}
                      </span>
                      <span style={{ fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.08em', color: p.status === 'collected' ? OLIVE : TAN }}>
                        {p.status === 'collected' ? 'Paid' : p.status === 'partially_refunded' ? 'Partial Refund' : 'Refunded'}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </Section>
          )}

          {/* Payment Status — lessor's read-only view of what the hunter has paid */}
          {is_lessor && landowner_finance && (
            <Section title="Payment Status">
              <LandownerFinance data={landowner_finance} />
            </Section>
          )}

          {/* Security Deposit — lessor manages the held deposit: release or file a claim */}
          {is_lessor && landowner_deposit && <LandownerDepositSection data={landowner_deposit} />}

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

          {/* Contacts — landowner, managers, local law enforcement, game warden, emergency */}
          {contacts && <ContactsSection contacts={contacts} />}

          {/* Communications — application message thread with the other party */}
          {communications && <CommunicationsSection data={communications} isLessor={is_lessor} />}

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

          {/* Damage Claims — lessor files itemized claims against the held deposit */}
          {is_lessor && damage_claims && <DamageClaimsSection data={damage_claims} />}
          {incidents && <IncidentsSection data={incidents} />}

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
