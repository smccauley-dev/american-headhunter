// Offline write queue for member field logs (harvest / sighting / fishing).
//
// A hunter often fills a log where there is no signal. When the browser is
// offline the New form drops the submission here — into IndexedDB, keyed by a
// client-minted `local_record_id` (a v4 UUID) — instead of posting it. On
// reconnect (the window 'online' event, or the next page load) `flush()` replays
// each queued item to its normal store URL with `Accept: application/json`, so the
// server returns a real status code: 201 fresh / 200 idempotent replay (it dedups
// on the same `local_record_id`, so a double-flush can never create two rows or
// double-claim a quota), or 409/422/403 which we treat as a terminal rejection the
// member has to see. Anything else (a 5xx, a dropped connection) is left in place
// to retry next time.
//
// This is foreground sync: it runs only while a tab is open. That is a deliberate
// choice over the Background Sync API, which iOS Safari does not support.

export type QueueKind = 'harvest' | 'sighting' | 'fishing'

export interface QueuedLog {
  /** The client-minted local_record_id — also the IndexedDB key. */
  id: string
  kind: QueueKind
  /** The member store route this replays to (e.g. /member/harvest). */
  storeUrl: string
  /** A short human summary for the pending list, e.g. "Whitetail Deer". */
  label: string
  /** The validated form payload (without local_record_id — added at flush). */
  payload: Record<string, unknown>
  createdAt: number
  /** Set when the server rejected the item terminally (409/422/403). */
  error?: string
}

const DB_NAME = 'ah-offline'
const STORE = 'field_logs'
const DB_VERSION = 1

let dbPromise: Promise<IDBDatabase> | null = null

function openDb(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise
  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION)
    req.onupgradeneeded = () => {
      if (!req.result.objectStoreNames.contains(STORE)) {
        req.result.createObjectStore(STORE, { keyPath: 'id' })
      }
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
  return dbPromise
}

function tx<T>(mode: IDBTransactionMode, run: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  return openDb().then(
    db =>
      new Promise<T>((resolve, reject) => {
        const request = run(db.transaction(STORE, mode).objectStore(STORE))
        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
      }),
  )
}

export function allQueued(): Promise<QueuedLog[]> {
  return tx<QueuedLog[]>('readonly', s => s.getAll() as IDBRequest<QueuedLog[]>).then(items =>
    items.sort((a, b) => a.createdAt - b.createdAt),
  )
}

export async function enqueue(item: Omit<QueuedLog, 'createdAt'>): Promise<void> {
  await tx('readwrite', s => s.put({ ...item, createdAt: Date.now() }))
  notify()
  // If we are actually online (e.g. the live POST failed for a transient reason)
  // try to drain immediately rather than waiting for the next 'online' event.
  if (navigator.onLine) void flush()
}

export async function removeQueued(id: string): Promise<void> {
  await tx('readwrite', s => s.delete(id))
  notify()
}

async function markError(id: string, message: string): Promise<void> {
  const existing = await tx<QueuedLog | undefined>('readonly', s => s.get(id) as IDBRequest<QueuedLog | undefined>)
  if (!existing) return
  await tx('readwrite', s => s.put({ ...existing, error: message }))
  notify()
}

// ── change subscription (the pending banner re-reads on every change) ──────────

type Listener = () => void
const listeners = new Set<Listener>()

export function subscribe(cb: Listener): () => void {
  listeners.add(cb)
  return () => listeners.delete(cb)
}

function notify(): void {
  listeners.forEach(cb => cb())
}

// ── flush ──────────────────────────────────────────────────────────────────────

/** Laravel reads the encrypted CSRF token from the XSRF-TOKEN cookie, same as axios. */
function xsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

let flushing = false

/** Replay every non-errored queued item. Safe to call repeatedly. */
export async function flush(): Promise<void> {
  if (flushing || !navigator.onLine) return
  flushing = true
  try {
    for (const item of await allQueued()) {
      if (item.error) continue // terminal — waits for the member to dismiss it

      let res: Response
      try {
        res = await fetch(item.storeUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
          },
          body: JSON.stringify({ ...item.payload, local_record_id: item.id }),
        })
      } catch {
        break // network dropped again — leave the rest queued for next time
      }

      if (res.ok) {
        await removeQueued(item.id)
      } else if (res.status === 409 || res.status === 422 || res.status === 403) {
        const body = (await res.json().catch(() => ({}))) as { message?: string }
        await markError(item.id, body.message ?? `Rejected (${res.status})`)
      }
      // 5xx / anything else: leave in place and retry on the next flush.
    }
  } finally {
    flushing = false
  }
}

/** Wire the global flush triggers once, at app boot. */
export function initOfflineSync(): void {
  window.addEventListener('online', () => void flush())
  if (navigator.onLine) void flush()
}
