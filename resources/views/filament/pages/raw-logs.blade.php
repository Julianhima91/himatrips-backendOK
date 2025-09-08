<x-filament::page>
    {{ $this->table }}

    @if($showLogModal && $selectedLog)
        <x-filament::modal id="log-modal" :show="$showLogModal" width="7xl">
            <x-slot name="header">
                <h2>{{ $selectedLog['file'] }}</h2>
            </x-slot>

            <div class="p-4">
                <pre class="bg-gray-100 p-4 rounded overflow-x-auto text-sm whitespace-pre-wrap">{{ $selectedLog['content'] }}</pre>
            </div>

            <x-slot name="footer">
                <x-filament::button wire:click="$set('showLogModal', false)">
                    Close
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    @endif
</x-filament::page>
