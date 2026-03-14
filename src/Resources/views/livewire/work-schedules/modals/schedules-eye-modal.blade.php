{{-- app/Modules/Attendance/Resources/views/livewire/work-schedules/modals/schedules-eye-modal.blade.php --}}

<x-ui.modal wire:model="showScheduleEyeModal" max-width="xl">
    <x-slot name="title">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-eye"></i>
            </div>

            <div>
                <h3 class="font-bold text-gray-900 text-lg leading-tight">
                    {{ tr('All Schedules') }}
                </h3>
                <p class="text-xs text-gray-500">
                    {{ $scheduleEyeEmployeeName ? $scheduleEyeEmployeeName : '-' }}
                </p>
            </div>
        </div>
    </x-slot>

    <x-slot name="content">
        <div class="space-y-3">

            @if(empty($scheduleEyeRows))
                <div class="p-6 text-center text-sm text-gray-500 italic">
                    {{ tr('No schedules found.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 border-b">
                                <th class="py-2 pe-3">{{ tr('Schedule') }}</th>
                                <th class="py-2 pe-3">{{ tr('Start') }}</th>
                                <th class="py-2 pe-3">{{ tr('End') }}</th>
                                <th class="py-2 pe-3">{{ tr('Status') }}</th>
                                <th class="py-2 text-right">{{ tr('Action') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y">
                            @foreach($scheduleEyeRows as $r)
                                @php
                                    $status = $r['status'] ?? 'inactive';
                                    $badgeType =
                                        $status === 'active' ? 'success' :
                                        ($status === 'future' ? 'info' :
                                        ($status === 'past' ? 'gray' :
                                        ($status === 'range' ? 'warning' : 'gray')));
                                @endphp

                                <tr class="hover:bg-gray-50/50">
                                    <td class="py-2 pe-3 font-semibold text-gray-900">
                                        {{ $r['schedule_name'] ?? '-' }}
                                        @if(!empty($r['assignment_type']))
                                            <span class="text-[11px] text-gray-400 ms-2">
                                                ({{ $r['assignment_type'] }})
                                            </span>
                                        @endif
                                    </td>

                                    <td class="py-2 pe-3 font-mono text-gray-700">
                                        {{ $r['start_date'] ?? '-' }}
                                    </td>

                                    <td class="py-2 pe-3 font-mono text-gray-700">
                                        {{ !empty($r['end_date']) ? $r['end_date'] : tr('Permanent') }}
                                    </td>

                                    <td class="py-2 pe-3">
                                        <x-ui.badge :type="$badgeType" size="xs">
                                            {{ $status === 'active' ? tr('Active') :
                                               ($status === 'future' ? tr('Future') :
                                               ($status === 'past' ? tr('Expired') :
                                               ($status === 'range' ? tr('In Range') : tr('Inactive')))) }}
                                        </x-ui.badge>
                                    </td>

                                    <td class="py-2 text-right">
                                        @can('attendance.manage')
                                            @if(!empty($r['can_edit']))
                                                <button
                                                    type="button"
                                                    wire:click="openScheduleEditModal({{ (int) $r['id'] }})"
                                                    class="text-xs font-bold text-[color:var(--brand-via)] hover:text-[color:var(--brand-from)]"
                                                >
                                                    {{ tr('Edit') }}
                                                </button>
                                            @else
                                                <span class="text-xs text-gray-400 italic">
                                                    {{ tr('View only') }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400 italic">
                                                <i class="fas fa-lock text-[10px] me-1"></i>
                                                {{ tr('Locked') }}
                                            </span>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                    </table>
                </div>
            @endif

        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end gap-3 w-full">
            <x-ui.secondary-button wire:click="$set('showScheduleEyeModal', false)">
                {{ tr('Close') }}
            </x-ui.secondary-button>
        </div>
    </x-slot>
</x-ui.modal>
