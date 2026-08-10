<x-ui.modal wire:model="showSummaryEditHistoryModal" max-width="2xl">
    <x-slot name="title">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--app-soft-bg)] text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--border-soft)] shadow-sm">
                <i class="fas fa-history"></i>
            </div>
            <div>
                <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Modification History') }}</h3>
                <p class="text-xs text-gray-500 mt-0.5">{{ $summaryEditHistoryPeriod }}</p>
            </div>
        </div>
    </x-slot>

    <x-slot name="content">
        <div class="space-y-5">
            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-[color:var(--brand-via)] font-bold shrink-0 border border-[color:var(--border-soft)]">
                    {{ mb_substr($summaryEditHistoryEmployeeName ?: '?', 0, 1) }}
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-900 truncate">{{ $summaryEditHistoryEmployeeName ?: '-' }}</p>
                    <p class="text-xs text-gray-500">#{{ $summaryEditHistoryEmployeeNo ?: '-' }}</p>
                </div>
            </div>

            @if(count($summaryEditHistory) > 0)
                <div class="space-y-4">
                    @foreach($summaryEditHistory as $entry)
                        <div class="relative ps-6 pb-2 border-s-2 border-gray-100 last:border-transparent">
                            <div class="absolute -start-[9px] top-0 w-4 h-4 rounded-full bg-white border-2 border-[color:var(--brand-via)] flex items-center justify-center">
                                <div class="w-1.5 h-1.5 rounded-full bg-[color:var(--brand-via)]"></div>
                            </div>

                            <div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-bold text-gray-900">{{ $entry['actor_name'] ?? tr('System') }}</span>
                                        <span class="px-2 py-0.5 rounded-full bg-gray-50 border border-gray-100 text-[10px] text-gray-500">
                                            {{ $entry['action_label'] ?? tr('Attendance edit') }}
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-gray-400">{{ $entry['edited_at'] ?? '-' }}</span>
                                </div>

                                <div class="text-[11px] text-gray-500 mb-2">
                                    <i class="fas fa-calendar-day me-1 text-gray-300"></i>
                                    {{ tr('Attendance Date') }}: {{ $entry['attendance_date'] ?? '-' }}
                                </div>

                                <p class="text-xs text-gray-700 leading-relaxed bg-gray-50/70 rounded-lg p-3 border border-gray-100">
                                    <i class="fas fa-quote-left text-[10px] text-gray-300 me-1"></i>
                                    {{ $entry['reason'] ?? '-' }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-10 text-center">
                    <div class="w-14 h-14 mx-auto rounded-full bg-gray-50 flex items-center justify-center text-gray-300 mb-3">
                        <i class="fas fa-history fa-lg"></i>
                    </div>
                    <p class="text-sm text-gray-500 italic">{{ tr('No modification history found for this period.') }}</p>
                </div>
            @endif
        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end w-full">
            <x-ui.secondary-button wire:click="closeSummaryEditHistoryModal">
                {{ tr('Close') }}
            </x-ui.secondary-button>
        </div>
    </x-slot>
</x-ui.modal>
