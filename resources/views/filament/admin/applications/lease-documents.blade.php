{{--
    Lease document list.
    $documents: [['label','badge','badgeStyle','subtitle','filename','size','date','downloadUrl','deletableId'], ...]
    $deletedDocuments: [['label','badge','badgeStyle','filename','size','deletedDate','prunedOn','restoreId'], ...]
    Delete/restore buttons mount Filament page actions via wire:click.
--}}
@if (empty($documents) && empty($deletedDocuments))
    <p style="color:#888;font-style:italic;font-size:13px">No documents attached yet.</p>
@else
    @if (! empty($documents))
        <div style="display:flex;flex-direction:column;gap:8px;">
            @foreach ($documents as $d)
                <div style="display:flex;align-items:center;justify-content:space-between;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;background:#fff;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <svg style="width:28px;height:28px;flex-shrink:0;color:#C84C21;" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/>
                        </svg>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-size:14px;font-weight:600;color:#111827;">{{ $d['label'] }}</span>
                                <span style="font-size:11px;font-weight:700;padding:1px 6px;border-radius:4px;{{ $d['badgeStyle'] }}">{{ $d['badge'] }}</span>
                            </div>
                            @if ($d['subtitle'] !== '')
                                <p style="font-size:12px;color:#6b7280;margin:0;">{{ $d['subtitle'] }}</p>
                            @endif
                            <p style="font-size:11px;color:#9ca3af;margin:0;">{{ implode(' · ', array_filter([$d['filename'], $d['size'], $d['date']])) }}</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                        <a href="{{ $d['downloadUrl'] }}" target="_blank"
                           style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;background:#f3f4f6;font-size:13px;font-weight:500;color:#374151;text-decoration:none;border:1px solid #e5e7eb;white-space:nowrap;">
                            <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Download
                        </a>
                        @if ($d['deletableId'])
                            <button type="button"
                                wire:click="mountAction('deleteLeaseDocument', { documentId: '{{ $d['deletableId'] }}' })"
                                style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:6px;background:#fff;font-size:13px;font-weight:500;color:#b91c1c;cursor:pointer;border:1px solid #fca5a5;white-space:nowrap;flex-shrink:0;">
                                <svg style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Delete
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
    @if (! empty($deletedDocuments))
        <details style="margin-top:12px;">
            <summary style="cursor:pointer;font-family:monospace;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;list-style:none;display:flex;align-items:center;gap:6px;user-select:none;">
                <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                {{ count($deletedDocuments) === 1 ? '1 deleted document' : count($deletedDocuments) . ' deleted documents' }} — click to expand
            </summary>
            <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">
                @foreach ($deletedDocuments as $d)
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fafafa;border:1px solid #e5e7eb;border-radius:6px;opacity:0.75;">
                        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                            <svg style="width:22px;height:22px;flex-shrink:0;color:#9ca3af;" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/>
                            </svg>
                            <div style="min-width:0;">
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <span style="font-size:13px;font-weight:600;color:#6b7280;">{{ $d['label'] }}</span>
                                    <span style="font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;{{ $d['badgeStyle'] }}opacity:0.6;">{{ $d['badge'] }}</span>
                                </div>
                                <div style="font-size:11px;color:#9ca3af;margin-top:2px;font-family:monospace;">
                                    {{ implode(' · ', array_filter([$d['filename'], $d['size']])) }} &nbsp;·&nbsp; Deleted {{ $d['deletedDate'] }} &nbsp;·&nbsp; <span style="color:#dc2626;">Permanently removed {{ $d['prunedOn'] }}</span>
                                </div>
                            </div>
                        </div>
                        <button type="button"
                            wire:click="mountAction('restoreLeaseDocument', { documentId: '{{ $d['restoreId'] }}' })"
                            style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:6px;background:#fff;border:1px solid #6ee7b7;font-size:13px;font-weight:500;color:#065f46;cursor:pointer;white-space:nowrap;flex-shrink:0;margin-left:12px;">
                            <svg style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Restore
                        </button>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
@endif
