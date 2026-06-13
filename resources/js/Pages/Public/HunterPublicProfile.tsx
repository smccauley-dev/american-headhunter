import { Head, router } from '@inertiajs/react'
import { LockClosedIcon } from '@heroicons/react/24/outline'

// ── Species labels (subset — display only) ────────────────────────────────────

const SPECIES_LABELS: Record<string, string> = {
  whitetail:  'Whitetail',
  mule_deer:  'Mule Deer',
  elk:        'Elk',
  turkey:     'Turkey',
  hog:        'Wild Hog',
  black_bear: 'Black Bear',
  waterfowl:  'Waterfowl',
  dove:       'Dove',
  quail:      'Quail',
  pheasant:   'Pheasant',
  small_game: 'Small Game',
  coyote:     'Predator',
}

// ── Types ─────────────────────────────────────────────────────────────────────

// Subset of the admin profile template (DB 12) used by the public page:
// theme colors + the registration-mark decoration. Modules/coffee-stain/topo
// don't apply to this minimal layout.
interface TemplateConfig {
  decorations: { registration_marks: { enabled: boolean } }
  theme: { accent: string; paper: string; ink: string }
}

interface Props {
  username: string
  is_public: boolean
  template: TemplateConfig
  display_name?: string
  initials?: string
  member_since?: string
  state_code?: string | null
  trust_score?: number
  is_veteran?: boolean
  veteran_branch?: string | null
  is_first_responder?: boolean
  bio?: string | null
  species?: string[]
}

// ── Shared topbar ─────────────────────────────────────────────────────────────

function Topbar({ showRegMarks }: { showRegMarks: boolean }) {
  return (
    <div style={{ background: 'var(--ah-ink)', borderBottom: '1px solid #b8934a' }}>
      <div style={{ maxWidth: '1160px', margin: '0 auto', padding: '0 24px', height: '64px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <a href="/" style={{ display: 'flex', alignItems: 'center', gap: '14px', textDecoration: 'none' }}>
          <div style={{ position: 'relative', width: '42px', height: '42px', flexShrink: 0, margin: '5px' }}>
            {showRegMarks && (
              <>
                <div style={{ position: 'absolute', top: -5, left: -5, width: 9, height: 9, borderTop: '1.5px solid #a89874', borderLeft: '1.5px solid #a89874' }} />
                <div style={{ position: 'absolute', bottom: -5, right: -5, width: 9, height: 9, borderBottom: '1.5px solid #a89874', borderRight: '1.5px solid #a89874' }} />
              </>
            )}
            <div style={{ width: '42px', height: '42px', border: '1px solid #a89874', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--ah-ink)' }}>
              <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '15px', fontWeight: 500, color: '#F4ECDC', letterSpacing: '.05em' }}>AH</span>
            </div>
          </div>
          <div>
            <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', fontWeight: 400, color: '#F4ECDC', letterSpacing: '.01em', lineHeight: 1.1 }}>
              American Headhunter
            </div>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.22em', textTransform: 'uppercase', color: '#6b9e8f', marginTop: '3px' }}>
              Hunter Profiles
            </div>
          </div>
        </a>

        <button
          onClick={() => router.visit('/apply/my-applications')}
          style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', background: 'none', border: '1px solid #a89874', padding: '6px 14px', cursor: 'pointer' }}
        >
          Sign In
        </button>
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function HunterPublicProfile(props: Props) {
  const { username, is_public, template } = props
  // Theme tokens cascade to all descendants via CSS custom properties.
  const themeVars = {
    '--ah-accent': template.theme.accent,
    '--ah-paper': template.theme.paper,
    '--ah-ink': template.theme.ink,
  } as React.CSSProperties
  const showRegMarks = template.decorations.registration_marks.enabled

  return (
    <>
      <Head>
        <title>{is_public && props.display_name ? `${props.display_name} (@${username}) — American Headhunter` : `@${username} — American Headhunter`}</title>
        {!is_public && <meta name="robots" content="noindex" />}
        {is_public && <meta name="description" content={props.bio ?? `${props.display_name ?? username} is a hunter on American Headhunter.`} />}
      </Head>

      <div style={{ ...themeVars, minHeight: '100vh', background: '#EDE5D0' }}>
        <Topbar showRegMarks={showRegMarks} />

        <div style={{ maxWidth: '680px', margin: '0 auto', padding: '56px 24px 80px' }}>
          {is_public ? <PublicCard {...props} username={username} /> : <PrivateStub username={username} />}
        </div>
      </div>
    </>
  )
}

// ── Private stub ──────────────────────────────────────────────────────────────

function PrivateStub({ username }: { username: string }) {
  return (
    <div style={{
      background: 'var(--ah-paper)',
      border: '1px solid #d4c9b0',
      padding: '56px 40px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      gap: '16px',
      textAlign: 'center',
    }}>
      <div style={{
        width: '52px', height: '52px', borderRadius: '50%',
        background: '#e5ddd0', display: 'flex', alignItems: 'center', justifyContent: 'center',
      }}>
        <LockClosedIcon style={{ width: '24px', height: '24px', color: '#a89874' }} />
      </div>

      <div>
        <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', fontWeight: 700, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
          @{username}
        </div>
        <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '22px', fontWeight: 400, color: 'var(--ah-ink)', marginBottom: '10px' }}>
          This profile is private.
        </div>
        <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#888', margin: 0, maxWidth: '360px' }}>
          This hunter has set their profile to private. If you know them, reach out directly through the platform.
        </p>
      </div>

      <a
        href="/"
        style={{
          marginTop: '8px',
          fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700,
          letterSpacing: '.12em', textTransform: 'uppercase',
          color: '#F4ECDC', background: 'var(--ah-ink)',
          padding: '10px 24px', textDecoration: 'none', display: 'inline-block',
        }}
      >
        Back to American Headhunter
      </a>
    </div>
  )
}

// ── Public profile card ───────────────────────────────────────────────────────

function PublicCard(props: Props) {
  const {
    username, display_name, initials, member_since,
    state_code, trust_score = 0, is_veteran, veteran_branch,
    is_first_responder, bio, species = [],
  } = props

  const trustPct   = Math.min(100, Math.max(0, trust_score))
  const trustColor = trustPct >= 75 ? '#4a7c59' : trustPct >= 45 ? '#b8934a' : 'var(--ah-accent)'

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1px' }}>

      {/* Header card */}
      <div style={{ background: 'var(--ah-paper)', border: '1px solid #d4c9b0', padding: '32px 32px 24px' }}>

        <div style={{ display: 'flex', alignItems: 'flex-start', gap: '24px' }}>

          {/* Avatar placeholder */}
          <div style={{
            width: '80px', height: '80px', flexShrink: 0,
            background: 'var(--ah-ink)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '28px', fontWeight: 400, color: '#a89874' }}>
              {initials}
            </span>
          </div>

          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '26px', fontWeight: 400, color: 'var(--ah-ink)', lineHeight: 1.15, marginBottom: '4px' }}>
              {display_name}
            </div>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 600, letterSpacing: '.12em', color: '#a89874', marginBottom: '10px' }}>
              @{username}
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', alignItems: 'center' }}>
              {state_code && (
                <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                  {state_code}
                </span>
              )}
              {member_since && (
                <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#bbb', letterSpacing: '.06em' }}>
                  · Member since {member_since}
                </span>
              )}
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: '10px' }}>
              {is_veteran && (
                <span style={{
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700,
                  letterSpacing: '.14em', textTransform: 'uppercase',
                  padding: '3px 8px', background: 'var(--ah-ink)', color: '#b8934a',
                  border: '1px solid #b8934a',
                }}>
                  Veteran{veteran_branch ? ` · ${veteran_branch.replace(/_/g, ' ')}` : ''}
                </span>
              )}
              {is_first_responder && (
                <span style={{
                  fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700,
                  letterSpacing: '.14em', textTransform: 'uppercase',
                  padding: '3px 8px', background: 'var(--ah-ink)', color: '#6b9e8f',
                  border: '1px solid #6b9e8f',
                }}>
                  First Responder
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Trust score */}
        <div style={{ marginTop: '20px', paddingTop: '16px', borderTop: '1px solid #e5ddd0' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874' }}>
              Trust Score
            </span>
            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, color: trustColor }}>
              {trustPct}
            </span>
          </div>
          <div style={{ height: '4px', background: '#e5ddd0', borderRadius: '2px' }}>
            <div style={{ height: '4px', width: `${trustPct}%`, background: trustColor, borderRadius: '2px', transition: 'width 600ms ease' }} />
          </div>
        </div>
      </div>

      {/* Bio */}
      {bio && (
        <div style={{ background: 'var(--ah-paper)', border: '1px solid #d4c9b0', padding: '20px 32px' }}>
          <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.16em', textTransform: 'uppercase', color: '#a89874', marginBottom: '10px' }}>
            About
          </div>
          <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: 'var(--ah-ink)', lineHeight: 1.65, margin: 0 }}>
            {bio}
          </p>
        </div>
      )}

      {/* Species */}
      {species.length > 0 && (
        <div style={{ background: 'var(--ah-paper)', border: '1px solid #d4c9b0', padding: '20px 32px' }}>
          <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.16em', textTransform: 'uppercase', color: '#a89874', marginBottom: '10px' }}>
            Game Pursued
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
            {species.map(k => (
              <span key={k} style={{
                fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700,
                letterSpacing: '.08em', textTransform: 'uppercase',
                padding: '4px 10px', border: '1px solid #d4c9b0', color: '#4a5440',
              }}>
                {SPECIES_LABELS[k] ?? k}
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Footer note */}
      <div style={{ padding: '16px 0 0', textAlign: 'center' }}>
        <a href="/" style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', textDecoration: 'none' }}>
          American Headhunter — Hunting Lease Marketplace
        </a>
      </div>

    </div>
  )
}
