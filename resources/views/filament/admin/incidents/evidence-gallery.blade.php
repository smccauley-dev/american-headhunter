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
               style="display:block;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#0f0f0f;">
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
                            :style="`width:64px;height:64px;border-radius:6px;overflow:hidden;padding:0;cursor:pointer;background:#f3f4f6;border:2px solid ${active === i ? '#c84c21' : '#e5e7eb'};`">
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
                <a href="{{ $f['url'] }}" target="_blank" rel="noopener"
                   style="display:flex;align-items:center;width:160px;min-height:48px;border:1px solid #e5e7eb;border-radius:6px;padding:8px;background:#f9fafb;font-size:12px;color:#374151;text-decoration:none;word-break:break-word;">
                    {{ $f['name'] }}
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
