import React, { useState } from 'react'
import { router, useForm, usePage } from '@inertiajs/react'
import { PortalChrome, TitleHead, BackLink, Section, fieldCard, DashedInset, INK, ACCENT, TAN, DIVIDER, BRASS, PAPER } from '@/Components/Member/PropertyChrome'

interface Signer {
  name: string
  role: string
  status: string
  signed_at: string | null
}

interface Deposit {
  amount: string
  held: boolean
  pay_url: string
}

interface LeaseProps {
  id: string
  status: string
  start_date: string
  end_date: string
  total_price: string
  property: {
    title: string
    county: string
    state: string
    acres: string | number
  } | null
}

interface Props {
  lease: LeaseProps
  request_id?: string
  signers: Signer[]
  already_signed: boolean
  deposit: Deposit | null
}

// Brand palette extensions (see docs/design_system.md): sage for cleared/signed,
// rust for errors. Everything else comes from the shared member-portal tokens.
const SAGE = '#3d6b54'
const RUST = '#8a3216'
const SERIF = 'Crimson Pro, Georgia, serif'
const mono = 'var(--mono)'

export default function Sign({ lease, request_id, signers, already_signed, deposit }: Props) {
  const { props } = usePage<{ flash?: { success?: string; info?: string; error?: string } }>()
  const flash = props.flash ?? {}

  const { data, setData, post, processing, errors } = useForm({
    request_id: request_id ?? '',
    full_name: '',
    agreed: false as boolean,
  })

  const [payingDeposit, setPayingDeposit] = useState(false)

  const allSigned = signers.every((s) => s.status === 'signed')
  // Pay-then-sign: a due-but-unpaid refundable deposit locks the signature form.
  const depositPending = !!deposit && !deposit.held

  function payDeposit() {
    if (!deposit) return
    setPayingDeposit(true)
    router.post(deposit.pay_url, { return: 'sign' }, { onFinish: () => setPayingDeposit(false) })
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    post(`/member/leases/${lease.id}/sign`)
  }

  const locationLine = lease.property
    ? [lease.property.county ? `${lease.property.county} County` : null, lease.property.state,
       lease.property.acres ? `${Number(lease.property.acres).toLocaleString()} acres` : null]
      .filter(Boolean).join(' · ')
    : null

  return (
    <PortalChrome headTitle="Sign Lease Agreement">
      <div style={{ maxWidth: '680px', margin: '0 auto' }}>

        <BackLink href={`/member/leases/${lease.id}`}>← Back to Lease</BackLink>

        <TitleHead
          kicker="Electronic Signature"
          title="Sign Your Lease"
          subtitle="Review the terms below and sign to confirm your hunting lease."
        />

        {/* Flash messages — on-brand sage / brass / rust */}
        {flash.success && <FlashBar color={SAGE}>{flash.success}</FlashBar>}
        {flash.info && <FlashBar color={BRASS}>{flash.info}</FlashBar>}
        {flash.error && <FlashBar color={RUST}>{flash.error}</FlashBar>}

        {/* Lease Record — Field Record card */}
        <div style={fieldCard}>
          <DashedInset />
          <div style={{ position: 'relative', zIndex: 2 }}>
            <div style={{ padding: '18px 24px', borderBottom: `1px solid ${DIVIDER}` }}>
              <div style={{ fontFamily: mono, fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: ACCENT, marginBottom: '7px' }}>
                Lease Record
              </div>
              <div style={{ fontFamily: 'var(--display)', fontSize: '22px', fontWeight: 400, color: INK, lineHeight: 1.15 }}>
                {lease.property?.title ?? 'Hunting Property'}
              </div>
              {locationLine && (
                <div style={{ fontFamily: mono, fontSize: '10px', letterSpacing: '.08em', color: TAN, marginTop: '6px' }}>
                  {locationLine}
                </div>
              )}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr' }}>
              <RecordCell label="Start Date" value={lease.start_date} border />
              <RecordCell label="End Date" value={lease.end_date} border />
              <RecordCell label="Total Price" value={`$${lease.total_price}`} />
            </div>
          </div>
        </div>

        {/* Signatures Required */}
        <Section title="Signatures Required">
          {signers.map((signer, i) => {
            const isSigned = signer.status === 'signed'
            const color = isSigned ? SAGE : BRASS
            return (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '14px', padding: '11px 0', borderBottom: i < signers.length - 1 ? `1px solid ${DIVIDER}` : 'none' }}>
                <span style={{ width: '24px', height: '24px', border: `1.5px solid ${color}`, color, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  {isSigned && <CheckMark />}
                </span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontFamily: SERIF, fontSize: '16px', color: INK }}>{signer.name}</div>
                  <div style={{ fontFamily: mono, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, marginTop: '2px' }}>{signer.role}</div>
                </div>
                <div style={{ fontFamily: mono, fontSize: '10px', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.1em', color }}>
                  {isSigned ? 'Signed' : 'Pending'}
                </div>
              </div>
            )
          })}
        </Section>

        {/* Deposit — pay-then-sign gate. The refundable security deposit, when due,
            must be paid before the signature form unlocks. */}
        {!already_signed && deposit && (
          depositPending ? (
            <DepositStep
              eyebrow="Security Deposit — Refundable"
              headline="Pay your refundable deposit to unlock signing"
              lineItem="Refundable security deposit"
              amount={deposit.amount}
              payLabel={`Pay $${deposit.amount} Deposit`}
              note="Held securely and refundable at the end of your lease. You'll return here to sign once it's received."
              paying={payingDeposit}
              onPay={payDeposit}
            />
          ) : (
            <DepositCleared text={`Security deposit of $${deposit.amount} held — you're clear to sign.`} />
          )
        )}

        {/* Already signed state */}
        {already_signed && (
          <div style={{ ...fieldCard, boxShadow: `6px 6px 0 ${SAGE}` }}>
            <DashedInset />
            <div style={{ position: 'relative', zIndex: 2, padding: '24px', textAlign: 'center' }}>
              <span style={{ width: '34px', height: '34px', border: `1.5px solid ${SAGE}`, color: SAGE, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', marginBottom: '12px' }}>
                <CheckMark size={18} />
              </span>
              <div style={{ fontFamily: 'var(--display)', fontSize: '19px', color: INK, marginBottom: '5px' }}>You have already signed this lease.</div>
              <div style={{ fontFamily: SERIF, fontSize: '15px', color: SAGE }}>
                {allSigned ? 'All parties have signed — your lease is now active.' : 'Waiting for the landowner to countersign.'}
              </div>
            </div>
          </div>
        )}

        {/* Signature form — only shown when not yet signed and the deposit is paid */}
        {!already_signed && !depositPending && (
          <form onSubmit={handleSubmit}>
            <Section title="Your Signature">
              <div style={{ marginBottom: '18px' }}>
                <label style={{ display: 'block', fontFamily: mono, fontSize: '10px', fontWeight: 600, letterSpacing: '.12em', textTransform: 'uppercase', color: TAN, marginBottom: '8px' }}>
                  Type your full legal name to sign
                </label>
                <input
                  type="text"
                  value={data.full_name}
                  onChange={(e) => setData('full_name', e.target.value)}
                  placeholder="Your full legal name"
                  style={{
                    width: '100%',
                    padding: '11px 14px',
                    border: `1px solid ${errors.full_name ? RUST : TAN}`,
                    fontFamily: 'var(--display)',
                    fontSize: '20px',
                    fontStyle: 'italic',
                    fontWeight: 400,
                    color: INK,
                    background: '#fff',
                    boxSizing: 'border-box',
                    outline: 'none',
                  }}
                />
                {errors.full_name && (
                  <div style={{ fontFamily: mono, fontSize: '10px', color: RUST, marginTop: '5px' }}>{errors.full_name}</div>
                )}
              </div>

              <label htmlFor="agreed" style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', padding: '15px', background: PAPER, border: `1px solid ${DIVIDER}`, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  id="agreed"
                  checked={data.agreed}
                  onChange={(e) => setData('agreed', e.target.checked)}
                  style={{ marginTop: '2px', width: '16px', height: '16px', flexShrink: 0, accentColor: ACCENT }}
                />
                <span style={{ fontFamily: SERIF, fontSize: '15px', color: INK, lineHeight: 1.5 }}>
                  I, <strong style={{ fontWeight: 600 }}>{data.full_name || '[your name]'}</strong>, agree to the terms of this hunting lease agreement
                  for the period {lease.start_date} through {lease.end_date}, for the total amount of ${lease.total_price}.
                  I understand this constitutes a legally binding electronic signature under the ESIGN Act.
                </span>
              </label>
              {errors.agreed && (
                <div style={{ fontFamily: mono, fontSize: '10px', color: RUST, marginTop: '6px' }}>{errors.agreed}</div>
              )}
            </Section>

            <BrandButton type="submit" disabled={processing || !data.full_name.trim() || !data.agreed}>
              {processing ? 'Signing…' : 'Sign Lease Agreement'}
            </BrandButton>

            <p style={{ fontFamily: mono, fontSize: '10px', letterSpacing: '.04em', color: TAN, textAlign: 'center', marginTop: '14px', lineHeight: 1.7 }}>
              Your signature is recorded with your account ID, timestamp, and IP address.
              This constitutes a legally binding agreement under the U.S. ESIGN Act (15 U.S.C. § 7001).
            </p>
          </form>
        )}

      </div>
    </PortalChrome>
  )
}

/** One labelled cell in the lease-record data grid. */
function RecordCell({ label, value, border }: { label: string; value: string; border?: boolean }) {
  return (
    <div style={{ padding: '15px 22px', borderRight: border ? `1px solid ${DIVIDER}` : 'none' }}>
      <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.16em', textTransform: 'uppercase', color: TAN, marginBottom: '6px' }}>{label}</div>
      <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '17px', fontWeight: 600, color: INK }}>{value}</div>
    </div>
  )
}

/** Sharp single-stroke check, inherits currentColor (no emoji — see design system). */
function CheckMark({ size = 13 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="square">
      <path d="M5 12l5 5L20 6" />
    </svg>
  )
}

/** Thin on-brand flash bar — colour carries the meaning. */
function FlashBar({ color, children }: { color: string; children: React.ReactNode }) {
  return (
    <div style={{ borderLeft: `3px solid ${color}`, border: `1px solid ${color}`, background: '#fff', padding: '12px 16px', marginBottom: '20px', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
      {children}
    </div>
  )
}

/** Primary action — ink field-button that warms to blaze on hover (design system). */
function BrandButton({ children, disabled, type = 'button', onClick }: {
  children: React.ReactNode
  disabled?: boolean
  type?: 'button' | 'submit'
  onClick?: () => void
}) {
  const [hover, setHover] = useState(false)
  return (
    <button
      type={type}
      disabled={disabled}
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        width: '100%',
        padding: '15px',
        background: disabled ? '#c9b896' : (hover ? ACCENT : INK),
        color: disabled ? '#f4ecdc' : '#F4ECDC',
        border: 'none',
        fontFamily: 'var(--mono)',
        fontSize: '11px',
        fontWeight: 600,
        letterSpacing: '.15em',
        textTransform: 'uppercase',
        cursor: disabled ? 'not-allowed' : 'pointer',
        transition: 'background 0.2s',
      }}
    >
      {children}
    </button>
  )
}

/** Brass "pay this deposit to unlock signing" Field Record card. */
function DepositStep({ eyebrow, headline, lineItem, amount, payLabel, note, paying, onPay }: {
  eyebrow: string
  headline: string
  lineItem: string
  amount: string
  payLabel: string
  note: string
  paying: boolean
  onPay: () => void
}) {
  return (
    <div style={{ ...fieldCard, boxShadow: `6px 6px 0 ${BRASS}` }}>
      <DashedInset />
      <div style={{ position: 'relative', zIndex: 2 }}>
        <div style={{ padding: '16px 24px', borderBottom: `1px solid ${DIVIDER}` }}>
          <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.16em', textTransform: 'uppercase', color: '#7a6028', marginBottom: '6px' }}>{eyebrow}</div>
          <div style={{ fontFamily: 'var(--display)', fontSize: '18px', fontWeight: 400, color: INK }}>{headline}</div>
        </div>
        <div style={{ padding: '20px 24px' }}>
          <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: '16px' }}>
            <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50' }}>{lineItem}</span>
            <span style={{ fontFamily: 'var(--display)', fontSize: '24px', fontWeight: 500, color: INK }}>${amount}</span>
          </div>
          <BrandButton onClick={onPay} disabled={paying}>
            {paying ? 'Redirecting…' : payLabel}
          </BrandButton>
          <p style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.04em', color: TAN, textAlign: 'center', marginTop: '12px', lineHeight: 1.7 }}>
            {note}
          </p>
        </div>
      </div>
    </div>
  )
}

/** Sage "this deposit is settled" confirmation chip. */
function DepositCleared({ text }: { text: string }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '11px', background: '#fff', border: `1px solid ${SAGE}`, borderLeft: `3px solid ${SAGE}`, padding: '12px 16px', marginBottom: '20px' }}>
      <span style={{ color: SAGE, display: 'inline-flex', flexShrink: 0 }}><CheckMark /></span>
      <span style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>{text}</span>
    </div>
  )
}
