<x-ui.modal wire:model="showSummaryEditHistoryModal" max-width="7xl">
    <x-slot name="title">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-10 h-10 bg-[color:var(--app-soft-bg)] text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--border-soft)] shadow-sm shrink-0">
                <i class="fas fa-history"></i>
            </div>
            <div class="min-w-0">
                <h3 class="font-bold text-gray-900 text-lg leading-tight truncate">{{ $summaryEditHistoryEmployeeName ?: '-' }}</h3>
                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 mt-0.5">
                    <span class="font-semibold text-[color:var(--brand-via)]">#{{ $summaryEditHistoryEmployeeNo ?: '-' }}</span>
                    <span class="text-gray-300">|</span>
                    <span>{{ $summaryEditHistoryPeriod }}</span>
                </div>
            </div>
        </div>
    </x-slot>

    <x-slot name="content">
        @php
            $historyLabels = str_starts_with((string) app()->getLocale(), 'ar')
                ? [
                    'modification_date' => 'تاريخ التعديل',
                    'modified_day_date' => 'تاريخ ويوم اليوم الذي تم تعديله',
                    'modified_period' => 'الفترة المعدلة',
                    'modification_type' => 'نوع التعديل',
                    'before_time' => 'الوقت قبل التعديل',
                    'after_time' => 'الوقت المعدل',
                    'reason' => 'سبب التعديل',
                    'actor' => 'اسم الموظف الذي عدل',
                ]
                : [
                    'modification_date' => 'Modification Date',
                    'modified_day_date' => 'Modified Day and Date',
                    'modified_period' => 'Modified Period',
                    'modification_type' => 'Modification Type',
                    'before_time' => 'Time Before Modification',
                    'after_time' => 'Modified Time',
                    'reason' => 'Modification Reason',
                    'actor' => 'Edited By',
                ];
        @endphp

        @if(count($summaryEditHistory) > 0)
            <div class="rounded-xl border border-gray-100 bg-white shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-[1180px] w-full text-sm text-right">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr class="border-b border-gray-100">
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['modification_date'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['modified_day_date'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['modified_period'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['modification_type'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['before_time'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['after_time'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold min-w-[220px]">{{ $historyLabels['reason'] }}</th>
                                <th class="px-4 py-3 text-xs font-bold whitespace-nowrap">{{ $historyLabels['actor'] }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($summaryEditHistory as $entry)
                                <tr class="hover:bg-gray-50/70 transition-colors">
                                    <td class="px-4 py-3 text-xs font-semibold text-gray-800 whitespace-nowrap">
                                        {{ $entry['edited_at'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-700 whitespace-nowrap">
                                        {{ $entry['attendance_date_with_day'] ?? ($entry['attendance_date'] ?? '-') }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full bg-gray-50 border border-gray-100 px-2.5 py-1 text-xs font-bold text-gray-700">
                                            {{ $entry['period_label'] ?? '-' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full bg-[color:var(--app-soft-bg)] border border-[color:var(--border-soft)] px-2.5 py-1 text-xs font-bold text-[color:var(--brand-via)]">
                                            {{ $entry['change_type_label'] ?? '-' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-mono text-gray-600 whitespace-nowrap">
                                        {{ $entry['before_time'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs font-mono font-bold text-gray-900 whitespace-nowrap">
                                        {{ $entry['after_time'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-700 leading-relaxed">
                                        {{ $entry['reason'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold text-gray-800 whitespace-nowrap">
                                        {{ $entry['actor_name'] ?? tr('System') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="py-10 text-center">
                <div class="w-14 h-14 mx-auto rounded-full bg-gray-50 flex items-center justify-center text-gray-300 mb-3">
                    <i class="fas fa-history fa-lg"></i>
                </div>
                <p class="text-sm text-gray-500 italic">{{ tr('No modification history found for this period.') }}</p>
            </div>
        @endif
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end w-full">
            <x-ui.secondary-button wire:click="closeSummaryEditHistoryModal">
                {{ tr('Close') }}
            </x-ui.secondary-button>
        </div>
    </x-slot>
</x-ui.modal>
