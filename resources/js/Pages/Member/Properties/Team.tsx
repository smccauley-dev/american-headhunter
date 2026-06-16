import { Head, useForm, router } from '@inertiajs/react'
import { useState } from 'react'

interface Manager {
  id: string
  name: string
  email: string
  role: string
  granted_at: string | null
  granted_by: string
}

interface CheckIn {
  name: string
  email: string
  lease_ref: string
  checked_in_at: string | null
  checked_out_at: string | null
  open: boolean
}

interface Props {
  property: { id: string; title: string }
  managers: Manager[]
  roles: Record<string, string>
  checkIns: CheckIn[]
}

// ── Design tokens ───────────────────────────────────────────────────────────
const INK = '#0A1512'
const ACCENT = '#C84C21'
const PAPER = '#F8F4EB'

const sectionLabel: React.CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '11px',
  fontWeight: 700,
  letterSpacing: '.14em',
  textTransform: 'uppercase',
  color: '#a89874',
  marginBottom: '14px',
}

const cardStyle: React.CSSProperties = {
  border: '1px solid #d4c9b0',
  background: '#FBF7EE',
  padding: '24px 22px',
  marginBottom: '22px',
}

const input: React.CSSProperties = {
  width: '100%',
  fontFamily: 'Crimson Pro, Georgia, serif',
  fontSize: '15px',
  color: INK,
  background: '#fff',
  border: '1px solid #d4c9b0',
  padding: '9px 11px',
  outline: 'none',
  boxSizing: 'border-box',
}

const label: React.CSSProperties = {
  display: 'block',
  fontFamily: 'JetBrains Mono, monospace',
  fontSize: '10px',
  fontWeight: 600,
  letterSpacing: '.12em',
  textTransform: 'uppercase',
  color: '#a89874',
  marginBottom: '6px',
}

const ROLE_COLOR: Record<string, string> = {
  owner: '#9d174d',
  co_owner: '#065f46',
  manager: '#1e40af',
  operator: '#92400e',
}

export default function PropertyTeam({ property, managers, roles, checkIns }: Props) {
  const [granting, setGranting] = useState(false)
  const roleKeys = Object.keys(roles)

  const { data, setData, post, processing, errors, reset } = useForm({
    user_email: '',
    role: roleKeys.includes('manager') ? 'manager' : roleKeys[0],
  })

  function grant(e: React.FormEvent) {
    e.preventDefault()
    post(`/member/properties/${property.id}/managers`, {
      preserveScroll: true,
      onSuccess: () => { reset(); setGranting(false) },
    })
  }

  function revoke(id: string) {
    if (!confirm('Revoke this manager’s access?')) return
    router.delete(`/member/properties/${property.id}/managers/${id}`, { preserveScroll: true })
  }

  return (
    <>
      <Head title={`Team · ${property.title}`} />

      <div style={{ minHeight: '100vh', background: PAPER }}>

        {/* Topbar */}
        <div style={{ background: INK, borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '760px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: ACCENT, fontWeight: 700 }}>
                American Headhunter
              </span>
              <span style={{ color: '#3a5a50', fontSize: '12px' }}>·</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                Property Management
              </span>
            </div>
            <button onClick={() => router.post('/logout')} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', background: 'none', border: 'none', cursor: 'pointer', padding: '4px 0' }}>
              Sign Out
            </button>
          </div>
        </div>

        <div style={{ maxWidth: '760px', margin: '0 auto', padding: '36px 16px 64px' }}>

          <a href={`/member/properties/${property.id}`} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#a89874', textDecoration: 'none', display: 'inline-block', marginBottom: '18px' }}>
            ← {property.title}
          </a>

          <div style={{ marginBottom: '28px' }}>
            <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
              Team & Activity
            </div>
            <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '28px', fontWeight: 400, color: INK, margin: 0, lineHeight: 1.1 }}>
              {property.title}
            </h1>
          </div>

          {/* Managers */}
          <div style={cardStyle}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '14px' }}>
              <div style={{ ...sectionLabel, marginBottom: 0 }}>Managers</div>
              {!granting && (
                <button onClick={() => setGranting(true)} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 16px', background: INK, color: '#F4ECDC', border: 'none', cursor: 'pointer' }}>
                  + Grant Access
                </button>
              )}
            </div>

            {granting && (
              <form onSubmit={grant} style={{ border: `1px solid ${ACCENT}`, background: '#fff', padding: '18px 16px', marginBottom: '16px', display: 'flex', flexDirection: 'column', gap: '14px' }}>
                <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '12px' }}>
                  <div>
                    <label htmlFor="user_email" style={label}>User Email</label>
                    <input id="user_email" type="email" value={data.user_email} onChange={e => setData('user_email', e.target.value)} style={input} placeholder="hunter@example.com" />
                    {errors.user_email && <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: ACCENT, marginTop: '5px' }}>{errors.user_email}</div>}
                  </div>
                  <div>
                    <label htmlFor="role" style={label}>Role</label>
                    <select id="role" value={data.role} onChange={e => setData('role', e.target.value)} style={input}>
                      {roleKeys.map(k => <option key={k} value={k}>{roles[k]}</option>)}
                    </select>
                  </div>
                </div>
                <div style={{ display: 'flex', gap: '10px' }}>
                  <button type="submit" disabled={processing} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: INK, color: '#F4ECDC', border: 'none', cursor: processing ? 'not-allowed' : 'pointer', opacity: processing ? 0.7 : 1 }}>
                    {processing ? 'Granting…' : 'Grant'}
                  </button>
                  <button type="button" onClick={() => { reset(); setGranting(false) }} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '9px 22px', background: 'transparent', color: INK, border: '1px solid #d4c9b0', cursor: 'pointer' }}>
                    Cancel
                  </button>
                </div>
                <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#6b5e50', margin: 0, lineHeight: 1.5 }}>
                  The person must already have an American Headhunter account. They'll be able to manage this property on your behalf.
                </p>
              </form>
            )}

            {managers.length === 0 ? (
              <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
                No managers yet. You're the only one who can manage this property.
              </p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column' }}>
                {managers.map((m, i) => (
                  <div key={m.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', padding: '12px 0', borderTop: i === 0 ? 'none' : '1px solid #e5ddd0' }}>
                    <div>
                      <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: INK, display: 'flex', alignItems: 'center', gap: '9px' }}>
                        {m.name}
                        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '2px 7px', borderRadius: '9999px', background: ROLE_COLOR[m.role] ?? INK, color: '#fff' }}>
                          {roles[m.role] ?? m.role}
                        </span>
                      </div>
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#a89874', marginTop: '3px' }}>
                        {m.email}{m.granted_at ? ` · granted ${m.granted_at} by ${m.granted_by}` : ''}
                      </div>
                    </div>
                    <button onClick={() => revoke(m.id)} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '7px 14px', background: 'transparent', color: ACCENT, border: '1px solid rgba(200,76,33,0.4)', cursor: 'pointer', flexShrink: 0 }}>
                      Revoke
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Check-In Log */}
          <div style={cardStyle}>
            <div style={sectionLabel}>Check-In Log</div>
            {checkIns.length === 0 ? (
              <p style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#6b5e50', margin: 0 }}>
                No check-ins recorded on this property yet.
              </p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column' }}>
                {checkIns.map((c, i) => (
                  <div key={i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', padding: '11px 0', borderTop: i === 0 ? 'none' : '1px solid #e5ddd0' }}>
                    <div>
                      <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK }}>
                        {c.name}
                        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#a89874', marginLeft: '8px' }}>#{c.lease_ref}</span>
                      </div>
                      <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#6b5e50', marginTop: '3px' }}>
                        In {c.checked_in_at ?? '—'}{c.checked_out_at ? ` · Out ${c.checked_out_at}` : ''}
                      </div>
                    </div>
                    {c.open && (
                      <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '8px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 9px', borderRadius: '9999px', background: '#065f46', color: '#fff', flexShrink: 0 }}>
                        On Site
                      </span>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>

        </div>
      </div>
    </>
  )
}
