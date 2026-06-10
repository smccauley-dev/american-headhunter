<x-filament-panels::page>
    <form wire:submit="save">

        {{-- One connected box: toolbar + IP list section (section border stripped) --}}
        <div class="fi-ta-ctn" style="display:flex;flex-direction:column;">
            <div class="fi-ta-header-ctn">
                <div class="fi-ta-header-toolbar" style="display:flex;align-items:center;margin-left:8px;margin-right:8px;">
                    <div class="fi-ta-actions fi-align-start fi-wrapped" style="flex:none;">
                        <button
                            type="button"
                            wire:click="addEntry"
                            class="fi-btn fi-btn-size-sm fi-color-gray fi-ac-btn-action fi-color"
                        >
                            <svg class="fi-btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" style="width:1em;height:1em;flex-shrink:0">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            <span>ADD IP / CIDR RANGE</span>
                        </button>
                    </div>
                </div>
            </div>
            {{ $this->ipForm }}
        </div>

        {{-- Emergency Bypass — same fi-ta-ctn structure --}}
        <div class="fi-ta-ctn" style="display:flex;flex-direction:column;margin-top:1.5rem;">
            <div class="fi-ta-header-ctn">
                <div class="fi-ta-header-toolbar" style="display:flex;align-items:center;margin-left:8px;margin-right:8px;">
                    <div class="fi-ta-actions fi-align-start fi-wrapped" style="flex:none;">
                        <button
                            type="button"
                            wire:click="addBypassEntry"
                            class="fi-btn fi-btn-size-sm fi-color-gray fi-ac-btn-action fi-color"
                        >
                            <svg class="fi-btn-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" style="width:1em;height:1em;flex-shrink:0">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            <span>ADD BYPASS IP</span>
                        </button>
                    </div>
                </div>
            </div>
            {{ $this->bypassForm }}
        </div>

        {{-- Save --}}
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
