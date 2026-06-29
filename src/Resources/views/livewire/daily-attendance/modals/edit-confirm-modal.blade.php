{{-- Approved Edit Confirm Modal --}}
    <x-ui.modal wire:model="showApprovedEditConfirmModal" max-width="md">
        <x-slot name="title">{{ tr('Confirm Edit Approved Attendance') }}</x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div class="p-3 rounded-xl border border-[color:var(--error)]/25 bg-[color:var(--error)]/10 flex items-start gap-2">
                    <i class="fas fa-lock text-[color:var(--error)] mt-0.5"></i>
                    <div class="text-xs text-[color:var(--text-primary)] font-medium">
                        {{ tr('This attendance is already approved. Editing it will move it back to Pending and requires re-approval.') }}
                    </div>
                </div>

                <x-ui.input
                    wire:model="approvedEditConfirmText"
                    :label="tr('Type CONFIRM to proceed')"
                    error="approvedEditConfirmText"
                    :disabled="!$canManageDaily"
                />

                <label class="flex items-center gap-2 text-xs text-gray-700">
                    <input type="checkbox" wire:model="approvedEditConfirmUnderstood" class="text-[color:var(--accent-orange)] rounded border-gray-300 focus:ring-[color:var(--accent-orange)]" @if(!$canManageDaily) disabled @endif>
                    <span>{{ tr('I understand this will reopen the record for re-approval') }}</span>
                </label>
                @error('approvedEditConfirmUnderstood') <div class="text-xs text-[color:var(--error)]">{{ $message }}</div> @enderror
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-2 w-full">
                <x-ui.secondary-button wire:click="$set('showApprovedEditConfirmModal', false)">
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                @if($canManageDaily)
                <x-ui.primary-button wire:click="confirmEditApproved" class="gap-2">
                    <i class="fas fa-check"></i>
                    <span>{{ tr('Confirm') }}</span>
                </x-ui.primary-button>
                @endif
            </div>
        </x-slot>
    </x-ui.modal>
