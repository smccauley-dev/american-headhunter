import { useForm, router } from '@inertiajs/react'
import { useState } from 'react'
import { Section, UserGroupIcon, INK, ACCENT } from './PropertyChrome'

export interface Manager {
  id: string
  name: string
  email: string
  role: string
  granted_at: string | null
  granted_by: string
}

const input: React.CSSProperties = {
  width: '100%', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: INK,
  background: '#fff', border: '1px solid #d4c9b0', padding: '9px 11px', outline: 'none', boxSizing: 'border-box',
}

const label: React.CSSProperties = {
  display: 'block', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 600,
  letterSpacing: '.12em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px',
}

const ROLE_COLOR: Record<string, string> = {
  owner: '#9d174d', co_owner: '#065f46', manager: '#1e40af', operator: '#92400e',
}

export default function PropertyTeamTab({ propertyId, managers, roles }: {
  propertyId: string
  managers: Manager[]
  roles: Record<string, string>
}) {
  const [granting, setGranting] = useState(false)
  const roleKeys = Object.keys(roles)

  const { data, setData, post, processing, errors, reset } = useForm({
    user_email: '',
    role: roleKeys.includes('manager') ? 'manager' : roleKeys[0],
  })

  function grant(e: React.FormEvent) {
    e.preventDefault()
    post(`/member/properties/${propertyId}/managers`, {
      preserveScroll: true,
      onSuccess: () => { reset(); setGranting(false) },
    })
  }

  function revoke(id: string) {
    if (!confirm('Revoke this manager’s access?')) return
    router.delete(`/member/properties/${propertyId}/managers/${id}`, { preserveScroll: true })
  }

  const grantAction = !granting ? (
    <button onClick={() => setGranting(true)} style={{ fontFamily: 'var(--mono)', fontSize: '9px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', padding: '8px 16px', background: INK, color: '#F4ECDC', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap' }}>
      + Grant Access
    </button>
  ) : undefined

  return (
    <Section title="Managers" icon={<UserGroupIcon />} description="Users who can manage this property on behalf of the owner." action={grantAction}>
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
              {m.role !== 'owner' && (
                <button onClick={() => revoke(m.id)} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', padding: '7px 14px', background: 'transparent', color: ACCENT, border: '1px solid rgba(200,76,33,0.4)', cursor: 'pointer', flexShrink: 0 }}>
                  Revoke
                </button>
              )}
            </div>
          ))}
        </div>
      )}
    </Section>
  )
}
