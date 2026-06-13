<div style="display: flex; flex-direction: column; gap: 1rem;">
    <div>
        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; margin-bottom: 4px;">Subject</div>
        <div style="font-weight: 600;">{{ $rendered->subject }}</div>
    </div>

    @if ($rendered->html !== null)
        <div>
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; margin-bottom: 4px;">HTML Preview (sample data)</div>
            <iframe
                srcdoc="{{ $rendered->html }}"
                sandbox=""
                style="width: 100%; height: 480px; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; background: #fff;"
            ></iframe>
        </div>
    @endif

    @if ($rendered->text !== null)
        <div>
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; margin-bottom: 4px;">Text Preview (sample data)</div>
            <pre style="white-space: pre-wrap; font-size: 13px; padding: 12px 16px; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; margin: 0;">{{ $rendered->text }}</pre>
        </div>
    @endif
</div>
