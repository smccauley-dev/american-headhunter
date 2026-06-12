<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 2rem;" class="fi-sc-actions">
            <div class="fi-ac" style="display: flex; gap: 0.75rem;">
                <x-filament::button type="submit" icon="heroicon-o-check-circle">
                    Save Changes
                </x-filament::button>
                <x-filament::button type="button" color="gray" icon="heroicon-o-paper-airplane" wire:click="mountAction('testEmail')">
                    Send Test Email
                </x-filament::button>
            </div>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
