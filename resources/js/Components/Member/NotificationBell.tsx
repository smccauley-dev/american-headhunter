import { useState, useEffect, useRef } from 'react'
import { router, usePage } from '@inertiajs/react'

export interface NotificationItem {
  id: string
  type: string
  title: string
  body: string
  action_url: string | null
  read: boolean
  created_at: string | null
}

interface SharedProps {
  notifications?: {
    unread_count: number
    recent: NotificationItem[]
  } | null
  [key: string]: unknown
}

function timeAgo(iso: string | null): string {
  if (!iso) return ''
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return ''
  const secs = Math.max(0, Math.floor((Date.now() - then) / 1000))
  if (secs < 60) return 'just now'
  const mins = Math.floor(secs / 60)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.floor(hrs / 24)
  if (days < 30) return `${days}d ago`
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * Member-portal notification bell. Reads the `notifications` Inertia shared prop
 * (unread count + recent list), shows an unread badge, and opens a dropdown
 * preview. Sits in the dark member topbar.
 */
export default function NotificationBell() {
  const { notifications } = usePage<SharedProps>().props
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [])

  if (!notifications) return null

  const { unread_count: unread, recent } = notifications

  function openItem(n: NotificationItem) {
    setOpen(false)
    const go = () => { if (n.action_url) router.visit(n.action_url) }
    if (n.read) { go(); return }
    router.post(`/member/notifications/${n.id}/read`, {}, {
      preserveScroll: true,
      preserveState: true,
      onFinish: go,
    })
  }

  function markAll() {
    router.post('/member/notifications/read-all', {}, { preserveScroll: true, preserveState: true })
  }

  return (
    <div ref={ref} style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
      <button
        onClick={() => setOpen(o => !o)}
        aria-label={`Notifications${unread > 0 ? ` (${unread} unread)` : ''}`}
        style={{ position: 'relative', background: 'none', border: 'none', cursor: 'pointer', padding: '4px', display: 'flex', alignItems: 'center', color: '#6b9e8f', lineHeight: 0 }}
      >
        <svg width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.6" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        {unread > 0 && (
          <span style={{ position: 'absolute', top: '-2px', right: '-3px', minWidth: '15px', height: '15px', padding: '0 4px', borderRadius: '8px', background: '#C84C21', color: '#fff', fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 700, lineHeight: '15px', textAlign: 'center' }}>
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div style={{ position: 'absolute', top: '34px', right: 0, width: '340px', maxWidth: 'calc(100vw - 32px)', background: '#FBF7EE', border: '1px solid #d4c9b0', boxShadow: '0 12px 28px rgba(10,21,18,0.22)', zIndex: 80 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid #e4dac2' }}>
            <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: '#0A1512' }}>
              Notifications
            </span>
            {unread > 0 && (
              <button onClick={markAll} style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', fontWeight: 600, letterSpacing: '.08em', textTransform: 'uppercase', color: '#7a6a48', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>
                Mark all read
              </button>
            )}
          </div>

          <div style={{ maxHeight: '360px', overflowY: 'auto' }}>
            {recent.length === 0 ? (
              <div style={{ padding: '28px 16px', textAlign: 'center', fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '14px', color: '#7a6a48' }}>
                You're all caught up.
              </div>
            ) : (
              recent.map(n => (
                <button
                  key={n.id}
                  onClick={() => openItem(n)}
                  style={{ display: 'block', width: '100%', textAlign: 'left', padding: '12px 16px', borderBottom: '1px solid #efe6d2', background: n.read ? 'transparent' : 'rgba(200,76,33,0.05)', border: 'none', borderLeft: n.read ? '3px solid transparent' : '3px solid #C84C21', cursor: 'pointer' }}
                >
                  <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '10px', marginBottom: '3px' }}>
                    <span style={{ fontFamily: 'Fraunces, Georgia, serif', fontSize: '14px', color: '#0A1512', fontWeight: n.read ? 400 : 600 }}>
                      {n.title}
                    </span>
                    <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '9px', color: '#a89874', whiteSpace: 'nowrap', flexShrink: 0 }}>
                      {timeAgo(n.created_at)}
                    </span>
                  </div>
                  <div style={{ fontFamily: 'Crimson Pro, Georgia, serif', fontSize: '13px', color: '#4a4334', lineHeight: 1.4 }}>
                    {n.body}
                  </div>
                </button>
              ))
            )}
          </div>

          <a href="/member/notifications" style={{ display: 'block', textAlign: 'center', padding: '11px 16px', borderTop: '1px solid #e4dac2', fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: '#0A1512', textDecoration: 'none' }}>
            View all
          </a>
        </div>
      )}
    </div>
  )
}
