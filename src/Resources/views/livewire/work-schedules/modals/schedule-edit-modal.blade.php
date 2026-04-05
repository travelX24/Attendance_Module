{{-- app/Modules/Attendance/Resources/views/livewire/work-schedules/modals/schedule-edit-modal.blade.php --}}

<x-ui.modal wire:model="showScheduleEditModal" max-width="lg">
    <x-slot name="title">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-pen"></i>
            </div>
            <h3 class="font-bold text-gray-900 text-lg leading-tight">
                {{ tr('Edit Schedule') }}
            </h3>
        </div>
    </x-slot>

    <x-slot name="content">
        <div class="space-y-4">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Schedule') }}</label>
                <x-ui.select wire:model="editScheduleForm.work_schedule_id" class="w-full" align="down" :disabled="!auth()->user()->can('attendance.manage')">
                    <option value="">{{ tr('Select a schedule...') }}</option>
                    @foreach(($workSchedulesAll ?? []) as $sch)
                        <option value="{{ $sch->id }}">
                            {{ $sch->name }}{{ $sch->is_active ? '' : ' (' . tr('Disabled') . ')' }}
                        </option>
                    @endforeach
                </x-ui.select>
                @error('editScheduleForm.work_schedule_id')
                    <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                @enderror
            </div>

            @php
                $isDefault = !empty($editScheduleForm['is_default']);
                $isPermanent = !empty($editScheduleForm['is_permanent']);
                $showEndDate = !$isDefault && !$isPermanent;
            @endphp

            <div class="{{ $showEndDate ? 'grid grid-cols-2 gap-4' : 'grid grid-cols-1' }}">
                <div>
                    <x-ui.company-date-picker 
                        model="editScheduleForm.start_date" 
                        :label="tr('Start Date')" 
                        :disabled="!auth()->user()->can('attendance.manage')" 
                    />
                </div>
                
                @if($showEndDate)
                    <x-ui.company-date-picker 
                        model="editScheduleForm.end_date" 
                        :label="tr('End Date')" 
                        :disabled="!auth()->user()->can('attendance.manage')" 
                    />
                @endif
            </div>

            <p class="text-xs text-gray-500">
                {{ tr('Note: If the edited range includes today, it will become the active schedule automatically.') }}
            </p>
        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end gap-3 w-full">
            <x-ui.secondary-button wire:click="$set('showScheduleEditModal', false)">
                {{ tr('Cancel') }}
            </x-ui.secondary-button>

            @can('attendance.manage')
            <x-ui.primary-button wire:click="saveScheduleEdit" wire:loading.attr="disabled" class="gap-2">
                <i class="fas fa-save" wire:loading.remove></i>
                <i class="fas fa-spinner fa-spin" wire:loading></i>
                <span>{{ tr('Save') }}</span>
            </x-ui.primary-button>
            @endcan
        </div>
    </x-slot>
</x-ui.modal>
