import { router } from '@inertiajs/react'
import { enqueue, type QueueKind } from './queue'

/**
 * Submit-or-queue for the field-log New forms. Online, the caller posts through
 * Inertia as usual. Offline, `queue()` mints a local_record_id, drops the log into
 * IndexedDB, and returns to the Index page — where the pending-sync banner shows it
 * waiting. On reconnect the queue replays it to the same store URL (see queue.ts),
 * and the server dedups on that local_record_id so a replay never double-writes.
 */
export function useOfflineSubmit(kind: QueueKind, indexUrl: string) {
  async function queue(storeUrl: string, payload: Record<string, unknown>, label: string): Promise<void> {
    await enqueue({ id: crypto.randomUUID(), kind, storeUrl, label, payload })
    router.visit(indexUrl)
  }

  return { queue }
}
