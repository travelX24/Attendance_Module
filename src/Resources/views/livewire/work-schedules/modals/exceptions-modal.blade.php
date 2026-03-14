{{-- Employee Exceptions Modal (UI Improved) --}}
<x-ui.modal
    wire:model="showExceptionsModal"
    max-width="4xl"
>
    <x-slot name="title">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-user-clock"></i>
            </div>
            <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Employee Exceptions Management') }}</h3>
        </div>
    </x-slot>
    <x-slot name="content">
        @php
            $initial = $exceptionsEmployeeName ? mb_substr($exceptionsEmployeeName, 0, 1) : '—';
        @endphp

        <div class="space-y-6">

            {{-- Header Card --}}
            <x-ui.card class="!p-0 overflow-hidden">
                <div class="p-4 sm:p-5 bg-gradient-to-r from-[color:var(--brand-from)]/10 via-[color:var(--brand-via)]/10 to-[color:var(--brand-to)]/10 border-b border-gray-100">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-[color:var(--brand-via)]/15 text-[color:var(--brand-via)] flex items-center justify-center font-extrabold">
                                {{ $initial }}
                            </div>
                            <div>
                                <div class="text-sm font-bold text-gray-900">
                                    {{ tr('Employee') }}: {{ $exceptionsEmployeeName ?: '—' }}
                                </div>
                                <div class="text-xs text-gray-600 flex items-center gap-2">
                                    <i class="fas fa-user-clock text-[color:var(--brand-via)]"></i>
                                    <span>{{ tr('Manage individual exceptions for a specific date') }}</span>
                                </div>
                            </div>
                        </div>

                        <x-ui.badge type="info" class="shrink-0">
                            <i class="fas fa-shield-alt me-1"></i>
                            {{ tr('Attendance') }}
                        </x-ui.badge>
                    </div>
                </div>

                <div class="flex flex-col gap-4">
            {{-- Info Alert --}}
            <div class="p-3 bg-indigo-50 border border-indigo-100 rounded-xl flex items-center gap-3 text-indigo-700 text-sm">
                <i class="fas fa-info-circle"></i>
                <p>{{ tr('Selected changes will be applied to') }} <strong>1 {{ tr('Employee') }}</strong></p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <x-ui.company-date-picker
                            model="exceptionForm.exception_date"
                            :label="tr('Date')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />

                        <x-ui.select
                            wire:model.live="exceptionForm.exception_type"
                            :label="tr('Exception Type')"
                            error="exceptionForm.exception_type"
                            openUpward="true"
                            required
                            :disabled="!auth()->user()->can('attendance.manage')"
                        >
                            <option value="time_override">{{ tr('Time Override') }}</option>
                            <option value="day_off">{{ tr('Exceptional Day Off') }}</option>
                            <option value="work_day">{{ tr('Exceptional Work Day') }}</option>
                        </x-ui.select>
                        @if(in_array(($exceptionForm['exception_type'] ?? ''), ['time_override','work_day'], true))
                            <x-ui.input
                                type="time"
                                wire:model="exceptionForm.start_time"
                                :label="tr('Start Time')"
                                error="exceptionForm.start_time"
                                required
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />

                            <x-ui.input
                                type="time"
                                wire:model="exceptionForm.end_time"
                                :label="tr('End Time')"
                                error="exceptionForm.end_time"
                                required
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />

                            {{-- ✅ Breaks removed --}}
                        @else
                            <div class="md:col-span-2">
                                <div class="flex items-center gap-3 p-3 rounded-xl border border-gray-100 bg-gray-50">
                                    <i class="fas fa-info-circle text-gray-500"></i>
                                    <p class="text-xs text-gray-600">
                                        {{ tr('This exception type does not require start/end time.') }}
                                    </p>
                                </div>
                            </div>
                        @endif


                        <div class="md:col-span-2">
                            <x-ui.textarea
                                rows="3"
                                wire:model="exceptionForm.notes"
                                :label="tr('Notes')"
                                error="exceptionForm.notes"
                                :hint="tr('Optional: add a short reason or note for audit purposes')"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-3 mt-5">
                        @can('attendance.manage')
                        @if($exceptionEditId)
                            <x-ui.secondary-button wire:click="resetExceptionForm">
                                <i class="fas fa-times me-1"></i>
                                {{ tr('Cancel Edit') }}
                            </x-ui.secondary-button>

                            <x-ui.primary-button
                                wire:click="saveException"
                                wire:loading.attr="disabled"
                                class="gap-2"
                            >
                                <i class="fas fa-save" wire:loading.remove wire:target="saveException"></i>
                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="saveException"></i>
                                <span>{{ tr('Update') }}</span>
                            </x-ui.primary-button>
                        @else
                            <x-ui.primary-button
                                wire:click="saveException"
                                wire:loading.attr="disabled"
                                class="gap-2"
                            >
                                <i class="fas fa-check" wire:loading.remove wire:target="saveException"></i>
                                <i class="fas fa-spinner fa-spin" wire:loading wire:target="saveException"></i>
                                <span>{{ tr('Save') }}</span>
                            </x-ui.primary-button>
                        @endif
                        @endcan
                    </div>
                </div>
            </x-ui.card>

            {{-- Existing Exceptions --}}
            <x-ui.card padding="false" class="overflow-hidden">
                <div class="p-4 border-b border-gray-100 bg-gray-50/30 flex items-center justify-between gap-3">
                    <div class="text-sm font-bold text-gray-900">
                        {{ tr('Existing Exceptions') }}
                    </div>

                    <x-ui.badge type="default">
                        {{ is_array($exceptionsList) ? count($exceptionsList) : (method_exists($exceptionsList,'count') ? $exceptionsList->count() : 0) }}
                    </x-ui.badge>
                </div>

                <div class="p-4">
                    <x-ui.table
                        :headers="[tr('Date'), tr('Type'), tr('Time'), tr('Notes'), tr('Actions')]"
                        :enablePagination="false"
                        :headerAlign="['start','start','start','start','end']"
                    >
                        @forelse($exceptionsList as $ex)
                            @php
                                $t = $ex['exception_type'] ?? '';
                                $typeLabel = $t === 'time_override'
                                    ? tr('Time Override')
                                    : ($t === 'day_off' ? tr('Exceptional Day Off') : tr('Exceptional Work Day'));

                                $badgeType = $t === 'time_override'
                                    ? 'info'
                                    : ($t === 'day_off' ? 'danger' : 'success');

                                $start = !empty($ex['start_time']) ? substr((string) $ex['start_time'], 0, 5) : '—';
                                $end   = !empty($ex['end_time'])   ? substr((string) $ex['end_time'], 0, 5)   : '—';

                                $timeText = in_array($t, ['time_override','work_day'], true)
                                    ? ($start . ' - ' . $end)
                                    : '—';


                            @endphp

                            <tr class="border-b border-gray-50">
                                @php
                                    $dateDisplay = company_date($ex['exception_date'] ?? null) ?: '—';
                                @endphp

                                <td class="py-3 px-6 text-sm text-gray-700">{{ $dateDisplay }}</td>


                                <td class="py-3 px-6">
                                    <x-ui.badge :type="$badgeType">{{ $typeLabel }}</x-ui.badge>
                                </td>

                                <td class="py-3 px-6 text-sm text-gray-700">
                                    <span dir="ltr" class="inline-block">{{ $timeText }}</span>
                                </td>

                                <td class="py-3 px-6 text-sm text-gray-600">
                                    <span class="line-clamp-2">{{ $ex['notes'] ?? '' }}</span>
                                 </td>



                                <td class="py-3 px-6">
                                    <div class="flex justify-end">
                                        <x-ui.actions-menu>
                                            @can('attendance.manage')
                                            <x-ui.dropdown-item wire:click="editException({{ $ex['id'] }})">
                                                <i class="fas fa-pen me-2 text-gray-500"></i>
                                                <span>{{ tr('Edit') }}</span>
                                            </x-ui.dropdown-item>

                                            <x-ui.dropdown-item
                                                danger
                                                x-on:click.prevent="window.dispatchEvent(new CustomEvent('open-confirm-delete-employee-exception', { detail: { id: {{ (int) $ex['id'] }} } }))"
                                            >
                                                <i class="fas fa-trash me-2"></i>
                                                <span>{{ tr('Delete') }}</span>
                                            </x-ui.dropdown-item>
                                            @else
                                                <span class="text-xs text-gray-400 italic">{{ tr('No Access') }}</span>
                                            @endcan
                                        </x-ui.actions-menu>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-10 px-6 text-center text-gray-500 italic">
                                    {{ tr('No exceptions found') }}
                                </td>
                            </tr>
                        @endforelse
                    </x-ui.table>
                </div>
            </x-ui.card>

        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end gap-3 w-full">
            <x-ui.secondary-button wire:click="$set('showExceptionsModal', false)">
                {{ tr('Close') }}
            </x-ui.secondary-button>
        </div>
    </x-slot>
</x-ui.modal>
