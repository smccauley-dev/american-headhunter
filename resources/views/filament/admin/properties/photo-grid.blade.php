{{-- Property photo gallery: $photos = collection of PropertyPhoto, ordered.
     Buttons mount Filament page actions on EditPropertyV2 via wire:click. --}}
@php
    $btn = 'display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:6px;background:#fff;'
         . 'border:1px solid #e5e7eb;font-size:12px;font-weight:500;color:#374151;cursor:pointer;white-space:nowrap;';
@endphp
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
    @foreach ($photos as $photo)
        <div style="border:1px solid #e5e0d8;border-radius:8px;overflow:hidden;background:#fff;display:flex;flex-direction:column;">
            <div style="position:relative;aspect-ratio:16/10;background:#f5f1eb;">
                <img src="{{ route('admin.documents.view', $photo->document_id) }}"
                     alt="{{ $photo->caption ?? 'Property photo' }}"
                     loading="lazy"
                     style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                @if ($photo->is_primary)
                    <span style="position:absolute;top:8px;left:8px;background:#0a1512;color:#e8ddc8;font-family:monospace;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 8px;border-radius:3px;">Primary</span>
                @endif
                <span style="position:absolute;top:8px;right:8px;background:rgba(10,21,18,0.65);color:#fff;font-family:monospace;font-size:10px;padding:2px 7px;border-radius:3px;">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} / {{ str_pad((string) $photos->count(), 2, '0', STR_PAD_LEFT) }}</span>
            </div>
            <div style="padding:10px 12px;display:flex;flex-direction:column;gap:8px;flex:1;">
                <div style="font-size:13px;color:#374151;min-height:18px;{{ $photo->caption ? '' : 'color:#9ca3af;font-style:italic;' }}">
                    {{ $photo->caption ?: 'No caption' }}
                </div>
                @if (! empty($photo->tags))
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        @foreach ($photo->tags as $tag)
                            <span style="background:#f5f1eb;color:#6b5d40;border:1px solid #e5e0d8;font-family:monospace;font-size:10px;letter-spacing:.04em;text-transform:uppercase;padding:2px 7px;border-radius:9999px;">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:auto;padding-top:4px;">
                    <button type="button" title="Move earlier"
                        wire:click="mountAction('movePropertyPhoto', { photoId: '{{ $photo->id }}', direction: 'up' })"
                        style="{{ $btn }} {{ $loop->first ? 'opacity:0.35;pointer-events:none;' : '' }}">&#8592;</button>
                    <button type="button" title="Move later"
                        wire:click="mountAction('movePropertyPhoto', { photoId: '{{ $photo->id }}', direction: 'down' })"
                        style="{{ $btn }} {{ $loop->last ? 'opacity:0.35;pointer-events:none;' : '' }}">&#8594;</button>
                    @unless ($photo->is_primary)
                        <button type="button"
                            wire:click="mountAction('makePrimaryPropertyPhoto', { photoId: '{{ $photo->id }}' })"
                            style="{{ $btn }}">&#9733; Primary</button>
                    @endunless
                    <button type="button"
                        wire:click="mountAction('editPropertyPhoto', { photoId: '{{ $photo->id }}' })"
                        style="{{ $btn }}">Edit</button>
                    <button type="button"
                        wire:click="mountAction('deletePropertyPhoto', { photoId: '{{ $photo->id }}' })"
                        style="{{ $btn }} color:#b91c1c;border-color:#fca5a5;">Delete</button>
                </div>
            </div>
        </div>
    @endforeach
</div>
