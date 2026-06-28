import { INK, ACCENT, TAN, DIVIDER } from '@/Components/Member/PropertyChrome'

// Read-only payment picture for the landowner: what the hunter has paid (booking
// deposit + lease rent), the landowner's net after the platform fee/surcharge, the
// outstanding balance and where the refundable security deposit stands. Assembled
// server-side by LeaseFinanceSummaryService and surfaced on both the application
// detail page (`payment_summary`) and the lease detail page (`landowner_finance`).
// All money arrives as formatted dollar strings without a symbol.
//
// Renders the body only — no Section wrapper — so each page supplies its own
// parchment Section (the lease page's is title-only; the application page's takes an
// icon + description), keeping the surrounding chrome consistent on each.

export interface LandownerFinanceData {
  lease_total: string
  paid_to_date: string
  outstanding: string
  fully_paid: boolean
  net_received: string
  booking_deposit: { status: string | null; paid: boolean; amount: string; net: string | null; collected_at: string | null } | null
  security_deposit: { status: string | null; amount: string; refunded: string; forfeited: string } | null
  payments: { amount: string; fee: string; net: string; status: string; paid_at: string | null }[]
}

const mono: React.CSSProperties = { fontFamily: 'JetBrains Mono, monospace' }
const serif: React.CSSProperties = { fontFamily: 'Crimson Pro, Georgia, serif' }

const usd = (v: string) => '$' + v

const STATUS_COLOR: Record<string, string> = {
  collected: '#15803d', disbursed: '#15803d', held: '#3d6b8e',
  released: '#15803d', refunded: '#3d6b8e', partially_refunded: '#b05a00',
  forfeited: '#b91c1c', pending: '#b05a00',
}

function Pill({ status }: { status: string | null }) {
  if (!status) return null
  const color = STATUS_COLOR[status] ?? TAN
  return (
    <span style={{ ...mono, fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', border: `1px solid ${color}`, color }}>
      {status.replace(/_/g, ' ')}
    </span>
  )
}

// Headline stat tile — uppercase mono label over a large serif figure.
function Tile({ label, value, accent }: { label: string; value: string; accent?: string }) {
  return (
    <div style={{ padding: '14px 16px', background: '#fff', border: `1px solid ${DIVIDER}` }}>
      <div style={{ ...mono, fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: TAN, marginBottom: '7px' }}>{label}</div>
      <div style={{ ...serif, fontSize: '22px', fontWeight: 600, color: accent ?? INK }}>{value}</div>
    </div>
  )
}

function Line({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '12px', padding: '7px 0' }}>
      <span style={{ ...mono, fontSize: '10px', letterSpacing: '.08em', textTransform: 'uppercase', color: '#6b5e50' }}>{label}</span>
      <span style={{ ...serif, fontSize: '15px', color: INK }}>{children}</span>
    </div>
  )
}

export default function LandownerFinance({ data }: { data: LandownerFinanceData }) {
  return (
    <div>
      <p style={{ ...serif, fontSize: '14px', lineHeight: 1.45, color: '#3d6b54', margin: '0 0 16px', maxWidth: '760px' }}>
        What the hunter has paid and your net after the platform fee and processing surcharge. Lease income transfers to
        your connected payout account automatically; the security deposit is refundable collateral held separately.
      </p>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '14px', marginBottom: '20px' }}>
        <Tile label="Lease Total" value={usd(data.lease_total)} />
        <Tile label="Paid to Date" value={usd(data.paid_to_date)} />
        <Tile
          label={data.fully_paid ? 'Balance' : 'Outstanding'}
          value={data.fully_paid ? 'Paid in full' : usd(data.outstanding)}
          accent={data.fully_paid ? '#15803d' : ACCENT}
        />
        <Tile label="Net Received to You" value={usd(data.net_received)} accent="#15803d" />
      </div>

      {/* Booking deposit */}
      {data.booking_deposit && (
        <div style={{ background: '#fff', border: `1px solid ${DIVIDER}`, padding: '14px 18px', marginBottom: '14px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
            <span style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: INK }}>Booking Deposit</span>
            <Pill status={data.booking_deposit.status} />
          </div>
          <Line label="Amount Paid">{usd(data.booking_deposit.amount)}</Line>
          {data.booking_deposit.net !== null && <Line label="Net to You">{usd(data.booking_deposit.net)}</Line>}
          {data.booking_deposit.collected_at && <Line label="Collected">{data.booking_deposit.collected_at}</Line>}
          {!data.booking_deposit.paid && (
            <div style={{ ...mono, fontSize: '10px', color: '#b05a00', marginTop: '6px' }}>Not yet paid by the hunter.</div>
          )}
        </div>
      )}

      {/* Lease-rent payments */}
      {data.payments.length > 0 && (
        <div style={{ marginBottom: '14px' }}>
          <div style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: INK, marginBottom: '8px' }}>Lease Payments</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
            {data.payments.map((p, i) => (
              <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr auto auto auto', alignItems: 'center', gap: '14px', padding: '10px 16px', background: '#fff', border: `1px solid ${DIVIDER}` }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', minWidth: 0 }}>
                  <Pill status={p.status} />
                  <span style={{ ...mono, fontSize: '10px', color: '#6b5e50' }}>{p.paid_at ?? '—'}</span>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ ...mono, fontSize: '8px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN }}>Hunter Paid</div>
                  <div style={{ ...serif, fontSize: '15px', color: INK }}>{usd(p.amount)}</div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ ...mono, fontSize: '8px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN }}>Platform Fee</div>
                  <div style={{ ...serif, fontSize: '15px', color: '#6b5e50' }}>−{usd(p.fee)}</div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ ...mono, fontSize: '8px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN }}>Net to You</div>
                  <div style={{ ...serif, fontSize: '15px', fontWeight: 600, color: '#15803d' }}>{usd(p.net)}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Security deposit */}
      {data.security_deposit && (
        <div style={{ background: '#fff', border: `1px solid ${DIVIDER}`, padding: '14px 18px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
            <span style={{ ...mono, fontSize: '10px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: INK }}>Security Deposit</span>
            <Pill status={data.security_deposit.status} />
          </div>
          <Line label="Held">{usd(data.security_deposit.amount)}</Line>
          <Line label="Refunded">{usd(data.security_deposit.refunded)}</Line>
          <Line label="Forfeited">{usd(data.security_deposit.forfeited)}</Line>
          <div style={{ ...mono, fontSize: '10px', color: '#6b5e50', marginTop: '6px', borderTop: `1px solid ${DIVIDER}`, paddingTop: '8px' }}>
            Refundable collateral — not lease income.
          </div>
        </div>
      )}

      {!data.booking_deposit && data.payments.length === 0 && !data.security_deposit && (
        <p style={{ ...mono, fontSize: '11px', color: '#6b5e50' }}>No payments collected from the hunter yet.</p>
      )}
    </div>
  )
}
