import { useEffect, useState } from 'react'
import { allQueued, flush, removeQueued, subscribe, type QueuedLog } from './queue'

/**
 * Live view of the offline write queue for the Index pages' pending-sync banner.
 * Re-reads on every queue change and on connectivity flips, and drains the queue
 * whenever the tab regains its connection.
 */
export function useOfflineQueue() {
  const [items, setItems] = useState<QueuedLog[]>([])
  const [online, setOnline] = useState<boolean>(() =>
    typeof navigator === 'undefined' ? true : navigator.onLine,
  )

  useEffect(() => {
    let active = true
    const refresh = () => {
      allQueued().then(rows => {
        if (active) setItems(rows)
      })
    }
    refresh()

    const unsubscribe = subscribe(refresh)
    const onOnline = () => {
      setOnline(true)
      void flush()
    }
    const onOffline = () => setOnline(false)
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)

    return () => {
      active = false
      unsubscribe()
      window.removeEventListener('online', onOnline)
      window.removeEventListener('offline', onOffline)
    }
  }, [])

  return {
    items,
    online,
    pending: items.filter(i => !i.error).length,
    errored: items.filter(i => i.error),
    syncNow: flush,
    dismiss: removeQueued,
  }
}
