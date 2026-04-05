{{-- History Modal --}}
<x-ui.modal
    wire:model="showHistoryModal"
    max-width="3xl"
>
    <x-slot name="title">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-history"></i>
            </div>
            <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Schedule Assignment History') }}</h3>
        </div>
    </x-slot>

    <x-slot name="content">
        <div class="space-y-6">
            {{-- Header Summary --}}
            <x-ui.card class="!p-4 bg-gray-50 border-gray-100 shadow-none">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-white text-gray-400 flex items-center justify-center border border-gray-200 shadow-sm">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wider font-semibold">{{ tr('Employee') }}</p>
                            <p class="text-sm font-bold text-gray-900">{{ $historyEmployeeName }}</p>
                        </div>
                    </div>
                    <div class="text-end">
                        <p class="text-xs text-gray-500 uppercase tracking-wider font-semibold">{{ tr('Records') }}</p>
                        <x-ui.badge type="default" class="mt-1">{{ count($historyList) }}</x-ui.badge>
                    </div>
                </div>
            </x-ui.card>

            <div class="relative pl-4 sm:pl-6">
                {{-- Timeline Line --}}
                <div class="absolute top-0 bottom-0 {{ app()->isLocale('ar') ? 'right-0 border-r-2' : 'left-0 border-l-2' }} border-gray-100 border-dashed"></div>
                
                <div class="space-y-6">
                    @forelse($historyList as $log)
                        <div class="relative group">
                            {{-- Timeline Dot --}}
                            <div class="absolute top-4 {{ app()->isLocale('ar') ? '-right-[9px]' : '-left-[9px]' }} w-4 h-4 rounded-full bg-white border-4 border-[color:var(--brand-via)] box-content shadow-sm z-10 group-hover:scale-110 transition-transform"></div>

                            <x-ui.card class="ms-4 !p-0 overflow-hidden hover:shadow-md transition-all duration-300 border-gray-200">
                                <div class="p-4 border-b border-gray-50 bg-gray-50/50 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-2">
                                        @php
                                            $actionMap = [
                                                'work_schedule.assigned' => ['label' => tr('Assigned'), 'type' => 'success', 'icon' => 'fa-plus-circle'],
                                                'work_schedule.changed'  => ['label' => tr('Changed'),  'type' => 'info',    'icon' => 'fa-edit'],
                                                'work_schedule.deleted'  => ['label' => tr('Deleted'),  'type' => 'danger',  'icon' => 'fa-trash-alt'],
                                                'work_schedule.edited'   => ['label' => tr('Modified'), 'type' => 'info',    'icon' => 'fa-pencil-alt'],
                                                'work_schedule.bulk_assigned' => ['label' => tr('Bulk Assigned'), 'type' => 'success', 'icon' => 'fa-users'],
                                            ];
                                            $act = $actionMap[$log->action] ?? ['label' => $log->action, 'type' => 'default', 'icon' => 'fa-info-circle'];
                                        @endphp
                                        <x-ui.badge :type="$act['type']" size="sm">
                                            <i class="fas {{ $act['icon'] }} mr-1.5 opacity-80"></i> {{ $act['label'] }}
                                        </x-ui.badge>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-[10px] font-mono text-gray-400 bg-white px-2 py-1 rounded border border-gray-100 shadow-sm" title="{{ tr('Exact Timestamp') }}">
                                            {{ $log->created_at->format('Y-m-d H:i') }}
                                        </span>
                                        <span class="text-xs text-gray-600 font-bold flex items-center bg-white px-2 py-1 rounded-md border border-gray-100 shadow-sm min-w-[120px] justify-center">
                                            <i class="far fa-calendar-alt me-1.5 text-blue-400"></i>
                                            {{ company_date($log->created_at) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="p-4 bg-white">
                                    @php
                                        $after = $log->after_json;
                                        $before = $log->before_json;
                                        
                                        // If deleted, use 'before' data for info
                                        $data = ($log->action === 'work_schedule.deleted') ? $before : $after;
                                        
                                        $scheduleId = $data['work_schedule_id'] ?? 0;
                                        $schedule = \Athka\SystemSettings\Models\WorkSchedule::find($scheduleId);
                                        $schName = $schedule ? $schedule->name : ($scheduleId ? tr('Schedule') . ' #' . $scheduleId : tr('N/A'));
                                        
                                        $startDate = $data['start_date'] ?? null;
                                        $endDate = $data['end_date'] ?? null;
                                        $isDeleted = ($log->action === 'work_schedule.deleted');
                                    @endphp

                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 rounded-xl {{ $isDeleted ? 'bg-red-50 text-red-500' : 'bg-indigo-50 text-indigo-600' }} flex items-center justify-center text-lg shrink-0 border {{ $isDeleted ? 'border-red-100' : 'border-indigo-100' }}">
                                            <i class="fas {{ $isDeleted ? 'fa-calendar-times' : 'fa-calendar-check' }}"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="text-sm font-bold {{ $isDeleted ? 'text-red-700 line-through' : 'text-gray-900' }} flex items-center gap-2">
                                                    {{ $schName }}
                                                    @if(!$schedule && $scheduleId && !$isDeleted)
                                                        <i class="fas fa-exclamation-triangle text-amber-500 text-[10px]" title="{{ tr('Schedule no longer exists') }}"></i>
                                                    @endif
                                                </p>
                                                @if($isDeleted)
                                                    <span class="text-[10px] font-black uppercase text-red-600 tracking-widest bg-red-50 px-2 py-0.5 rounded border border-red-200">{{ tr('Removed') }}</span>
                                                @endif
                                            </div>
                                            
                                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                                @if($startDate)
                                                    <div class="flex items-center gap-1.5 px-2 py-1 bg-gray-50 rounded border border-gray-100 text-gray-600">
                                                        <span class="font-semibold">{{ tr('From') }}:</span>
                                                        <span class="font-mono text-gray-800">{{ company_date($startDate) }}</span>
                                                    </div>
                                                @endif
                                                
                                                @if($endDate)
                                                    <div class="flex items-center gap-1.5 px-2 py-1 bg-gray-50 rounded border border-gray-100 text-gray-600">
                                                        <span class="font-semibold">{{ tr('To') }}:</span>
                                                        <span class="font-mono text-gray-800">{{ company_date($endDate) }}</span>
                                                    </div>
                                                @else
                                                    <div class="flex items-center gap-1.5 px-2 py-1 bg-purple-50 rounded border border-purple-100 text-purple-700">
                                                        <i class="fas fa-infinity text-[10px]"></i>
                                                        <span class="font-bold">{{ tr('Permanent') }}</span>
                                                    </div>
                                                @endif

                                                @if($log->action === 'work_schedule.changed' && $before)
                                                    <div class="w-full mt-1 pt-1 border-t border-dashed border-gray-100 text-[10px] text-gray-400 italic">
                                                        <i class="fas fa-exchange-alt mr-1"></i>
                                                        {{ tr('Prev Schedule') }}: {{ \Athka\SystemSettings\Models\WorkSchedule::find($before['work_schedule_id'] ?? 0)?->name ?: '#' . ($before['work_schedule_id'] ?? '?') }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="px-4 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-xs">
                                    <div class="flex items-center gap-2 text-gray-600">
                                        <div class="w-5 h-5 rounded-full bg-white border border-gray-200 flex items-center justify-center text-[10px]">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <span class="font-medium">
                                            {{ $log->actor ? ($log->actor->name ?: $log->actor->username) : tr('System') }}
                                        </span>
                                    </div>
                                    @if($log->ip)
                                        <div class="flex items-center gap-1.5 text-gray-400 font-mono bg-white px-2 py-0.5 rounded border border-gray-100">
                                            <i class="fas fa-laptop text-[10px]"></i>
                                            {{ $log->ip }}
                                        </div>
                                    @endif
                                </div>
                            </x-ui.card>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-12 text-center">
                            <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                <i class="fas fa-history text-3xl text-gray-300"></i>
                            </div>
                            <h3 class="text-gray-900 font-bold mb-1">{{ tr('No History Found') }}</h3>
                            <p class="text-gray-500 text-sm max-w-xs">{{ tr('There are no schedule assignment records for this employee yet.') }}</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end w-full">
            <x-ui.secondary-button wire:click="$set('showHistoryModal', false)">
                {{ tr('Close') }}
            </x-ui.secondary-button>
        </div>
    </x-slot>
</x-ui.modal>
