<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 2rem;" class="fi-sc-actions">
            <div class="fi-ac">
                <x-filament::button type="submit" icon="heroicon-o-check-circle">
                    Save Changes
                </x-filament::button>
            </div>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
