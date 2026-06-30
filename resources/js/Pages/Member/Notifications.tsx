import { Head, router } from '@inertiajs/react'
import type { NotificationItem } from '../../Components/Member/NotificationBell'
import NotificationBell from '../../Components/Member/NotificationBell'

interface Props {
  items: NotificationItem[]
  unread_count: number
}

function formatWhen(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

export default function Notifications({ items, unread_count }: Props) {
  function open(n: NotificationItem) {
    const go = () => { if (n.action_url) router.visit(n.action_url) }
    if (n.read) { go(); return }
    router.post(`/member/notifications/${n.id}/read`, {}, { preserveScroll: true, preserveState: true, onFinish: go })
  }

  function markRead(e: React.MouseEvent, id: string) {
    e.stopPropagation()
    router.post(`/member/notifications/${id}/read`, {}, { preserveScroll: true, preserveState: true })
  }

  function markAll() {
    router.post('/member/notifications/read-all', {}, { preserveScroll: true, preserveState: true })
  }

  return (
    <>
      <Head title="Notifications" />

      <div style={{ minHeight: '100vh', background: '#F8F4EB' }}>
        {/* Topbar */}
        <div style={{ background: '#0A1512', borderBottom: '1px solid #1a2e28' }}>
          <div style={{ maxWidth: '900px', margin: '0 auto', padding: '0 16px', height: '52px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.15em', textTransform: 'uppercase', color: '#C84C21', fontWeight: 700 }}>
                American Headhunter
              </span>
              <span style={{ color: '#3a5a50', fontSize: '12px' }}>·</span>
              <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f' }}>
                Member Portal
              </span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '18px' }}>
              <NotificationBell />
              <a href="/member" style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#6b9e8f', textDecoration: 'none' }}>
                Dashboard
              </a>
            </div>
          </div>
        </div>

        <div style={{ maxWidth: '900px', margin: '0 auto', padding: '40px 16px 64px' }}>
          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: '24px' }}>
            <div>
              <div style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', letterSpacing: '.14em', textTransform: 'uppercase', color: '#a89874', marginBottom: '6px' }}>
                {unread_count > 0 ? `${unread_count} unread` : 'All caught up'}
              </div>
              <h1 style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '30px', fontWeight: 400, color: '#0A1512', margin: 0, lineHeight: 1.1 }}>
                Notifications
              </h1>
            </div>
            {unread_count > 0 && (
              <button onClick={markAll} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#0A1512', background: 'none', border: '1px solid #d4c9b0', padding: '9px 18px', cursor: 'pointer' }}>
                Mark all read
              </button>
            )}
          </div>

          {items.length === 0 ? (
            <div style={{ border: '1px solid #d4c9b0', background: '#FBF7EE', padding: '48px 24px', textAlign: 'center', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '16px', color: '#7a6a48' }}>
              You don't have any notifications yet.
            </div>
          ) : (
            <div style={{ border: '1px solid #d4c9b0', background: '#FBF7EE' }}>
              {items.map((n, i) => (
                <div
                  key={n.id}
                  onClick={() => open(n)}
                  style={{ display: 'flex', alignItems: 'flex-start', gap: '14px', padding: '18px 22px', borderTop: i === 0 ? 'none' : '1px solid #efe6d2', borderLeft: n.read ? '3px solid transparent' : '3px solid #C84C21', background: n.read ? 'transparent' : 'rgba(200,76,33,0.05)', cursor: n.action_url ? 'pointer' : 'default' }}
                >
                  <div style={{ flex: 1 }}>
                    <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '12px', marginBottom: '4px' }}>
                      <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '17px', color: '#0A1512', fontWeight: n.read ? 400 : 600 }}>
                        {n.title}
                      </span>
                      <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#a89874', whiteSpace: 'nowrap', flexShrink: 0 }}>
                        {formatWhen(n.created_at)}
                      </span>
                    </div>
                    <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '15px', color: '#4a4334', lineHeight: 1.45 }}>
                      {n.body}
                    </div>
                  </div>
                  {!n.read && (
                    <button onClick={e => markRead(e, n.id)} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.08em', textTransform: 'uppercase', color: '#7a6a48', background: 'none', border: 'none', cursor: 'pointer', padding: '2px 0', whiteSpace: 'nowrap', flexShrink: 0 }}>
                      Mark read
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  )
}
