{-- History Modal --}}
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
                                <div class="p-4 border-b border-gray-50 bg-gray-50/50 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        @if($log->action === 'work_schedule.assigned')
                                            <x-ui.badge type="success" size="sm">
                                                <i class="fas fa-plus-circle me-1"></i> {{ tr('Assigned') }}
                                            </x-ui.badge>
                                        @else
                                            <x-ui.badge type="info" size="sm">
                                                <i class="fas fa-edit me-1"></i> {{ tr('Changed') }}
                                            </x-ui.badge>
                                        @endif
                                    </div>
                                    <span class="text-xs text-gray-500 font-medium flex items-center bg-white px-2 py-1 rounded-md border border-gray-100 shadow-sm">
                                        <i class="far fa-clock me-1.5 text-gray-400"></i>
                                        {{ $log->created_at->format('Y/m/d H:i') }}
                                    </span>
                                </div>

                                <div class="p-4 bg-white">
                                    @php
                                        $after = $log->after_json;
                                        $scheduleId = $after['work_schedule_id'] ?? 0;
                                        $schedule = \Athka\SystemSettings\Models\WorkSchedule::find($scheduleId);
                                        $schName = $schedule ? $schedule->name : ($scheduleId ? tr('Deleted Schedule') . ' #' . $scheduleId : tr('N/A'));
                                        
                                        $startDate = $after['start_date'] ?? null;
                                        $endDate = $after['end_date'] ?? null;
                                    @endphp

                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg shrink-0">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-gray-900 flex items-center gap-2">
                                                {{ $schName }}
                                                @if(!$schedule && $scheduleId)
                                                    <i class="fas fa-exclamation-triangle text-amber-500 text-xs" title="{{ tr('Schedule no longer exists') }}"></i>
                                                @endif
                                            </p>
                                            
                                            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                                @if($startDate)
                                                    <div class="flex items-center gap-1.5 px-2 py-1 bg-gray-50 rounded border border-gray-100 text-gray-600">
                                                        <span class="font-semibold">{{ tr('From') }}:</span>
                                                        <span class="font-mono text-gray-800">{{ $startDate }}</span>
                                                    </div>
                                                @endif
                                                
                                                @if($endDate)
                                                    <div class="flex items-center gap-1.5 px-2 py-1 bg-gray-50 rounded border border-gray-100 text-gray-600">
                                                        <span class="font-semibold">{{ tr('To') }}:</span>
                                                        <span class="font-mono text-gray-800">{{ $endDate }}</span>
                                                    </div>
                                                @else
                                                    <div class="flex items-center gap-1.5 px-2 py-1 bg-purple-50 rounded border border-purple-100 text-purple-700">
                                                        <i class="fas fa-infinity text-[10px]"></i>
                                                        <span class="font-bold">{{ tr('Permanent') }}</span>
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
