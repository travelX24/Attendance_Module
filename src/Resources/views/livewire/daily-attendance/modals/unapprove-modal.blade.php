{{-- Unapprove/Reopen Modal --}}
    <x-ui.modal wire:model="showUnapproveModal" max-width="md">
        <x-slot name="title">{{ tr('Reopen Record') }}</x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <div class="flex items-center gap-3 p-3 bg-yellow-50 rounded-xl border border-yellow-100">
                    <i class="fas fa-exclamation-triangle text-yellow-600"></i>
                    <p class="text-xs text-yellow-900 font-medium">
                        {{ tr('This will move the record back to Pending. You must provide a reason.') }}
                    </p>
                </div>

                <x-ui.textarea wire:model="unapproveReason" :label="tr('Reason')" rows="4" :disabled="!auth()->user()->can('attendance.manage')" />
                @error('unapproveReason') <div class="text-xs text-red-600">{{ $message }}</div> @enderror

                <label class="flex items-center gap-2 text-xs text-gray-700">
                    <input type="checkbox" wire:model="unapproveUnderstood" @cannot('attendance.manage') disabled @endcannot>
                    <span>{{ tr('I understand this will require re-approval for payroll') }}</span>
                </label>
                @error('unapproveUnderstood') <div class="text-xs text-red-600">{{ $message }}</div> @enderror

                <div class="flex items-center justify-end gap-3 w-full pt-4 border-t border-gray-100 mt-4">
                    <x-ui.secondary-button wire:click="$set('showUnapproveModal', false)">{{ tr('Cancel') }}</x-ui.secondary-button>
                    @can('attendance.manage')
                    <x-ui.primary-button wire:click="unapproveSingle" class="gap-2">
                        <i class="fas fa-undo"></i>
                        <span>{{ tr('Reopen') }}</span>
                    </x-ui.primary-button>
                    @endcan
                </div>
            </div>
        </x-slot>
    </x-ui.modal>
