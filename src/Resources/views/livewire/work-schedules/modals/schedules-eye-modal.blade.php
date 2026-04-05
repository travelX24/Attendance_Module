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
                <x-ui.table
                    :headers="[tr('Schedule'), tr('Start'), tr('End'), tr('Period'), tr('Status'), tr('Action')]"
                    :enablePagination="false"
                    :headerAlign="['start','start','start','start','start','end']"
                >
                    @foreach($scheduleEyeRows as $r)
                        @php
                            $status = $r['status'] ?? 'inactive';
                            $badgeType =
                                $status === 'active' ? 'success' :
                                ($status === 'future' ? 'info' :
                                ($status === 'past' ? 'gray' :
                                ($status === 'range' ? 'warning' : 'gray')));
                        @endphp

                        <tr class="hover:bg-gray-50/50 border-b border-gray-50 last:border-0 transition-colors">
                            <td class="py-3 px-6 font-semibold text-gray-900 text-sm">
                                {{ $r['schedule_name'] ?? '-' }}
                                @if(!empty($r['assignment_type']))
                                    <span class="text-[11px] text-gray-400 font-normal">
                                        ({{ $r['assignment_type'] }})
                                    </span>
                                @endif
                            </td>

                            <td class="py-3 px-6 font-mono text-gray-700 text-sm">
                                {{ $row['start_date'] ?? $r['start_date'] ?? '-' }}
                            </td>

                            <td class="py-3 px-6 font-mono text-gray-700 text-sm">
                                {{ !empty($r['end_date']) ? $r['end_date'] : tr('Permanent') }}
                            </td>
                            <td class="py-3 px-6">
                                @php
                                    $isPerm = empty($r['end_date']) || $r['end_date'] === '-';
                                @endphp
                                <x-ui.badge :type="$isPerm ? 'success' : 'info'" size="xs" outline>
                                    {{ $isPerm ? tr('Permanent') : tr('Limited Duration') }}
                                </x-ui.badge>
                            </td>
                            <td class="py-3 px-6">
                                <x-ui.badge :type="$badgeType" size="xs">
                                    {{ $status === 'active' ? tr('Active') :
                                       ($status === 'future' ? tr('Future') :
                                       ($status === 'past' ? tr('Expired') :
                                       ($status === 'range' ? tr('In Range') : tr('Inactive')))) }}
                                </x-ui.badge>
                            </td>

                            <td class="py-3 px-6 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @can('attendance.manage')
                                        @if(!empty($r['can_edit']))
                                            <button
                                                type="button"
                                                wire:click="openScheduleEditModal({{ (int) $r['id'] }})"
                                                class="text-xs font-bold text-[color:var(--brand-via)] hover:text-[color:var(--brand-from)] transition-colors"
                                                title="{{ tr('Edit') }}"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        @endif

                                        @if(!empty($r['can_delete']))
                                            <button
                                                type="button"
                                                wire:click="confirmDeleteAssignment({{ (int) $r['id'] }})"
                                                class="text-xs font-bold text-red-500 hover:text-red-700 transition-colors"
                                                title="{{ tr('Delete') }}"
                                            >
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        @elseif(!empty($r['is_default']))
                                            <span class="text-gray-300" title="{{ tr('Base schedule cannot be deleted') }}">
                                                <i class="fas fa-trash-alt opacity-30 cursor-not-allowed"></i>
                                            </span>
                                        @endif

                                        @if(empty($r['can_edit']) && empty($r['can_delete']))
                                            <span class="text-[11px] text-gray-400 font-bold flex items-center gap-1 justify-end italic whitespace-nowrap">
                                                <i class="fas fa-lock text-[10px]"></i>
                                                {{ !empty($r['is_default']) ? tr('Base Schedule') : tr('Locked') }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-[11px] text-gray-400 italic">
                                            <i class="fas fa-eye text-[10px] me-1"></i>
                                            {{ tr('View only') }}
                                        </span>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
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

    {{-- Delete Confirmation --}}
    <x-ui.confirm-dialog
        id="assignment-delete"
        confirm-action="wire:deleteAssignment"
        :title="tr('Delete Schedule Assignment')"
        :message="tr('Are you sure you want to delete this schedule assignment? This action cannot be undone and may affect attendance calculations.')"
        type="danger"
        confirm-text="{{ tr('Delete') }}"
    />
</x-ui.modal>
