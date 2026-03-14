{{-- Reject Modal --}}
    <x-ui.modal wire:model="showRejectModal" max-width="md">
        <x-slot name="title">{{ tr('Reject Attendance') }}</x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <div class="flex items-center gap-3 p-3 bg-red-50 rounded-xl border border-red-100">
                    <i class="fas fa-times-circle text-red-600"></i>
                    <p class="text-xs text-red-900 font-medium">
                        {{ tr('Rejecting means this record will not be used for payroll until reopened and approved.') }}
                    </p>
                </div>

                <x-ui.textarea wire:model="rejectNotes" :label="tr('Rejection Reason')" rows="4" :disabled="!auth()->user()->can('attendance.manage')" />
                @error('rejectNotes') <div class="text-xs text-red-600">{{ $message }}</div> @enderror

                <div class="flex items-center justify-end gap-3 w-full pt-4 border-t border-gray-100 mt-4">
                    <x-ui.secondary-button wire:click="$set('showRejectModal', false)">{{ tr('Cancel') }}</x-ui.secondary-button>
                    @can('attendance.manage')
                    <x-ui.primary-button wire:click="rejectSingle" class="gap-2">
                        <i class="fas fa-times"></i>
                        <span>{{ tr('Reject') }}</span>
                    </x-ui.primary-button>
                    @endcan
                </div>
            </div>
        </x-slot>
    </x-ui.modal>
