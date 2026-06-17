import { Head, router } from '@inertiajs/react'
import { useState as useReactState, useRef as useReactRef } from 'react'

/**
 * PropertyChromeV2 — full-parity "Field Record" kit for the member-portal property
 * pages, rebuilt to match the admin design system 1:1 (see docs/admin_design_system.md).
 *
 * This is the redesign mock-up kit. It is API-compatible with PropertyChrome.tsx
 * (same export names + signatures) so the shared tabs can be re-pointed here, but
 * it carries the exact admin token values instead of the lighter member palette:
 *   - card paper #f4ecdc / page paper #e8dcc4 / input paper #faf7f2
 *   - hard 8px offset shadow (was 6px) + 8px dashed inset (was 6px)
 *   - tan input borders #c9b896, tan dashed/dividers #a89874
 *   - Instrument Mono chrome (admin's mono), injected via a --mono override on the
 *     PortalChrome root so every descendant (incl. re-pointed tabs) inherits it.
 *
 * Once the redesign is approved this file folds into PropertyChrome.tsx.
 */

// ── Design tokens — admin "Field Record" parity (docs/admin_design_system.md §2) ─
export const INK = '#0a1512'
export const ACCENT = '#c84c21'
export const PAPER = '#f4ecdc'        // card / section / modal background
export const PAGE = '#e8dcc4'         // main content area, topbar
export const INPUT_BG = '#faf7f2'     // input fields, dropzone
export const TAN = '#a89874'          // dashed inset borders, dividers, header underline
export const TAN_BORDER = '#c9b896'   // input / select borders
export const DIVIDER = '#a89874'      // section header underline (tan)
export const BRASS = '#b8934a'
export const CREAM = '#e8dcc4'        // button text on ink

// Field-record card shell — 1px ink border + hard 8px ink drop shadow (no blur).
export const fieldCard: React.CSSProperties = {
  position: 'relative',
  background: PAPER,
  border: `1px solid ${INK}`,
  boxShadow: `8px 8px 0 ${INK}`,
  marginBottom: '24px',
}

export function DashedInset() {
  return <div style={{ position: 'absolute', inset: 8, border: `1px dashed ${TAN}`, pointerEvents: 'none', zIndex: 1 }} />
}

export function Section({ title, description, action, children }: { title: string; description?: React.ReactNode; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div style={fieldCard}>
      <DashedInset />
      <div style={{ position: 'relative', zIndex: 2, padding: '20px 24px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '16px', marginBottom: '18px', borderBottom: `1px solid ${DIVIDER}`, paddingBottom: description ? '14px' : '10px' }}>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontFamily: 'var(--mono)', fontSize: '13px', fontWeight: 400, letterSpacing: '.15em', textTransform: 'uppercase', color: 'rgba(10,21,18,0.7)' }}>
              {title}
            </div>
            {description && (
              <div style={{ fontFamily: 'var(--mono)', fontSize: '10px', letterSpacing: '.08em', lineHeight: 1.6, color: 'rgba(10,21,18,0.45)', marginTop: '8px', maxWidth: '760px' }}>
                {description}
              </div>
            )}
          </div>
          {action}
        </div>
        {children}
      </div>
    </div>
  )
}

/** Tab bar — mono small-caps text tabs on a single tan rule: active is ink with a
 * 2px terracotta underline, the rest muted ink (mirrors admin `nav.fi-tabs`). */
export function TabBar({ tabs, active, onChange }: {
  tabs: { key: string; label: string }[]
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
              fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 500, letterSpacing: '.12em',
              textTransform: 'uppercase', padding: '10px 16px', cursor: 'pointer',
              background: 'none', border: 'none', whiteSpace: 'nowrap', marginBottom: '-1px',
              borderBottom: on ? `2px solid ${ACCENT}` : '2px solid transparent',
              color: on ? INK : 'rgba(10,21,18,0.45)',
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
            <div style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 400, letterSpacing: '.22em', textTransform: 'uppercase', color: '#6b9e8f', marginTop: '3px' }}>
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

/** Topbar + topographic background + 1160px content container. Injects the admin's
 * Instrument Mono as --mono for the whole subtree so re-pointed tabs inherit it. */
export function PortalChrome({ headTitle, children }: { headTitle: string; children: React.ReactNode }) {
  return (
    <>
      <Head title={headTitle} />
      <div
        className="topo-bg"
        style={{ minHeight: '100vh', backgroundColor: PAGE, ['--mono' as string]: "'Instrument Mono', monospace" } as React.CSSProperties}
      >
        <Topbar />
        <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '32px 24px 80px' }}>
          {children}
        </div>
      </div>
    </>
  )
}

// ── Shared media kit (Map + Photos tabs) ─────────────────────────────────────

export const SANS = 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'

/** Small mono field label used inside modals/forms (admin modal-label scale). */
export const fieldLabel: React.CSSProperties = {
  display: 'block', fontFamily: 'var(--mono)', fontSize: '10px', fontWeight: 400,
  letterSpacing: '.12em', textTransform: 'uppercase', color: 'rgba(10,21,18,0.5)', marginBottom: '5px',
}

/** Parchment square input — #faf7f2 on a 1px tan border, ink text, no focus ring. */
export const fieldInput: React.CSSProperties = {
  width: '100%', fontFamily: SANS, fontSize: '14px', color: INK,
  background: INPUT_BG, border: `1px solid ${TAN_BORDER}`, padding: '8px 10px', outline: 'none', boxSizing: 'border-box',
}

/** Squared #FAFAFA toolbar / card button — mirrors the admin map toolbar. */
export const toolbarBtn: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '6px 12px', borderRadius: 0,
  background: '#FAFAFA', border: '1px solid #e5e7eb', fontFamily: SANS, fontSize: '12px',
  fontWeight: 500, color: '#374151', cursor: 'pointer', whiteSpace: 'nowrap', textDecoration: 'none',
}
export const toolbarActiveBtn: React.CSSProperties = { ...toolbarBtn, borderColor: INK, color: INK, fontWeight: 600 }
export const toolbarInkBtn: React.CSSProperties = { ...toolbarBtn, background: INK, color: '#F4ECDC', borderColor: INK }
export const toolbarDangerBtn: React.CSSProperties = { ...toolbarBtn, color: '#b91c1c', borderColor: '#fca5a5' }

/** Filament `fi-btn` ghost: square, 36px, Instrument Mono, uppercase, parchment ghost. */
export const fiGhostBtn: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '0.5rem',
  height: '36px', padding: '0 0.875rem', borderRadius: 0, boxShadow: 'none',
  background: '#fafafa', color: 'rgba(10,21,18,0.65)', border: '1px solid rgba(10,21,18,0.2)',
  fontFamily: 'var(--mono), monospace', fontSize: '11px', letterSpacing: '0.12em', textTransform: 'uppercase',
  lineHeight: 1, whiteSpace: 'nowrap', cursor: 'pointer', flexShrink: 0,
}
/** Dark primary `fi-btn` (modal SUBMIT). */
export const fiPrimaryBtn: React.CSSProperties = { ...fiGhostBtn, background: INK, color: CREAM, border: 'none' }
/** Terracotta danger `fi-btn`. */
export const fiDangerBtn: React.CSSProperties = { ...fiGhostBtn, background: ACCENT, color: PAPER, border: 'none' }

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
        style={{ width: '100%', maxWidth: '480px', background: PAPER, border: `1px solid ${INK}`, boxShadow: `8px 8px 0 ${INK}` }}
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

/** Drag & drop / Browse file zone (matches the admin filepond dropzone). */
export function DropZone({ onFiles }: { onFiles: (files: FileList | null) => void }) {
  const [over, setOver] = useReactState(false)
  const ref = useReactRef<HTMLInputElement>(null)
  return (
    <>
      <div
        onClick={() => ref.current?.click()}
        onDragOver={e => { e.preventDefault(); setOver(true) }}
        onDragLeave={() => setOver(false)}
        onDrop={e => { e.preventDefault(); setOver(false); onFiles(e.dataTransfer.files) }}
        style={{ border: `1px dashed ${over ? INK : TAN}`, background: over ? 'rgba(10,21,18,0.03)' : INPUT_BG, padding: '28px 16px', textAlign: 'center', cursor: 'pointer' }}
      >
        <div style={{ fontFamily: SANS, fontSize: '13px', color: '#6b5e50' }}>
          Drag &amp; Drop your files or <span style={{ color: ACCENT, textDecoration: 'underline' }}>Browse</span>
        </div>
      </div>
      <input ref={ref} type="file" accept="image/*" multiple style={{ display: 'none' }} onChange={e => { onFiles(e.target.files); if (ref.current) ref.current.value = '' }} />
    </>
  )
}

/** Selected-file list with per-file remove (shown under a DropZone). */
export function SelectedFiles({ files, onRemove }: { files: File[]; onRemove: (i: number) => void }) {
  if (files.length === 0) return null
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', marginTop: '10px' }}>
      {files.map((f, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', fontFamily: 'var(--mono)', fontSize: '11px', color: INK }}>
          <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.name}</span>
          <button type="button" onClick={() => onRemove(i)} style={{ border: 'none', background: 'none', cursor: 'pointer', color: '#9ca3af', fontSize: '15px', lineHeight: 1, flexShrink: 0 }}>×</button>
        </div>
      ))}
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
    <div style={{ position: 'relative', background: INK, boxShadow: `8px 8px 0 ${BRASS}`, marginBottom: '24px' }}>
      <div style={{ position: 'absolute', inset: 8, border: `1px dashed ${TAN}`, pointerEvents: 'none' }} />
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
