import { useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { PortalChrome, TitleHead, Section, BackLink, Modal, ScaleIcon, DocumentTextIcon, UserIcon, PencilSquareIcon, PaperClipIcon, UserGroupIcon, ChatEllipsisIcon, ChatLeftRightIcon, ClockIcon, BanknotesIcon, INK, ACCENT, TAN, type PropertySummary } from '@/Components/Member/PropertyChrome'
import LandownerFinance, { type LandownerFinanceData } from '@/Components/Member/LandownerFinance'

interface Application {
  id: string
  ref: string
  status: string
  status_label: string
  type: string
  proposed_start: string | null
  proposed_end: string | null
  hunters: number
  submitted_at: string | null
  message: string | null
  admin_notes: string | null
  reviewed_at: string | null
  rejection_reason: string | null
}

interface Hunter { name: string; type: string; is_minor: boolean; email: string | null; cell: string | null }
interface Signer { name: string; role: string; email: string; status: string; signed_at: string | null }
interface Message { role: string; sender_name: string; message: string; sent_at: string | null }
interface History { label: string; to: string; reason: string | null; decided_at: string | null }
interface LeaseDoc { label: string; badge: string; subtitle: string; filename: string; size: string; date: string; download_url: string }

interface Props {
  property: PropertySummary & { id: string }
  application: Application
  listing: { ref: string; title: string; location: string }
  applicant: { name: string; email: string; ref: string }
  hunters: Hunter[]
  lease: { ref: string; status: string; start_date: string | null; end_date: string | null; total_price: number | null } | null
  payment_summary: LandownerFinanceData | null
  signers: Signer[]
  signing_url: string | null
  documents: LeaseDoc[]
  messages: Message[]
  history: History[]
  defaults: { start_date: string | null; end_date: string | null; total_price: number | null }
}

const STATUS_COLOR: Record<string, string> = {
  pending: '#b05a00', under_review: '#3d6b8e', approved: '#15803d',
  rejected: '#b91c1c', withdrawn: '#9c9388', expired: '#9c9388',
  active: '#15803d', pending_signatures: '#b05a00', terminated: '#b91c1c', cancelled: '#b91c1c',
}

const mono: React.CSSProperties = { fontFamily: 'JetBrains Mono, monospace' }

function StatusPill({ status, label }: { status: string; label: string }) {
  const color = STATUS_COLOR[status] ?? INK
  return (
    <span style={{ ...mono, fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', border: `1px solid ${color}`, color }}>
      {label}
    </span>
  )
}

function Datum({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div style={{ ...mono, fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: TAN, marginBottom: '5px' }}>{label}</div>
      <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>{children ?? '—'}</div>
    </div>
  )
}

const labelStyle: React.CSSProperties = { display: 'block', ...mono, fontSize: '10px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: TAN, marginBottom: '6px' }
const inputStyle: React.CSSProperties = { width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK, background: '#fff', border: '1px solid #d4c9b0', padding: '9px 11px', outline: 'none', boxSizing: 'border-box' }
const errStyle: React.CSSProperties = { ...mono, fontSize: '10px', color: ACCENT, marginTop: '5px' }

function money(v: number | null): string {
  if (v === null) return '—'
  return '$' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const ROLE_LABEL: Record<string, string> = { admin: 'Staff', landowner: 'Landowner', applicant: 'Applicant' }

export default function ApplicationShow({ property, application, listing, applicant, hunters, lease, payment_summary, signers, signing_url, documents, messages, history, defaults }: Props) {
  const flash = (usePage().props as { flash?: { success?: string; error?: string } }).flash ?? {}
  const [modal, setModal] = useState<null | 'approve' | 'reject'>(null)
  const base = `/member/properties/${property.id}/applications/${application.id}`
  const isPending = application.status === 'pending'

  const msgForm = useForm({ message: '' })
  function sendMessage(e: React.FormEvent) {
    e.preventDefault()
    msgForm.post(`${base}/message`, { preserveScroll: true, onSuccess: () => msgForm.reset('message') })
  }

  return (
    <PortalChrome headTitle={`${applicant.name} · Application`}>

      <BackLink href={`/member/properties/${property.id}/applications`}>← Back to Applications</BackLink>

      <TitleHead
        kicker="Lease Application"
        title={applicant.name}
        subtitle={`${listing.title} · ${listing.location}`}
        badge={{ label: application.status_label, color: STATUS_COLOR[application.status] ?? TAN }}
      />

      {flash.success && (
        <div style={{ border: '1px solid #15803d', background: '#f0fdf4', color: '#15803d', padding: '12px 16px', marginBottom: '20px', ...mono, fontSize: '11px', letterSpacing: '.04em' }}>{flash.success}</div>
      )}
      {flash.error && (
        <div style={{ border: '1px solid #b91c1c', background: '#fef2f2', color: '#b91c1c', padding: '12px 16px', marginBottom: '20px', ...mono, fontSize: '11px', letterSpacing: '.04em' }}>{flash.error}</div>
      )}

      {/* Decision actions */}
      {isPending && (
        <Section title="Decision" icon={<ScaleIcon />} description="Approve this application to create the lease and start the e-signature flow, or reject it with a reason for the applicant.">
          <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>
            <button onClick={() => setModal('approve')} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: ACCENT, color: '#fff', border: 'none', cursor: 'pointer' }}>
              Approve & Create Lease
            </button>
            <button onClick={() => setModal('reject')} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '11px 26px', background: 'transparent', color: '#b91c1c', border: '1px solid rgba(185,28,28,0.4)', cursor: 'pointer' }}>
              Reject
            </button>
          </div>
        </Section>
      )}

      {/* Application details */}
      <Section title="Application Details" icon={<DocumentTextIcon />} description="What the applicant requested — dates, party size, and their message to you.">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '20px' }}>
          <Datum label="Application ID"><span style={mono}>{application.ref}</span></Datum>
          <Datum label="Status"><StatusPill status={application.status} label={application.status_label} /></Datum>
          <Datum label="Type">{application.type}</Datum>
          <Datum label="Proposed Start">{application.proposed_start}</Datum>
          <Datum label="Proposed End">{application.proposed_end}</Datum>
          <Datum label="Hunters Named">{application.hunters}</Datum>
          <Datum label="Submitted">{application.submitted_at}</Datum>
        </div>
        <div style={{ marginTop: '20px' }}>
          <Datum label="Message to Landowner">
            <span style={{ whiteSpace: 'pre-wrap' }}>{application.message || 'No message provided.'}</span>
          </Datum>
        </div>
      </Section>

      {/* Applicant */}
      <Section title="Applicant" icon={<UserIcon />} description="The hunter who submitted this application and the listing they applied to.">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '20px' }}>
          <Datum label="Name">{applicant.name}</Datum>
          <Datum label="Email">{applicant.email || '—'}</Datum>
          <Datum label="Applicant ID"><span style={mono}>{applicant.ref}</span></Datum>
          <Datum label="Listing"><span style={mono}>{listing.ref}</span> · {listing.title}</Datum>
          <Datum label="Location">{listing.location}</Datum>
        </div>
      </Section>

      {/* Lease & signing status */}
      {lease && (
        <Section title="Lease & Signing Status" icon={<PencilSquareIcon />} description="The lease created from this application and where each party stands in the e-signature flow.">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '16px', padding: '16px', background: '#fff', border: '1px solid #e5ddd0', marginBottom: '16px' }}>
            <Datum label="Lease ID"><span style={mono}>{lease.ref}</span></Datum>
            <Datum label="Status"><StatusPill status={lease.status} label={lease.status.replace(/_/g, ' ')} /></Datum>
            <Datum label="Term">{lease.start_date ?? '—'} – {lease.end_date ?? '—'}</Datum>
            <Datum label="Total Price">{money(lease.total_price)}</Datum>
          </div>
          {signers.length === 0 ? (
            <p style={{ ...mono, fontSize: '11px', color: '#6b5e50' }}>No signing request found.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {signers.map((s, i) => {
                const signed = s.status === 'signed'
                const c = signed ? '#15803d' : '#b05a00'
                return (
                  <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 14px', background: signed ? '#f0fdf4' : '#fff7ed', border: `1px solid ${signed ? '#bbf7d0' : '#fed7aa'}` }}>
                    <span style={{ fontSize: '16px', fontWeight: 700, color: c }}>{signed ? '✓' : '○'}</span>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>{s.name}</div>
                      <div style={{ ...mono, fontSize: '10px', color: '#6b5e50' }}>{s.role} · {s.email}</div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                      <span style={{ ...mono, fontSize: '10px', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.08em', color: c }}>{s.status}</span>
                      {signed && s.signed_at && <div style={{ ...mono, fontSize: '10px', color: '#6b5e50' }}>{s.signed_at}</div>}
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </Section>
      )}

      {/* Payment status */}
      {payment_summary && (
        <Section title="Payment Status" icon={<BanknotesIcon />}>
          <LandownerFinance data={payment_summary} />
        </Section>
      )}

      {/* Lease documents */}
      {lease && documents.length > 0 && (
        <Section title="Lease Documents" icon={<PaperClipIcon />} description="The contract sent for signature, the fully-executed copy, and any attachments.">
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {documents.map((d, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '14px', padding: '12px 16px', background: '#fff', border: '1px solid #d4c9b0' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                    <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: INK }}>{d.label}</span>
                    <span style={{ ...mono, fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', border: `1px solid ${TAN}`, color: '#6b5e50' }}>{d.badge}</span>
                  </div>
                  {d.subtitle && <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#6b5e50', marginTop: '3px' }}>{d.subtitle}</div>}
                  <div style={{ ...mono, fontSize: '10px', color: '#9c9388', marginTop: '4px' }}>
                    {[d.filename, d.size, d.date].filter(Boolean).join(' · ')}
                  </div>
                </div>
                <a href={d.download_url} style={{ ...mono, fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 18px', background: 'transparent', color: INK, border: `1px solid ${TAN}`, textDecoration: 'none', whiteSpace: 'nowrap' }}>
                  Download ↓
                </a>
              </div>
            ))}
          </div>
        </Section>
      )}

      {/* Hunter roster */}
      <Section title="Hunter Roster" icon={<UserGroupIcon />} description="Everyone named to hunt under this application.">
        {hunters.length === 0 ? (
          <p style={{ ...mono, fontSize: '11px', color: '#6b5e50' }}>No hunter details captured.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {hunters.map((h, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '12px 16px', background: '#fff', border: '1px solid #d4c9b0' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                    <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: INK }}>{h.name}</span>
                    <span style={{ ...mono, fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', background: h.type === 'Primary' ? INK : '#9c9388', color: '#fff' }}>{h.type}</span>
                    {h.is_minor && <span style={{ ...mono, fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', border: `1px solid ${ACCENT}`, color: ACCENT }}>Minor</span>}
                  </div>
                  <div style={{ ...mono, fontSize: '10px', color: '#6b5e50', marginTop: '4px' }}>
                    {[h.email, h.cell].filter(Boolean).join(' · ') || '—'}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* Notes */}
      {application.admin_notes && (
        <Section title="Notes" icon={<ChatEllipsisIcon />} description="Visible to staff and landowner only — not shown to the applicant.">
          <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK, whiteSpace: 'pre-wrap' }}>{application.admin_notes}</div>
        </Section>
      )}

      {/* Communications */}
      <Section title="Communications" icon={<ChatLeftRightIcon />} description="Messages between you and the applicant. The applicant is emailed when you send a message.">
        {messages.length === 0 ? (
          <p style={{ ...mono, fontSize: '11px', color: '#6b5e50', marginBottom: '18px' }}>No messages yet.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginBottom: '20px' }}>
            {messages.map((m, i) => {
              const mine = m.role === 'landowner'
              return (
                <div key={i} style={{ alignSelf: mine ? 'flex-end' : 'flex-start', maxWidth: '78%', background: mine ? INK : '#fff', color: mine ? '#F4ECDC' : INK, border: `1px solid ${mine ? INK : '#d4c9b0'}`, padding: '12px 14px' }}>
                  <div style={{ ...mono, fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: mine ? TAN : '#9c9388', marginBottom: '6px' }}>
                    {m.sender_name} ({ROLE_LABEL[m.role] ?? m.role}){m.sent_at ? ` · ${m.sent_at}` : ''}
                  </div>
                  <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', whiteSpace: 'pre-wrap' }}>{m.message}</div>
                </div>
              )
            })}
          </div>
        )}

        <form onSubmit={sendMessage} style={{ borderTop: '1px solid #e5ddd0', paddingTop: '18px' }}>
          <label htmlFor="message" style={labelStyle}>Message to Applicant</label>
          <textarea id="message" rows={3} value={msgForm.data.message} onChange={e => msgForm.setData('message', e.target.value)} style={{ ...inputStyle, resize: 'vertical', lineHeight: 1.5 }} placeholder="Write a message to the applicant…" />
          {msgForm.errors.message && <div style={errStyle}>{msgForm.errors.message}</div>}
          <div style={{ marginTop: '12px' }}>
            <button type="submit" disabled={msgForm.processing || !msgForm.data.message.trim()} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 24px', background: INK, color: '#F4ECDC', border: 'none', cursor: msgForm.processing ? 'not-allowed' : 'pointer', opacity: (msgForm.processing || !msgForm.data.message.trim()) ? 0.6 : 1 }}>
              {msgForm.processing ? 'Sending…' : 'Send Message'}
            </button>
          </div>
        </form>
      </Section>

      {/* Review history */}
      {history.length > 0 && (
        <Section title="Review History" icon={<ClockIcon />} description="Every approval, rejection, and override recorded in order.">
          <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
            {history.map((r, i) => (
              <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', padding: '10px 14px', background: '#fff', border: '1px solid #d4c9b0' }}>
                <StatusPill status={r.to} label={r.label} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  {r.reason && <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: INK }}>{r.reason}</div>}
                  {r.decided_at && <div style={{ ...mono, fontSize: '10px', color: '#6b5e50', marginTop: r.reason ? '4px' : 0 }}>{r.decided_at}</div>}
                </div>
              </div>
            ))}
          </div>
        </Section>
      )}

      {modal === 'approve' && <ApproveModal base={base} defaults={defaults} onClose={() => setModal(null)} />}
      {modal === 'reject' && <RejectModal base={base} onClose={() => setModal(null)} />}

    </PortalChrome>
  )
}

function ApproveModal({ base, defaults, onClose }: { base: string; defaults: { start_date: string | null; end_date: string | null; total_price: number | null }; onClose: () => void }) {
  const { data, setData, post, processing, errors } = useForm({
    start_date: defaults.start_date ?? '',
    end_date: defaults.end_date ?? '',
    total_price: defaults.total_price != null ? String(defaults.total_price) : '',
    sign_as_lessor: true,
    notify_applicant: true,
  })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(`${base}/approve`, { preserveScroll: true, onSuccess: onClose })
  }

  return (
    <Modal title="Approve & Create Lease" onClose={onClose} footer={
      <>
        <button form="approve-form" type="submit" disabled={processing} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 22px', background: ACCENT, color: '#fff', border: 'none', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.6 : 1 }}>
          {processing ? 'Approving…' : 'Approve'}
        </button>
        <button type="button" onClick={onClose} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 22px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', cursor: 'pointer' }}>Cancel</button>
      </>
    }>
      <form id="approve-form" onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
          <div>
            <label htmlFor="start_date" style={labelStyle}>Lease Start</label>
            <input id="start_date" type="date" value={data.start_date} onChange={e => setData('start_date', e.target.value)} style={inputStyle} />
            {errors.start_date && <div style={errStyle}>{errors.start_date}</div>}
          </div>
          <div>
            <label htmlFor="end_date" style={labelStyle}>Lease End</label>
            <input id="end_date" type="date" value={data.end_date} onChange={e => setData('end_date', e.target.value)} style={inputStyle} />
            {errors.end_date && <div style={errStyle}>{errors.end_date}</div>}
          </div>
        </div>
        <div>
          <label htmlFor="total_price" style={labelStyle}>Total Lease Price ($)</label>
          <input id="total_price" type="number" min={0} step="0.01" value={data.total_price} onChange={e => setData('total_price', e.target.value)} style={inputStyle} placeholder="0.00" />
          {errors.total_price && <div style={errStyle}>{errors.total_price}</div>}
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: '9px', cursor: 'pointer', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
          <input type="checkbox" checked={data.sign_as_lessor} onChange={e => setData('sign_as_lessor', e.target.checked)} />
          Sign now as lessor (landowner)
        </label>
        <label style={{ display: 'flex', alignItems: 'center', gap: '9px', cursor: 'pointer', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
          <input type="checkbox" checked={data.notify_applicant} onChange={e => setData('notify_applicant', e.target.checked)} />
          Send the signing link to the applicant
        </label>
      </form>
    </Modal>
  )
}

function RejectModal({ base, onClose }: { base: string; onClose: () => void }) {
  const { data, setData, post, processing, errors } = useForm({
    rejection_reason: '',
    notify_applicant: true,
  })

  function submit(e: React.FormEvent) {
    e.preventDefault()
    post(`${base}/reject`, { preserveScroll: true, onSuccess: onClose })
  }

  return (
    <Modal title="Reject Application" onClose={onClose} footer={
      <>
        <button form="reject-form" type="submit" disabled={processing} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 22px', background: '#b91c1c', color: '#fff', border: 'none', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.6 : 1 }}>
          {processing ? 'Rejecting…' : 'Reject'}
        </button>
        <button type="button" onClick={onClose} style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '10px 22px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', cursor: 'pointer' }}>Cancel</button>
      </>
    }>
      <form id="reject-form" onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        <div>
          <label htmlFor="rejection_reason" style={labelStyle}>Reason for Rejection</label>
          <textarea id="rejection_reason" rows={4} value={data.rejection_reason} onChange={e => setData('rejection_reason', e.target.value)} style={{ ...inputStyle, resize: 'vertical', lineHeight: 1.5 }} placeholder="Shown to the applicant…" />
          {errors.rejection_reason && <div style={errStyle}>{errors.rejection_reason}</div>}
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: '9px', cursor: 'pointer', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
          <input type="checkbox" checked={data.notify_applicant} onChange={e => setData('notify_applicant', e.target.checked)} />
          Notify the applicant of the rejection
        </label>
      </form>
    </Modal>
  )
}
