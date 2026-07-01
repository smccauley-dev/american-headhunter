import { useOfflineQueue } from '@/offline/useOfflineQueue'

const INK = '#0a1512'
const MONO = "'JetBrains Mono', Menlo, monospace"

/**
 * Pending-sync banner for the field-log Index pages. Shows how many logs are
 * waiting to reach the server, a manual "Sync now", and any items the server
 * rejected on replay (over quota, CWD zone, lost standing) so the member can read
 * the reason and clear them.
 */
export default function OfflineQueueBanner() {
  const { items, pending, errored, online, syncNow, dismiss } = useOfflineQueue()

  if (items.length === 0) return null

  return (
    <div style={{ marginBottom: '20px', display: 'flex', flexDirection: 'column', gap: '10px' }}>
      {pending > 0 && (
        <div style={{ background: '#fef6ee', border: '1px solid #f0c9a8', borderRadius: '4px', padding: '13px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' }}>
          <div style={{ fontFamily: MONO, fontSize: '12px', color: '#9a4a1e', lineHeight: 1.4 }}>
            <strong>{pending}</strong> {pending === 1 ? 'log' : 'logs'} waiting to sync
            {!online && ' · offline'}
          </div>
          <button
            type="button"
            onClick={() => void syncNow()}
            disabled={!online}
            style={{ fontFamily: MONO, fontSize: '10px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#fff', background: online ? '#c84c21' : '#c9b8a4', border: 'none', borderRadius: '3px', padding: '9px 14px', cursor: online ? 'pointer' : 'not-allowed', whiteSpace: 'nowrap' }}
          >
            Sync Now
          </button>
        </div>
      )}

      {errored.map(item => (
        <div key={item.id} style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: '4px', padding: '13px 16px', display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '12px' }}>
          <div style={{ lineHeight: 1.45 }}>
            <div style={{ fontFamily: MONO, fontSize: '10px', letterSpacing: '.1em', textTransform: 'uppercase', color: '#b91c1c', fontWeight: 700, marginBottom: '3px' }}>
              Couldn't sync · {item.label}
            </div>
            <div style={{ fontSize: '13px', color: INK }}>{item.error}</div>
          </div>
          <button
            type="button"
            onClick={() => void dismiss(item.id)}
            style={{ fontFamily: MONO, fontSize: '10px', fontWeight: 700, letterSpacing: '.08em', textTransform: 'uppercase', color: '#b91c1c', background: 'transparent', border: '1px solid #fca5a5', borderRadius: '3px', padding: '9px 12px', cursor: 'pointer', whiteSpace: 'nowrap' }}
          >
            Dismiss
          </button>
        </div>
      ))}
    </div>
  )
}
