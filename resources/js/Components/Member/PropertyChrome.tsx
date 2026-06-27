import { Head, router } from '@inertiajs/react'
import { useState as useReactState } from 'react'

/**
 * Shared chrome for the member-portal property management pages (Form, Listings,
 * Details, Team). Mirrors the Member Portal profile banner + the lease page's
 * parchment field-record system so every property sub-page reads the same.
 */

// ── Design tokens — parchment field-record system (see docs/design_system.md) ─
export const INK = '#0A1512'
export const ACCENT = '#C84C21'
export const PAPER = '#F8F4EB'
export const TAN = '#a89874'
export const DIVIDER = '#e5ddd0'
export const BRASS = '#b8934a'

// Field-record card shell — 1px ink border + solid ink drop shadow.
export const fieldCard: React.CSSProperties = {
  position: 'relative',
  background: PAPER,
  border: `1px solid ${INK}`,
  boxShadow: `6px 6px 0 ${INK}`,
  marginBottom: '24px',
}

export function DashedInset() {
  return <div style={{ position: 'absolute', inset: 6, border: `1px dashed ${TAN}`, pointerEvents: 'none', zIndex: 1 }} />
}

export function Section({ icon, title, description, action, children }: { icon?: React.ReactNode; title: string; description?: React.ReactNode; action?: React.ReactNode; children: React.ReactNode }) {
  // With a lead icon, mirror the admin Filament section header verbatim: an
  // oversized rust glyph vertically centered against a mono 13px ink-70% title
  // and a mono 9px ink description (see AdminPanelProvider .ah-section-lead-icon).
  const lead = !!icon
  return (
    <div style={fieldCard}>
      <DashedInset />
      <div style={{ position: 'relative', zIndex: 2, padding: '18px 24px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '16px', marginBottom: '18px', borderBottom: `1px solid ${DIVIDER}`, paddingBottom: description ? '14px' : '8px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: lead ? '0.85rem' : '11px', minWidth: 0 }}>
            {icon && (
              <span style={{ color: ACCENT, flexShrink: 0, display: 'inline-flex', width: '2.25rem', height: '2.25rem' }}>{icon}</span>
            )}
            <div style={{ minWidth: 0 }}>
              <div style={lead
                ? { fontFamily: 'var(--mono)', fontSize: '13px', fontWeight: 400, letterSpacing: '.15em', textTransform: 'uppercase', color: 'rgba(10, 21, 18, 0.7)' }
                : { fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: description ? '#3d6b54' : TAN }}>
                {title}
              </div>
              {description && (
                <div style={lead
                  ? { fontFamily: 'var(--mono)', fontSize: '9px', lineHeight: 1.5, letterSpacing: '.08em', color: INK, marginTop: '6px', maxWidth: '760px' }
                  : { fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', lineHeight: 1.45, color: '#3d6b54', marginTop: '7px', maxWidth: '760px' }}>
                  {description}
                </div>
              )}
            </div>
          </div>
          {action}
        </div>
        {children}
      </div>
    </div>
  )
}

/** Tab bar — underlined text tabs (mirrors the admin Filament tab row): the
 * active tab is ink with an accent underline, the rest muted, all sitting on a
 * single divider line. */
export function TabBar({ tabs, active, onChange }: {
  tabs: { key: string; label: string; icon?: React.ReactNode }[]
  active: string
  onChange: (key: string) => void
}) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0', marginBottom: '24px', borderBottom: `1px solid ${TAN}` }}>
      {tabs.map(t => {
        const on = t.key === active
        return (
          <button
            key={t.key}
            type="button"
            onClick={() => onChange(t.key)}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: '7px',
              fontFamily: 'var(--mono)', fontSize: '11px', fontWeight: 600, letterSpacing: '.12em',
              textTransform: 'uppercase', padding: '10px 16px', cursor: 'pointer',
              background: 'none', border: 'none', whiteSpace: 'nowrap', marginBottom: '-1px',
              borderBottom: on ? `2px solid ${ACCENT}` : '2px solid transparent',
              color: on ? INK : TAN,
            }}
          >
            {t.icon && (
              <span style={{ width: '14px', height: '14px', flexShrink: 0, display: 'inline-flex', color: on ? ACCENT : TAN }}>{t.icon}</span>
            )}
            {t.label}
          </button>
        )
      })}
    </div>
  )
}

/** Read/write the active tab in the URL query string (?tab=), like the admin editor. */
export function useTabQuery(fallback: string): [string, (key: string) => void] {
  const initial = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('tab') || fallback
    : fallback
  const [tab, setTab] = useReactState(initial)

  const change = (key: string) => {
    setTab(key)
    if (typeof window !== 'undefined') {
      const url = new URL(window.location.href)
      url.searchParams.set('tab', key)
      window.history.replaceState({}, '', url)
    }
  }

  return [tab, change]
}

function Topbar() {
  return (
    <div style={{ background: INK, borderBottom: `1px solid ${BRASS}` }}>
      <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '0 24px', height: '64px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>

        {/* Logo block */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
          <div style={{ position: 'relative', width: '42px', height: '42px', flexShrink: 0, margin: '5px' }}>
            {/* Registration mark corners */}
            <div style={{ position: 'absolute', top: -5, left: -5, width: 9, height: 9, borderTop: `1.5px solid ${TAN}`, borderLeft: `1.5px solid ${TAN}` }} />
            <div style={{ position: 'absolute', bottom: -5, right: -5, width: 9, height: 9, borderBottom: `1.5px solid ${TAN}`, borderRight: `1.5px solid ${TAN}` }} />
            <div style={{ width: '42px', height: '42px', border: `1px solid ${TAN}`, display: 'flex', alignItems: 'center', justifyContent: 'center', background: INK }}>
              <span style={{ fontFamily: 'var(--display)', fontSize: '15px', fontWeight: 500, color: '#F4ECDC', letterSpacing: '.05em' }}>
                AH
              </span>
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
          onClick={() => router.post('/logout')}
          style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, background: 'none', border: 'none', cursor: 'pointer' }}
        >
          Sign Out
        </button>
      </div>
    </div>
  )
}

/** Topbar + topographic background + 1160px content container. */
export function PortalChrome({ headTitle, children }: { headTitle: string; children: React.ReactNode }) {
  return (
    <>
      <Head title={headTitle} />
      <div className="topo-bg" style={{ minHeight: '100vh', backgroundColor: '#EDE5D0' }}>
        <Topbar />
        <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '32px 24px 80px' }}>
          {children}
        </div>
      </div>
    </>
  )
}

// ── Shared media kit (Map + Photos tabs) ─────────────────────────────────────
// One source of truth for the chrome the property-media tabs share, so the Map
// and Photos tabs stay pixel-identical: the squared #FAFAFA toolbar buttons, the
// Filament-style `fi-btn` header action, the parchment modal shell with a serif
// heading + tan rules + hard offset shadow, and the modal field/helper styles.

export const SANS = 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'

/** Small mono field label used inside modals/forms. */
export const fieldLabel: React.CSSProperties = {
  display: 'block', fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600,
  letterSpacing: '.12em', textTransform: 'uppercase', color: TAN, marginBottom: '5px',
}

export const fieldInput: React.CSSProperties = {
  width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: INK,
  background: '#fff', border: '1px solid #d4c9b0', padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
}

/** Squared #FAFAFA toolbar / card button — mirrors the admin map toolbar. */
export const toolbarBtn: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '6px 12px', borderRadius: 0,
  background: '#FAFAFA', border: '1px solid #e5e7eb', fontFamily: SANS, fontSize: '12px',
  fontWeight: 500, color: '#374151', cursor: 'pointer', whiteSpace: 'nowrap', textDecoration: 'none',
}
export const toolbarActiveBtn: React.CSSProperties = { ...toolbarBtn, borderColor: '#0a1512', color: '#0a1512', fontWeight: 600 }
export const toolbarInkBtn: React.CSSProperties = { ...toolbarBtn, background: INK, color: '#F4ECDC', borderColor: INK }
export const toolbarDangerBtn: React.CSSProperties = { ...toolbarBtn, color: '#b91c1c', borderColor: '#fca5a5' }

/** Filament `fi-btn` header action: square corners, 36px, mono, uppercase, ghost. */
export const fiGhostBtn: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem',
  height: '36px', padding: '0 0.875rem', borderRadius: 0, boxShadow: 'none',
  background: '#fafafa', color: 'rgba(10,21,18,0.65)', border: '1px solid rgba(10,21,18,0.2)',
  fontFamily: 'var(--mono), monospace', fontSize: '11px', letterSpacing: '0.12em', textTransform: 'uppercase',
  lineHeight: 1, whiteSpace: 'nowrap', cursor: 'pointer', flexShrink: 0,
}
/** Dark primary `fi-btn` (modal SUBMIT). */
export const fiPrimaryBtn: React.CSSProperties = { ...fiGhostBtn, background: INK, color: '#e8dcc4', border: 'none' }

/** Modal helper text — Crimson Pro serif, muted (matches the admin form copy). */
export const modalHelper: React.CSSProperties = { fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', lineHeight: 1.45, color: '#6b5e50', marginTop: '6px' }

export function UploadIcon() {
  return (
    <svg aria-hidden width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
    </svg>
  )
}
export function CheckIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
  )
}
export function XIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M6 18 18 6M6 6l12 12" /></svg>
  )
}

/* ── Section lead icons (heroicons outline) — mirror the admin Filament section
 * header glyphs; sized + tinted rust by <Section icon={…}> (fills its box). ─── */
export function InfoCircleIcon() {
  return (
    <svg aria-hidden width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" style={{ display: 'block' }}><path d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
  )
}
export function DocumentCheckIcon() {
  return (
    <svg aria-hidden width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" style={{ display: 'block' }}><path d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 15.75l1.5 1.5 3-3.75" /></svg>
  )
}
export function SquaresIcon() {
  return (
    <svg aria-hidden width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" style={{ display: 'block' }}><path d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" /></svg>
  )
}

/* Helper — a section-lead glyph wrapper so every icon renders identically. */
function leadGlyph(body: React.ReactNode) {
  return (
    <svg aria-hidden width="100%" height="100%" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" style={{ display: 'block' }}>{body}</svg>
  )
}
export function TagIcon() {
  return leadGlyph(<>
    <path d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
    <path d="M6 6h.008v.008H6V6Z" />
  </>)
}
export function TrophyIcon() {
  return leadGlyph(<path d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" />)
}
export function ClipboardListIcon() {
  return leadGlyph(<path d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />)
}
export function SparklesIcon() {
  return leadGlyph(<path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />)
}
export function PhotoIcon() {
  return leadGlyph(<path d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />)
}
export function MapIcon() {
  return leadGlyph(<path d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />)
}
export function MapPinIcon() {
  return leadGlyph(<path d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />)
}
export function UserGroupIcon() {
  return leadGlyph(<path d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />)
}
export function UsersIcon() {
  return leadGlyph(<path d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />)
}
export function IdentificationIcon() {
  return leadGlyph(<path d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" />)
}
export function InboxStackIcon() {
  return leadGlyph(<path d="m7.875 14.25 1.214 1.942a2.25 2.25 0 0 0 1.908 1.058h2.006c.776 0 1.497-.4 1.908-1.058l1.214-1.942M2.41 9h4.636a2.25 2.25 0 0 1 1.872 1.002l.164.246a2.25 2.25 0 0 0 1.872 1.002h2.092a2.25 2.25 0 0 0 1.872-1.002l.164-.246A2.25 2.25 0 0 1 16.954 9h4.636M2.41 9a2.25 2.25 0 0 0-.16.832V12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 12V9.832c0-.287-.055-.57-.16-.832M2.41 9a2.25 2.25 0 0 1 .382-.632l3.285-3.832a2.25 2.25 0 0 1 1.708-.786h8.43c.657 0 1.281.287 1.709.786l3.284 3.832c.163.19.291.404.382.632M4.5 20.25h15A2.25 2.25 0 0 0 21.75 18v-2.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125V18a2.25 2.25 0 0 0 2.25 2.25Z" />)
}
export function ScaleIcon() {
  return leadGlyph(<path d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z" />)
}
export function DocumentTextIcon() {
  return leadGlyph(<path d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />)
}
export function UserIcon() {
  return leadGlyph(<path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />)
}
export function PencilSquareIcon() {
  return leadGlyph(<path d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />)
}
export function PaperClipIcon() {
  return leadGlyph(<path d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />)
}
export function ChatEllipsisIcon() {
  return leadGlyph(<path d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />)
}
export function ChatLeftRightIcon() {
  return leadGlyph(<path d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />)
}
export function ClockIcon() {
  return leadGlyph(<path d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />)
}
export function CalendarDaysIcon() {
  return leadGlyph(<path d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" />)
}
export function NoSymbolIcon() {
  return leadGlyph(<path d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" />)
}

/**
 * Parchment modal shell — mirrors the admin Filament modal: #f4ecdc window with a
 * 1px ink border + hard 8px offset shadow, a Fraunces serif heading (title case),
 * a tan header rule, and an optional footer (tan top rule) for the action buttons.
 */
export function Modal({ title, onClose, children, footer }: { title: string; onClose: () => void; children: React.ReactNode; footer?: React.ReactNode }) {
  return (
    <div
      onClick={onClose}
      style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'rgba(10,21,18,0.55)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '60px 16px', overflowY: 'auto' }}
    >
      <div
        onClick={e => e.stopPropagation()}
        style={{ width: '100%', maxWidth: '480px', background: '#f4ecdc', border: `1px solid ${INK}`, boxShadow: `8px 8px 0 ${INK}` }}
      >
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 18px', borderBottom: `1px solid ${TAN}` }}>
          <div style={{ fontFamily: 'var(--display), Georgia, serif', fontSize: '18px', fontWeight: 500, color: INK }}>{title}</div>
          <button type="button" onClick={onClose} style={{ border: 'none', background: 'transparent', cursor: 'pointer', fontSize: '20px', lineHeight: 1, color: 'rgba(10,21,18,0.4)' }}>×</button>
        </div>
        <div style={{ padding: '18px' }}>{children}</div>
        {footer && (
          <div style={{ padding: '12px 18px', borderTop: `1px solid ${TAN}`, display: 'flex', gap: '10px' }}>{footer}</div>
        )}
      </div>
    </div>
  )
}

/** EXIF-style pill toggle (sage when on) used in the media upload modals. */
export function PillToggle({ on, onChange, label, disabled }: { on: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', opacity: disabled ? 0.55 : 1 }}>
      <button
        type="button" role="switch" aria-checked={on} disabled={disabled} onClick={() => { if (!disabled) onChange(!on) }}
        style={{ position: 'relative', width: '40px', height: '22px', borderRadius: '999px', border: 'none', cursor: disabled ? 'not-allowed' : 'pointer', flexShrink: 0, background: on ? '#6b7856' : '#c9bfa9', transition: 'background .15s' }}
      >
        <span style={{ position: 'absolute', top: '2px', left: on ? '20px' : '2px', width: '18px', height: '18px', borderRadius: '50%', background: '#fff', transition: 'left .15s', boxShadow: '0 1px 2px rgba(0,0,0,0.2)' }} />
      </button>
      <span style={{ ...fieldLabel, marginBottom: 0 }}>{label}</span>
    </div>
  )
}

export function BackLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <a href={href} style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: TAN, textDecoration: 'none', display: 'inline-block', marginBottom: '18px' }}>
      {children}
    </a>
  )
}

// ── Unified property header ──────────────────────────────────────────────────
// Every property sub-page (Form/Listings/Details/Team) renders the SAME header
// plate so they read identically. Status label + colour are resolved here so no
// page has to pass a statuses map.

export interface PropertySummary {
  title: string
  status: string
  state_code: string | null
  county: string | null
  total_acres: number | null
}

const STATUS_LABEL: Record<string, string> = {
  draft: 'Draft', active: 'Active', suspended: 'Suspended', archived: 'Archived',
  pending: 'Pending', inactive: 'Inactive',
}

const STATUS_COLOR: Record<string, string> = {
  active: '#4a7c59', draft: BRASS, pending: BRASS, suspended: ACCENT, archived: TAN, inactive: TAN,
}

export function PropertyHead({ property }: { property: PropertySummary }) {
  const locationLine = [property.county ? `${property.county} County` : null, property.state_code]
    .filter(Boolean).join(', ')
  const acres = property.total_acres != null ? `${Number(property.total_acres).toLocaleString()} acres` : null
  const subtitle = [locationLine || null, acres].filter(Boolean).join(' · ') || undefined

  return (
    <TitleHead
      kicker="Property"
      title={property.title}
      subtitle={subtitle}
      badge={{ label: STATUS_LABEL[property.status] ?? property.status, color: STATUS_COLOR[property.status] ?? TAN }}
    />
  )
}

/** Dark field-record header plate — kicker + title + optional subtitle/badge. */
export function TitleHead({ kicker, title, subtitle, badge }: {
  kicker: string
  title: string
  subtitle?: React.ReactNode
  badge?: { label: string; color: string }
}) {
  return (
    <div style={{ position: 'relative', background: INK, boxShadow: `6px 6px 0 ${BRASS}`, marginBottom: '24px' }}>
      <div style={{ position: 'absolute', inset: 6, border: `1px dashed ${TAN}`, pointerEvents: 'none' }} />
      <div style={{ position: 'relative', padding: '28px 28px' }}>
        <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', letterSpacing: '.2em', textTransform: 'uppercase', color: ACCENT, marginBottom: '8px' }}>
          {kicker}
        </div>
        <h1 style={{ fontFamily: 'var(--display)', fontSize: '28px', fontWeight: 400, color: '#F4ECDC', margin: '0 0 6px' }}>
          {title}
        </h1>
        {subtitle && (
          <div style={{ fontFamily: 'var(--body)', fontSize: '15px', color: TAN, marginBottom: badge ? '14px' : 0 }}>
            {subtitle}
          </div>
        )}
        {badge && (
          <span style={{
            display: 'inline-block', padding: '4px 12px', border: `1px solid ${badge.color}`,
            fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em',
            textTransform: 'uppercase', color: badge.color,
          }}>
            {badge.label}
          </span>
        )}
      </div>
    </div>
  )
}
