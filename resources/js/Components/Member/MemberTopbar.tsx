import NotificationBell from '@/Components/Member/NotificationBell'

// The standard member-portal banner — ink field-record bar, brass underline,
// the AH mark with its top-left / bottom-right registration brackets, and the
// notification bell. Mirrors the topbar on the lease page so every member page
// carries one identical banner. The right-hand link is the page's back-nav.
const INK = '#0A1512'
const TAN = '#a89874'
const BRASS = '#b8934a'

interface Props {
  /** Right-hand back-nav link target. */
  rightHref?: string
  /** Right-hand back-nav label. */
  rightLabel?: string
  /** Inner container width — match the page content width so the mark aligns. */
  maxWidth?: number
}

export default function MemberTopbar({ rightHref = '/member', rightLabel = '← Dashboard', maxWidth = 640 }: Props) {
  return (
    <div style={{ background: INK, borderBottom: `1px solid ${BRASS}` }}>
      <div style={{ maxWidth: `${maxWidth}px`, margin: '0 auto', padding: '0 16px', height: '64px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
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
        <div style={{ display: 'flex', alignItems: 'center', gap: '20px' }}>
          <NotificationBell />
          <a href={rightHref} style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, textDecoration: 'none' }}>
            {rightLabel}
          </a>
        </div>
      </div>
    </div>
  )
}
