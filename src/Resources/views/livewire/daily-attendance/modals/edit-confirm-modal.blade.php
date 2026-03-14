{{-- Approved Edit Confirm Modal --}}
    <x-ui.modal wire:model="showApprovedEditConfirmModal" max-width="md">
        <x-slot name="title">{{ tr('Confirm Edit Approved Attendance') }}</x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div class="p-3 rounded-xl border border-red-100 bg-red-50 flex items-start gap-2">
                    <i class="fas fa-lock text-red-600 mt-0.5"></i>
                    <div class="text-xs text-red-900 font-medium">
                        {{ tr('This attendance is already approved. Editing it will move it back to Pending and requires re-approval.') }}
                    </div>
                </div>

                <x-ui.input
                    wire:model="approvedEditConfirmText"
                    :label="tr('Type CONFIRM to proceed')"
                    error="approvedEditConfirmText"
                    :disabled="!auth()->user()->can('attendance.manage')"
                />

                <label class="flex items-center gap-2 text-xs text-gray-700">
                    <input type="checkbox" wire:model="approvedEditConfirmUnderstood" class="rounded border-gray-300" @cannot('attendance.manage') disabled @endcannot>
                    <span>{{ tr('I understand this will reopen the record for re-approval') }}</span>
                </label>
                @error('approvedEditConfirmUnderstood') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-2 w-full">
                <x-ui.secondary-button wire:click="$set('showApprovedEditConfirmModal', false)">
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                @can('attendance.manage')
                <x-ui.primary-button wire:click="confirmEditApproved" class="gap-2">
                    <i class="fas fa-check"></i>
                    <span>{{ tr('Confirm') }}</span>
                </x-ui.primary-button>
                @endcan
            </div>
        </x-slot>
    </x-ui.modal>
