@php
    /** @var array<int, array{url:string,name:string,isImage:bool}> $items */
    /** @var array<int, string> $missing */
    $missing = $missing ?? [];
    $images  = array_values(array_filter($items, fn ($i) => $i['isImage']));
    $files   = array_values(array_filter($items, fn ($i) => ! $i['isImage']));
@endphp

@if (empty($items) && empty($missing))
    <span style="color:#9ca3af;">No photo evidence submitted.</span>
@else
    @if (! empty($images))
        <div
            x-data="{ active: 0, images: {{ \Illuminate\Support\Js::from($images) }} }"
            style="max-width:560px;"
        >
            {{-- Main image (click to open full-size in a new tab) --}}
            <a :href="images[active].url" target="_blank" rel="noopener"
               style="display:block;border:1px solid #e5e7eb;overflow:hidden;background:#0f0f0f;">
                <img :src="images[active].url" :alt="images[active].name"
                     style="display:block;width:100%;max-height:380px;object-fit:contain;background:#0f0f0f;">
            </a>
            <div style="margin-top:6px;font-size:11px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                 x-text="images[active].name"></div>

            {{-- Thumbnail strip: click to swap into the main slot --}}
            <template x-if="images.length > 1">
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                    <template x-for="(img, i) in images" :key="i">
                        <button type="button" @click="active = i"
                            :style="`width:64px;height:64px;overflow:hidden;padding:0;cursor:pointer;background:#f3f4f6;border:2px solid ${active === i ? '#c84c21' : '#e5e7eb'};`">
                            <img :src="img.url" :alt="img.name" loading="lazy"
                                 style="display:block;width:100%;height:100%;object-fit:cover;">
                        </button>
                    </template>
                </div>
            </template>
        </div>
    @endif

    @if (! empty($files))
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:{{ empty($images) ? '0' : '12px' }};">
            @foreach ($files as $f)
                @php $ext = strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                <a href="{{ $f['url'] }}" target="_blank" rel="noopener" title="{{ $f['name'] }}"
                   style="display:block;width:120px;text-decoration:none;">
                    <div style="width:120px;height:140px;border:1px solid #e5e7eb;background:#fff;display:flex;align-items:center;justify-content:center;">
                        <svg width="56" height="68" viewBox="0 0 56 68" fill="none" style="display:block;">
                            <path d="M5 2h32l14 14v50H5z" fill="#fff" stroke="#c84c21" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M37 2v14h14" fill="none" stroke="#c84c21" stroke-width="3" stroke-linejoin="round"/>
                            <line x1="14" y1="26" x2="42" y2="26" stroke="#c84c21" stroke-width="2.5" stroke-linecap="round"/>
                            <line x1="14" y1="33" x2="42" y2="33" stroke="#c84c21" stroke-width="2.5" stroke-linecap="round"/>
                            <line x1="14" y1="40" x2="34" y2="40" stroke="#c84c21" stroke-width="2.5" stroke-linecap="round"/>
                            <rect x="2" y="46" width="34" height="18" fill="#c84c21"/>
                            <text x="19" y="59" font-family="ui-sans-serif,system-ui,sans-serif" font-size="12" font-weight="700" fill="#fff" text-anchor="middle">{{ $ext === 'PDF' ? 'PDF' : $ext }}</text>
                        </svg>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $f['name'] }}</div>
                </a>
            @endforeach
        </div>
    @endif

    @if (! empty($missing))
        <div style="margin-top:{{ (empty($images) && empty($files)) ? '0' : '12px' }};display:flex;flex-direction:column;gap:4px;">
            @foreach ($missing as $id)
                <div style="font-size:10px;font-family:ui-monospace,monospace;color:#9ca3af;">MISSING DOC: {{ $id }}</div>
            @endforeach
        </div>
    @endif
@endif
