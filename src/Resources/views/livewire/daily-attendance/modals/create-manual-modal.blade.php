{{-- Create Manual Attendance Modal --}}
    <x-ui.modal wire:model="showCreateModal" max-width="lg">
        <x-slot name="title">{{ tr('Add Manual Attendance') }}</x-slot>
        <x-slot name="content">
            <div class="space-y-5">
                <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-xl border border-blue-100">
                    <i class="fas fa-info-circle text-blue-600"></i>
                    <p class="text-xs text-blue-900 font-medium">
                        {{ tr('This will create a manual attendance record that requires approval before payroll processing.') }}
                    </p>
                </div>

                <x-ui.select
                    wire:model="createForm.employee_id"
                    :label="tr('Employee')"
                    error="createForm.employee_id"
                    required
                    :disabled="!auth()->user()->can('attendance.manage')"
                >
                    <option value="">{{ tr('Select employee...') }}</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">
                            {{ $employee->name_ar ?: $employee->name_en }} - #{{ $employee->employee_no }}
                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.company-date-picker
                    model="createForm.attendance_date"
                    :label="tr('Attendance Date')"
                    :disabled="!auth()->user()->can('attendance.manage')"
                />

                {{-- Dynamic Periods Section --}}
                <div class="space-y-4">
                    @foreach($createForm['periods'] as $index => $period)
                        <div class="p-4 bg-gray-50/50 rounded-xl border border-gray-100 space-y-3">
                            @if(count($createForm['periods']) > 1)
                                <div class="flex items-center gap-2 text-xs font-bold text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-clock text-blue-500"></i>
                                    <span>{{ tr('Period') }} #{{ $index + 1 }} {{ isset($period['label']) ? '(' . $period['label'] . ')' : '' }}</span>
                                </div>
                            @endif
                            <div class="grid grid-cols-2 gap-4">
                                <x-ui.input
                                    type="time"
                                    wire:model="createForm.periods.{{ $index }}.check_in_time"
                                    :label="tr('Check In Time')"
                                    error="createForm.periods.{{ $index }}.check_in_time"
                                    required
                                    :disabled="!auth()->user()->can('attendance.manage')"
                                />

                                <x-ui.input
                                    type="time"
                                    wire:model="createForm.periods.{{ $index }}.check_out_time"
                                    :label="tr('Check Out Time')"
                                    error="createForm.periods.{{ $index }}.check_out_time"
                                    :disabled="!auth()->user()->can('attendance.manage')"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.textarea
                    wire:model="createForm.notes"
                    :label="tr('Notes')"
                    :hint="tr('Optional: Add any notes or reason for manual entry')"
                    rows="3"
                    error="createForm.notes"
                    :disabled="!auth()->user()->can('attendance.manage')"
                />
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3 w-full">
                <x-ui.secondary-button wire:click="$set('showCreateModal', false)">
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>
                @can('attendance.manage')
                <x-ui.primary-button wire:click="saveManualAttendance" wire:loading.attr="disabled" class="gap-2">
                    <i class="fas fa-save" wire:loading.remove></i>
                    <i class="fas fa-spinner fa-spin" wire:loading></i>
                    <span>{{ tr('Create Attendance') }}</span>
                </x-ui.primary-button>
                @endcan
            </div>
        </x-slot>
    </x-ui.modal>
