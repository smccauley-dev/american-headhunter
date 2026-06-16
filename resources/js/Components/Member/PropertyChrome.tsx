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

export function Section({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div style={fieldCard}>
      <DashedInset />
      <div style={{ position: 'relative', zIndex: 2, padding: '18px 24px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', marginBottom: '18px', borderBottom: `1px solid ${DIVIDER}`, paddingBottom: '8px' }}>
          <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: TAN }}>
            {title}
          </div>
          {action}
        </div>
        {children}
      </div>
    </div>
  )
}

/** Field-record tab bar — stamped boxes, active tab filled ink with a brass shadow. */
export function TabBar({ tabs, active, onChange }: {
  tabs: { key: string; label: string }[]
  active: string
  onChange: (key: string) => void
}) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', marginBottom: '24px' }}>
      {tabs.map(t => {
        const on = t.key === active
        return (
          <button
            key={t.key}
            type="button"
            onClick={() => onChange(t.key)}
            style={{
              fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 700, letterSpacing: '.12em',
              textTransform: 'uppercase', padding: '10px 16px', cursor: 'pointer',
              border: `1px solid ${INK}`, whiteSpace: 'nowrap',
              background: on ? INK : PAPER, color: on ? '#F4ECDC' : INK,
              boxShadow: on ? `3px 3px 0 ${BRASS}` : 'none',
            }}
          >
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
