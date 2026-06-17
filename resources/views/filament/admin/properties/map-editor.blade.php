{{--
    Property map editor.
    $images   = live PropertyMapImage collection (boundary first)
    $selected = PropertyMapImage with markers loaded (or null)
    $deleted  = soft-deleted PropertyMapImage collection
    Marker placement/drag is Alpine-driven; everything else mounts Filament
    page actions on EditPropertyV2.
--}}
@php
    $btn = 'display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:0;background:#FAFAFA;'
         . 'border:1px solid #e5e7eb;font-size:12px;font-weight:500;color:#374151;cursor:pointer;white-space:nowrap;text-decoration:none;'
         . 'text-transform:uppercase;letter-spacing:.04em;';
@endphp

@if ($selected)
    <div
        x-data="{
            addMode: false,
            drag: null,
            moved: false,
            pct(e) {
                const r = this.$refs.mapimg.getBoundingClientRect();
                return {
                    x: Math.min(100, Math.max(0, ((e.clientX - r.left) / r.width) * 100)),
                    y: Math.min(100, Math.max(0, ((e.clientY - r.top) / r.height) * 100)),
                };
            },
            canvasClick(e) {
                if (! this.addMode) return;
                const p = this.pct(e);
                this.addMode = false;
                $wire.mountAction('addMapMarker', { mapImageId: '{{ $selected->id }}', x: p.x.toFixed(3), y: p.y.toFixed(3) });
            },
            startDrag(e, id) {
                this.drag = { id: id, el: e.currentTarget };
                this.moved = false;
                e.currentTarget.setPointerCapture(e.pointerId);
            },
            onMove(e) {
                if (! this.drag) return;
                this.moved = true;
                const p = this.pct(e);
                this.drag.el.style.left = p.x + '%';
                this.drag.el.style.top = p.y + '%';
            },
            endDrag(e) {
                if (! this.drag) return;
                const id = this.drag.id, wasMoved = this.moved;
                this.drag = null;
                this.moved = false;
                if (wasMoved) {
                    const p = this.pct(e);
                    $wire.moveMapMarker(id, parseFloat(p.x.toFixed(3)), parseFloat(p.y.toFixed(3)));
                } else {
                    $wire.mountAction('editMapMarker', { markerId: id });
                }
            },
        }"
        wire:key="map-editor-{{ $selected->id }}-{{ $selected->markers->count() }}"
    >
        {{-- Toolbar --}}
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:10px;">
            <button type="button"
                x-on:click="addMode = ! addMode"
                style="{{ $btn }}"
                x-bind:style="addMode ? { borderColor: '#0a1512', background: '#0a1512', color: '#ffffff' } : {}"
                x-text="addMode ? '+ Click the map to place — or cancel' : '+ Add Marker'"></button>
            <button type="button" style="{{ $btn }}"
                wire:click="mountAction('editMapImage', { mapImageId: '{{ $selected->id }}' })">Edit Details</button>
            <a href="{{ route('admin.documents.download', $selected->document_id) }}" style="{{ $btn }}">Download</a>
            <button type="button" style="{{ $btn }} color:#b91c1c;border-color:#fca5a5;"
                wire:click="mountAction('deleteMapImage', { mapImageId: '{{ $selected->id }}' })">Delete</button>
            <span style="margin-left:auto;font-family:monospace;font-size:11px;color:#9ca3af;">
                {{ $selected->markers->count() }} marker{{ $selected->markers->count() === 1 ? '' : 's' }} · drag a pin to move it · click a pin to edit
            </span>
        </div>

        {{-- Map canvas --}}
        <div style="position:relative;border:1px solid #0a1512;border-radius:4px;overflow:hidden;background:#f5f1eb;user-select:none;">
            <img x-ref="mapimg"
                 src="{{ route('admin.documents.view', $selected->document_id) }}"
                 alt="{{ $selected->description ?? 'Property map' }}"
                 draggable="false"
                 style="display:block;width:100%;height:auto;pointer-events:none;">

            {{-- Click-capture overlay, present only while placing a marker --}}
            <div x-show="addMode" x-cloak
                 x-on:click="canvasClick($event)"
                 style="position:absolute;inset:0;cursor:crosshair;z-index:8;"></div>

            @if ($selected->is_boundary)
                    <span style="position:absolute;top:10px;left:10px;background:#0a1512;color:#fff !important;border:1px solid #a89874;font-family:monospace;font-size:10px !important;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 9px;border-radius:3px;line-height:1.4;white-space:nowrap;z-index:10;">Boundary Map</span>
            @endif

            @foreach ($selected->markers as $m)
                @php $color = $m->displayColor(); @endphp
                <div
                    x-on:pointerdown.stop="startDrag($event, '{{ $m->id }}')"
                    x-on:pointermove="onMove($event)"
                    x-on:pointerup.stop="endDrag($event)"
                    x-on:click.stop
                    title="{{ $m->label }} ({{ \App\Models\Property\PropertyMapMarker::TYPES[$m->marker_type] ?? $m->marker_type }})"
                    style="position:absolute;left:{{ $m->x_percent }}%;top:{{ $m->y_percent }}%;transform:translate(-50%,-50%);z-index:5;cursor:grab;touch-action:none;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <span style="display:block;width:14px;height:14px;border-radius:50%;background:{{ $color }};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.45);"></span>
                    <span style="background:rgba(10,21,18,0.8);color:#fff !important;font-family:monospace;font-size:9px !important;letter-spacing:.05em;padding:2px 6px;border-radius:3px;white-space:nowrap;max-width:160px;overflow:hidden;text-overflow:ellipsis;pointer-events:none;">{{ $m->label }}</span>
                </div>
            @endforeach
        </div>

        {{-- Selected image meta --}}
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:10px;">
            <span style="font-size:13px;color:#374151;{{ $selected->description ? '' : 'color:#9ca3af;font-style:italic;' }}">
                {{ $selected->description ?: 'No description' }}
            </span>
            @if ($selected->latitude !== null && $selected->longitude !== null)
                <a href="https://maps.google.com/?q={{ $selected->latitude }},{{ $selected->longitude }}" target="_blank" rel="noopener"
                   style="font-family:monospace;font-size:11px;color:#6b7280;text-decoration:none;">
                    📍 {{ number_format($selected->latitude, 6) }}, {{ number_format($selected->longitude, 6) }}
                </a>
            @endif
        </div>
    </div>
@endif

{{-- Other map images — carousel --}}
@if ($images->count() > 1 || ($images->count() === 1 && ! $selected))
    <div style="margin-top:18px;">
        <div style="font-family:monospace;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">All Map Images</div>
        <div style="display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;">
            @foreach ($images as $img)
                <div wire:click="selectMapImage('{{ $img->id }}')"
                     style="flex:0 0 150px;cursor:pointer;border:2px solid {{ $selected && $img->id === $selected->id ? '#a89874' : '#e5e0d8' }};border-radius:6px;overflow:hidden;background:#fff;">
                    <div style="position:relative;aspect-ratio:16/10;background:#f5f1eb;">
                        <img src="{{ route('admin.documents.view', $img->document_id) }}"
                             alt="{{ $img->description ?? 'Map image' }}"
                             loading="lazy"
                             style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                        @if ($img->is_boundary)
                            <span style="position:absolute;top:4px;left:4px;background:#0a1512;color:#fff !important;font-family:monospace;font-size:8px !important;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:2px 5px;border-radius:2px;line-height:1.3;">Boundary</span>
                        @endif
                    </div>
                    <div style="padding:5px 8px;font-size:11px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        {{ $img->description ?: 'No description' }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- Deleted map images — recovery section --}}
@if ($deleted->isNotEmpty())
    <details style="margin-top:14px;">
        <summary style="cursor:pointer;font-family:monospace;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em;user-select:none;">
            {{ $deleted->count() === 1 ? '1 deleted map image' : $deleted->count() . ' deleted map images' }} — click to expand
        </summary>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">
            @foreach ($deleted as $img)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#fafafa;border:1px solid #e5e7eb;border-radius:6px;opacity:0.75;">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                        <img src="{{ route('admin.documents.view', $img->document_id) }}" alt=""
                             style="width:56px;height:36px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                        <div style="min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $img->description ?: 'No description' }}</div>
                            <div style="font-size:11px;color:#9ca3af;font-family:monospace;">Deleted {{ $img->deleted_at?->format('M j, Y') }} — markers preserved</div>
                        </div>
                    </div>
                    <button type="button"
                        wire:click="mountAction('restoreMapImage', { mapImageId: '{{ $img->id }}' })"
                        style="{{ $btn }} color:#065f46;border-color:#6ee7b7;flex-shrink:0;margin-left:12px;">Restore</button>
                </div>
            @endforeach
        </div>
    </details>
@endif

@if (! $selected && $deleted->isEmpty())
    <p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">
        No map images yet. Use <strong>Upload Map Images</strong> in the section header — the first upload becomes the boundary map.
    </p>
@endif
