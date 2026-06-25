<x-filament-panels::page>
    {{-- Standard underline tab bar (.fi-tabs chrome — see AdminPanelProvider CSS),
         driven by the $activeTab Livewire property. --}}
    <nav class="fi-tabs" role="tablist">
        @foreach ($this->tabs() as $key => $label)
            <button
                type="button"
                role="tab"
                wire:click="$set('activeTab', '{{ $key }}')"
                wire:loading.attr="disabled"
                @class(['fi-tabs-item', 'fi-active' => $activeTab === $key])
            >
                <span class="fi-tabs-item-label">{{ $label }}</span>
            </button>
        @endforeach
    </nav>

    {{-- Analytics toolbar: status text on the left, actions on the right. Sits
         below the tab bar so it can grow to hold buttons like Export Data. --}}
    @if ($activeTab === 'analytics')
        <div class="ah-toolbar">
            <span class="ah-toolbar-status">{{ $this->capturedAtLabel() }}</span>

            <div class="ah-toolbar-actions">
                {{ $this->refreshAction }}
            </div>
        </div>
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
