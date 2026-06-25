<x-filament-panels::page>
    {{-- Standard underline tab bar (.fi-tabs chrome — see AdminPanelProvider CSS),
         driven by the $activeTab Livewire property. --}}
    <nav class="fi-tabs" role="tablist">
        @php($tabIcons = $this->tabIcons())
        @foreach ($this->tabs() as $key => $label)
            <button
                type="button"
                role="tab"
                wire:click="$set('activeTab', '{{ $key }}')"
                wire:loading.attr="disabled"
                @class(['fi-tabs-item', 'fi-active' => $activeTab === $key])
            >
                @if ($icon = ($tabIcons[$key] ?? null))
                    {!! \Filament\Support\generate_icon_html($icon)?->toHtml() !!}
                @endif
                <span class="fi-tabs-item-label">{{ $label }}</span>
            </button>
        @endforeach
    </nav>

    {{-- Action toolbar — a header-only section (standard Field-Record chrome,
         same pattern as the Audit Log section): heading + status on the left,
         action buttons on the right. The afterHeader slot holds the buttons; the
         empty body means Filament renders no content area. Grows to take more
         buttons like Export Data. --}}
    @if ($activeTab === 'analytics')
        <x-filament::section class="ah-analytics-toolbar">
            <x-slot name="heading">Platform Analytics</x-slot>
            <x-slot name="description">Platform health across users, properties, leases and revenue. {{ $this->capturedAtLabel() }}</x-slot>
            <x-slot name="afterHeader">
                {{ $this->refreshAction }}
            </x-slot>
        </x-filament::section>
    @endif

    @php($widgets = $this->getVisibleWidgets())

    @if (filled($widgets))
        <x-filament-widgets::widgets
            :widgets="$widgets"
            :columns="$this->getColumns()"
            wire:key="dashboard-widgets-{{ $activeTab }}"
        />
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $this->tabs()[$activeTab] ?? 'Coming soon' }}</x-slot>

            <p class="fi-tabs-placeholder">
                This tab is a placeholder — we’ll build it out next.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
