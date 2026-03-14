@php
    $locale = app()->getLocale();
    $isRtl  = in_array(substr($locale, 0, 2), ['ar','fa','ur','he']);
    $dir    = $isRtl ? 'rtl' : 'ltr';
@endphp
<div class="w-full">
    @section('topbar-left-content')
        <div x-data="{ selectionCount: {{ count($selectedEmployees) }} }"
             x-on:selected-employees-count-updated.window="selectionCount = $event.detail[0].count">
        <x-ui.page-header
            :title="tr('Work Schedules Management')"
            :subtitle="tr('Assign and monitor employee work schedules')"
        >
            <x-slot name="action">
                <div class="flex flex-col sm:flex-row gap-2">
                   @can('attendance.manage')
                   <template x-if="selectionCount > 0">
                        <div class="flex flex-col sm:flex-row gap-2">
                            <x-ui.secondary-button x-on:click="$dispatch('triggerOpenBulkModal')" class="gap-2">
                                <i class="fas fa-calendar-plus"></i>
                                <span>{{ tr('Add Schedule') }}</span>
                                (<span x-text="selectionCount"></span>)
                            </x-ui.secondary-button>

                            <x-ui.primary-button x-on:click="$dispatch('triggerOpenRotationModal')" class="gap-2">
                                <i class="fas fa-sync-alt"></i>
                                <span>{{ tr('Add Rotation Schedule') }}</span>
                                (<span x-text="selectionCount"></span>)
                            </x-ui.primary-button>
                        </div>
                    </template>
                    @endcan
                </div>
            </x-slot>
        </x-ui.page-header>
    </div>
@endsection

<div class="space-y-6" dir="{{ $dir }}">

    {{-- Stats Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Total Employees - Reset Filter --}}
        <div x-data="{ open: false }" class="relative" x-on:mouseenter="open = true" x-on:mouseleave="open = false">
            <x-ui.card hover
                wire:click="$set('filterWarning', 'all')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'all' ? 'border-[color:var(--brand-via)] bg-[color:var(--brand-via)]/5' : 'border-transparent' }}"
            >
                <div class="p-3 bg-indigo-50 text-[color:var(--brand-via)] rounded-xl">
                    <i class="fas fa-users fa-lg"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">{{ tr('Total Employees') }}</p>
                    <p class="text-lg font-bold text-gray-900">{{ $stats['total_employees'] }}</p>
                </div>
            </x-ui.card>

            {{-- Popover --}}
            @if(count($warningEmployees['total_employees']) > 0)
            <div 
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-1"
                class="absolute z-50 top-full mt-2 w-full min-w-[240px] bg-white border border-indigo-100 shadow-2xl rounded-xl p-3 pointer-events-none"
                style="backdrop-filter: blur(12px); background: rgba(255, 255, 255, 0.98);"
            >
                <div class="text-[10px] font-bold text-indigo-600 mb-2 uppercase tracking-wider flex items-center gap-2">
                    <i class="fas fa-users text-xs"></i>
                    {{ tr('Employee List') }}
                </div>
                <ul class="space-y-1.5 list-none p-0 m-0">
                    @foreach($warningEmployees['total_employees'] as $name)
                        <li class="flex items-center gap-2 text-xs text-gray-700">
                            <div class="w-1.5 h-1.5 rounded-full bg-indigo-400"></div>
                            <span class="truncate">{{ $name }}</span>
                        </li>
                    @endforeach
                    @if($stats['total_employees'] > 10)
                        <li class="pt-1 mt-1 border-t border-indigo-50 text-[10px] text-gray-400 italic">
                            {{ tr('and :count more...', ['count' => $stats['total_employees'] - 10]) }}
                        </li>
                    @endif
                </ul>
            </div>
            @endif
        </div>

        {{-- Red Warning: No Schedule --}}
        <div x-data="{ open: false }" class="relative" x-on:mouseenter="open = true" x-on:mouseleave="open = false">
            <x-ui.card hover
                wire:click="setWarningFilter('no_schedule')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'no_schedule' ? 'border-red-500 bg-red-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-red-50 text-red-600 rounded-xl">
                    <i class="fas fa-user-slash fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('No Schedule') }}</p>
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-bold text-gray-900">{{ $earlyWarnings['no_schedule_overdue'] }}</p>
                        <x-ui.badge type="danger" size="xs">{{ tr('Critical') }}</x-ui.badge>
                    </div>
                </div>
            </x-ui.card>

            {{-- Popover --}}
            @if(count($warningEmployees['no_schedule_overdue']) > 0)
            <div 
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-1"
                class="absolute z-50 top-full mt-2 w-full min-w-[240px] bg-white border border-red-100 shadow-2xl rounded-xl p-3 pointer-events-none"
                style="backdrop-filter: blur(12px); background: rgba(255, 255, 255, 0.98);"
            >
                <div class="text-[10px] font-bold text-red-600 mb-2 uppercase tracking-wider flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-xs"></i>
                    {{ tr('Affected Employees') }}
                </div>
                <ul class="space-y-1.5 list-none p-0 m-0">
                    @foreach($warningEmployees['no_schedule_overdue'] as $name)
                        <li class="flex items-center gap-2 text-xs text-gray-700">
                            <div class="w-1.5 h-1.5 rounded-full bg-red-400"></div>
                            <span class="truncate">{{ $name }}</span>
                        </li>
                    @endforeach
                    @if($earlyWarnings['no_schedule_overdue'] > 10)
                        <li class="pt-1 mt-1 border-t border-red-50 text-[10px] text-gray-400 italic">
                            {{ tr('and :count more...', ['count' => $earlyWarnings['no_schedule_overdue'] - 10]) }}
                        </li>
                    @endif
                </ul>
            </div>
            @endif
        </div>

        {{-- Yellow Warning: Ending Soon --}}
        <div x-data="{ open: false }" class="relative" x-on:mouseenter="open = true" x-on:mouseleave="open = false">
            <x-ui.card hover
                wire:click="setWarningFilter('ending_soon')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'ending_soon' ? 'border-yellow-500 bg-yellow-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-yellow-50 text-yellow-600 rounded-xl">
                    <i class="fas fa-hourglass-half fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('Ending Soon') }}</p>
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-bold text-gray-900">{{ $earlyWarnings['ending_soon'] }}</p>
                        <x-ui.badge type="warning" size="xs">{{ tr('Warning') }}</x-ui.badge>
                    </div>
                </div>
            </x-ui.card>

            {{-- Popover --}}
            @if(count($warningEmployees['ending_soon']) > 0)
            <div 
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-1"
                class="absolute z-50 top-full mt-2 w-full min-w-[240px] bg-white border border-yellow-100 shadow-2xl rounded-xl p-3 pointer-events-none"
                style="backdrop-filter: blur(12px); background: rgba(255, 255, 255, 0.98);"
            >
                <div class="text-[10px] font-bold text-yellow-600 mb-2 uppercase tracking-wider flex items-center gap-2">
                    <i class="fas fa-clock text-xs"></i>
                    {{ tr('Affected Employees') }}
                </div>
                <ul class="space-y-1.5 list-none p-0 m-0">
                    @foreach($warningEmployees['ending_soon'] as $name)
                        <li class="flex items-center gap-2 text-xs text-gray-700">
                            <div class="w-1.5 h-1.5 rounded-full bg-yellow-400"></div>
                            <span class="truncate">{{ $name }}</span>
                        </li>
                    @endforeach
                    @if($earlyWarnings['ending_soon'] > 10)
                        <li class="pt-1 mt-1 border-t border-yellow-50 text-[10px] text-gray-400 italic">
                            {{ tr('and :count more...', ['count' => $earlyWarnings['ending_soon'] - 10]) }}
                        </li>
                    @endif
                </ul>
            </div>
            @endif
        </div>

        {{-- Orange Warning: Changed Too Much --}}
        <div x-data="{ open: false }" class="relative" x-on:mouseenter="open = true" x-on:mouseleave="open = false">
            <x-ui.card hover
                wire:click="setWarningFilter('changed_too_much')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'changed_too_much' ? 'border-orange-500 bg-orange-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-orange-50 text-orange-600 rounded-xl">
                    <i class="fas fa-history fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('High Turnover') }}</p>
                    <div class="flex items-center justify-between">
                        <p class="text-lg font-bold text-gray-900">{{ $earlyWarnings['changed_too_much'] }}</p>
                        <x-ui.badge type="orange" size="xs">2+</x-ui.badge>
                    </div>
                </div>
            </x-ui.card>

            {{-- Popover --}}
            @if(count($warningEmployees['changed_too_much']) > 0)
            <div 
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-1"
                class="absolute z-50 top-full mt-2 w-full min-w-[240px] bg-white border border-orange-100 shadow-2xl rounded-xl p-3 pointer-events-none"
                style="backdrop-filter: blur(12px); background: rgba(255, 255, 255, 0.98);"
            >
                <div class="text-[10px] font-bold text-orange-600 mb-2 uppercase tracking-wider flex items-center gap-2">
                    <i class="fas fa-sync text-xs"></i>
                    {{ tr('Affected Employees') }}
                </div>
                <ul class="space-y-1.5 list-none p-0 m-0">
                    @foreach($warningEmployees['changed_too_much'] as $name)
                        <li class="flex items-center gap-2 text-xs text-gray-700">
                            <div class="w-1.5 h-1.5 rounded-full bg-orange-400"></div>
                            <span class="truncate">{{ $name }}</span>
                        </li>
                    @endforeach
                    @if($earlyWarnings['changed_too_much'] > 10)
                        <li class="pt-1 mt-1 border-t border-orange-50 text-[10px] text-gray-400 italic">
                            {{ tr('and :count more...', ['count' => $earlyWarnings['changed_too_much'] - 10]) }}
                        </li>
                    @endif
                </ul>
            </div>
            @endif
        </div>
    </div>
    
    {{-- Tab Switcher --}}
    <div class="flex items-center gap-1 bg-white p-1 rounded-xl shadow-sm border border-gray-100 w-fit">
        <button 
            wire:click="setTab('list')"
            class="px-6 py-2 rounded-lg text-sm font-bold transition-all {{ $activeTab === 'list' ? 'bg-[color:var(--brand-via)] text-white shadow-md' : 'text-gray-500 hover:bg-gray-50' }}"
        >
            <i class="fas fa-list me-2"></i>
            {{ tr('Employee List') }}
        </button>
        <button 
            wire:click="setTab('summary')"
            class="px-6 py-2 rounded-lg text-sm font-bold transition-all {{ $activeTab === 'summary' ? 'bg-[color:var(--brand-via)] text-white shadow-md' : 'text-gray-500 hover:bg-gray-50' }}"
        >
            <i class="fas fa-th-list me-2"></i>
            {{ tr('Schedules Summary') }}
        </button>
    </div>

    {{-- Filters & Content --}}
    <x-ui.card padding="false" class="!overflow-visible">
        {{-- Toolbar / Filters --}}
        <div class="p-4 border-b border-gray-100 bg-gray-50/30">
            {{-- ✅ Flex wrap instead of horizontal scroll to ensure dropdowns are never clipped --}}
            <div class="flex flex-wrap items-end gap-4 p-1">

                    {{-- Search --}}
                    <div class="shrink-0 w-[300px] min-w-[300px]">
                        <x-ui.search-box
                            model="search"
                            :placeholder="tr('Search by name or employee ID...')"
                            class="w-full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Department --}}
                    <div class="shrink-0 w-[220px] min-w-[220px]">
                        <x-ui.filter-select
                            model="department_id"
                            :label="tr('Department')"
                            :placeholder="tr('All Departments')"
                            :options="array_merge([['value' => 'all', 'label' => tr('All Departments')]], $departments->map(fn($d) => ['value' => (string)$d->id, 'label' => $d->name])->toArray())"
                            width="md"
                            :defer="false"
                            :applyOnChange="true"
                            class="w-full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Link Status --}}
                    <div class="shrink-0 w-[220px] min-w-[220px]">
                        <x-ui.filter-select
                            model="schedule_type"
                            :label="tr('Link Status')"
                            :placeholder="tr('All')"
                            :options="[
                                ['value' => 'linked', 'label' => tr('Linked')],
                                ['value' => 'unlinked', 'label' => tr('Unlinked')],
                            ]"
                            width="md"
                            :defer="false"
                            :applyOnChange="true"
                            class="w-full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Work Schedule --}}
                    <div class="shrink-0 w-[280px] min-w-[280px]">
                        <x-ui.filter-select
                            model="work_schedule_id"
                            :label="tr('Work Schedule')"
                            :placeholder="tr('All Schedules')"
                            :options="array_merge([['value' => 'all', 'label' => tr('All Schedules')]], $workSchedules->map(fn($s) => ['value' => (string)$s->id, 'label' => $s->name])->toArray())"
                            width="lg"
                            :defer="false"
                            :applyOnChange="true"
                            class="w-full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Warnings --}}
                    <div class="shrink-0 w-[220px] min-w-[220px]">
                        <x-ui.filter-select
                            model="filterWarning"
                            :label="tr('Warnings')"
                            :placeholder="tr('All Warnings')"
                            :options="[
                                ['value' => 'all', 'label' => tr('All')],
                                ['value' => 'no_schedule', 'label' => tr('No Schedule')],
                                ['value' => 'ending_soon', 'label' => tr('Ending Soon')],
                                ['value' => 'changed_too_much', 'label' => tr('Too many changes')],
                                ['value' => 'inactive_schedule', 'label' => tr('Inactive Schedule')],
                            ]"
                            width="md"
                            :defer="false"
                            :applyOnChange="true"
                            class="w-full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                </div>
            </div>
        </div>

        @if($activeTab === 'summary')
            <div class="p-4 border-b border-gray-100 bg-white">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-2 bg-gray-50 p-1 rounded-lg border border-gray-200">
                        <button
                            wire:click="setSummaryPeriod('weekly')"
                            class="px-4 py-1.5 rounded-md text-xs font-bold transition-all {{ $summaryPeriod === 'weekly' ? 'bg-white text-[color:var(--brand-via)] shadow-sm' : 'text-gray-500 hover:bg-white/50' }}"
                        >
                            {{ tr('Weekly') }}
                        </button>
                        <button
                            wire:click="setSummaryPeriod('monthly')"
                            class="px-4 py-1.5 rounded-md text-xs font-bold transition-all {{ $summaryPeriod === 'monthly' ? 'bg-white text-[color:var(--brand-via)] shadow-sm' : 'text-gray-500 hover:bg-white/50' }}"
                        >
                            {{ tr('Monthly') }}
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        <button wire:click="prevPeriod" class="w-10 h-10 flex items-center justify-center rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-600 transition-colors">
                            <i class="fas fa-chevron-{{ $isRtl ? 'right' : 'left' }}"></i>
                        </button>

                        <div class="flex items-center gap-2 px-4 h-10 bg-gray-50 rounded-lg border border-gray-200 font-bold text-gray-700 min-w-[200px] justify-center text-sm">
                            <i class="far fa-calendar-alt text-[color:var(--brand-via)]"></i>
                            @if($summaryPeriod === 'weekly')
                                {{ \Carbon\Carbon::parse($summaryDate)->startOfWeek(\Carbon\Carbon::SATURDAY)->format('Y/m/d') }} - {{ \Carbon\Carbon::parse($summaryDate)->startOfWeek(\Carbon\Carbon::SATURDAY)->addDays(6)->format('Y/m/d') }}
                            @else
                                {{ \Carbon\Carbon::parse($summaryDate)->translatedFormat('F Y') }}
                            @endif
                        </div>

                        <button wire:click="nextPeriod" class="w-10 h-10 flex items-center justify-center rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-600 transition-colors">
                            <i class="fas fa-chevron-{{ $isRtl ? 'left' : 'right' }}"></i>
                        </button>

                        <x-ui.secondary-button wire:click="goToToday" size="sm" class="h-10">
                            {{ tr('Today') }}
                        </x-ui.secondary-button>
                    </div>
                </div>
            </div>
        @endif

        @if($activeTab === 'list')
        {{-- Table --}}
        @php
            $headers = [
                '', // Checkbox
                tr('Employee'),
                tr('Department & Title'),
                tr('Work Schedule'),
                tr('Effective Date'),
                tr('Expiry Date'),
                tr('Current Status'),
                tr('Control'),
            ];
            $headerAlign = ['start', 'start', 'start', 'start', 'start', 'start', 'start', 'center'];
        @endphp

        <x-ui.table :headers="$headers" :headerAlign="$headerAlign" :enablePagination="false">
            @forelse($employees as $employee)
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="px-6 py-4">
                        <input type="checkbox" wire:model.live="selectedEmployees" value="{{ $employee->id }}" class="w-4 h-4 text-[color:var(--brand-via)] border-gray-300 rounded focus:ring-[color:var(--brand-via)]" @cannot('attendance.manage') disabled @endcannot>
                    </td>

                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <div class="w-10 h-10 rounded-full bg-[color:var(--brand-via)]/10 flex items-center justify-center text-[color:var(--brand-via)] font-bold shrink-0">
                                    {{ mb_substr($employee->name_ar ?? $employee->name_en, 0, 1) }}
                                </div>

                                @php
                                    $hasIssue = !$employee->current_schedule_name
                                        || $employee->is_schedule_disabled
                                        || in_array($employee->id, $warningIds['no_schedule_overdue'] ?? [])
                                        || in_array($employee->id, $warningIds['inactive_schedule'] ?? []);
                                @endphp

                                <div class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full flex items-center justify-center border-2 border-white {{ $hasIssue ? 'bg-red-500' : 'bg-green-500' }}">
                                    <i class="fas {{ $hasIssue ? 'fa-times' : 'fa-check' }} text-[8px] text-white"></i>
                                </div>
                            </div>

                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $employee->name_ar ?: $employee->name_en }}</p>
                                <p class="text-xs text-gray-500">#{{ $employee->employee_no }}</p>
                            </div>
                        </div>
                    </td>

                    <td class="px-6 py-4">
                        <p class="text-sm text-gray-700">{{ $employee->department ? $employee->department->name : '-' }}</p>
                        <p class="text-xs text-gray-500">{{ $employee->jobTitle ? $employee->jobTitle->title_ar : '' }}</p>
                    </td>

                    <td class="px-6 py-4">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($employee->current_schedule_name)
                                <x-ui.badge type="info">
                                    {{ $employee->current_schedule_name }}
                                </x-ui.badge>
                            @else
                                <span class="text-xs text-gray-400 italic">{{ tr('No schedule') }}</span>
                            @endif

                            @php
                            $allCount = (int) ($employee->all_schedules_count ?? 0);

                            $extraCount = $employee->current_schedule_name ? max(0, $allCount - 1) : max(0, $allCount);
                        @endphp

                        @if($extraCount > 0)
                            <button
                                type="button"
                                wire:click="openScheduleEyeModal({{ $employee->id }})"
                                class="inline-flex items-center gap-2 text-xs font-bold text-gray-600 hover:text-[color:var(--brand-via)]"
                                title="{{ tr('View all schedules') }}"
                            >
                                <i class="fas fa-eye"></i>
                                <span>+{{ $extraCount }}</span>
                            </button>
                        @endif


                        </div>
                    </td>


                    <td class="px-6 py-4 text-sm text-gray-600 font-mono">
                        {{ $employee->current_schedule_start ? \Carbon\Carbon::parse($employee->current_schedule_start)->format('Y/m/d') : '-' }}
                    </td>

                    <td class="px-6 py-4 text-sm text-gray-600 font-mono">
                        {{ $employee->current_schedule_end ? \Carbon\Carbon::parse($employee->current_schedule_end)->format('Y/m/d') : tr('Permanent') }}
                    </td>

                    <td class="px-6 py-4">
                        @if($employee->current_schedule_name)
                            @php
                                $today = now()->startOfDay();
                                $isFuture = $employee->current_schedule_start && \Carbon\Carbon::parse($employee->current_schedule_start)->isAfter($today);
                                $isPast = $employee->current_schedule_end && \Carbon\Carbon::parse($employee->current_schedule_end)->isBefore($today);

                                $isEndingSoon = in_array($employee->id, $warningIds['ending_soon'] ?? []);
                                $isTooManyChanges = in_array($employee->id, $warningIds['changed_too_much'] ?? []);
                                $isInactiveSchedule = in_array($employee->id, $warningIds['inactive_schedule'] ?? []) || $employee->is_schedule_disabled;
                            @endphp

                            @if($isInactiveSchedule)
                                <div class="flex items-center gap-1.5" title="{{ tr('The underlying schedule in settings is disabled!') }}">
                                    <div class="w-2 h-2 rounded-full bg-red-600 shadow-sm shadow-red-200"></div>
                                    <span class="text-xs text-red-700 font-bold">{{ tr('Disabled') }}</span>
                                </div>
                            @elseif($isFuture)
                                <div class="flex items-center gap-1.5">
                                    <div class="w-2 h-2 rounded-full bg-blue-500 shadow-sm shadow-blue-200"></div>
                                    <span class="text-xs text-blue-700 font-semibold">{{ tr('Future') }}</span>
                                </div>
                            @elseif($isPast)
                                <div class="flex items-center gap-1.5">
                                    <div class="w-2 h-2 rounded-full bg-gray-400"></div>
                                    <span class="text-xs text-gray-600 font-medium">{{ tr('Expired') }}</span>
                                </div>
                            @else
                                <div class="flex items-center gap-1.5">
                                    <div class="w-2 h-2 rounded-full {{ $isEndingSoon ? 'bg-yellow-500 animate-pulse' : ($isTooManyChanges ? 'bg-orange-500' : 'bg-green-500 shadow-sm shadow-green-200') }}"></div>
                                    <span class="text-xs {{ $isEndingSoon ? 'text-yellow-700 font-bold' : ($isTooManyChanges ? 'text-orange-700' : 'text-green-700') }} font-semibold">
                                        {{ $isEndingSoon ? tr('Ending Soon') : ($isTooManyChanges ? tr('Changes') : tr('Active')) }}
                                    </span>
                                </div>
                            @endif
                        @else
                            @php
                                $isNoScheduleOverdue = in_array($employee->id, $warningIds['no_schedule_overdue'] ?? []);
                            @endphp

                            <div class="flex items-center gap-1.5">
                                <div class="w-2 h-2 rounded-full bg-red-500 {{ $isNoScheduleOverdue ? 'animate-ping' : 'animate-pulse' }}"></div>
                                <span class="text-xs {{ $isNoScheduleOverdue ? 'text-red-700 font-black' : 'text-red-500' }} font-bold">
                                    {{ $isNoScheduleOverdue ? tr('Overdue') : tr('Required') }}
                                </span>
                            </div>
                        @endif
                    </td>

                    <td class="px-6 py-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                           @can('attendance.manage')
                           <button type="button" wire:click="openBulkModalForSingleEmployee({{ $employee->id }})" wire:loading.attr="disabled" class="text-[color:var(--brand-via)] hover:text-[color:var(--brand-from)] text-xs font-bold transition-colors" title="{{ tr('Assign or change schedule') }}">
                                {{ tr('Change') }}
                            </button>

                            <span class="text-gray-200">|</span>

                            <button type="button" wire:click="openRotationModalForSingleEmployee({{ $employee->id }})" wire:loading.attr="disabled" class="text-[color:var(--brand-via)] hover:text-[color:var(--brand-from)] text-xs font-bold transition-colors" title="{{ tr('Assign rotation schedule') }}">
                                {{ tr('Rotation') }}
                            </button>
                            @else
                                <span class="text-gray-400"><i class="fas fa-lock text-[10px]"></i></span>
                            @endcan

                            <span class="text-gray-200">|</span>


                            <span class="text-gray-200">|</span>

                           <x-ui.actions-menu wire:key="actions-{{ $employee->id }}">

                            <x-ui.dropdown-item wire:click="openSchedulePreviewModal({{ $employee->id }})">
                                <i class="fas fa-calendar-day me-2"></i>
                                <span>{{ tr('Schedule Preview') }}</span>
                            </x-ui.dropdown-item>

                            <x-ui.dropdown-item wire:click="openExceptionsModal({{ $employee->id }})" :disabled="!$employee->current_schedule_name || !auth()->user()->can('attendance.manage')">
                                <i class="fas fa-calendar-times me-2"></i>
                                <span>{{ tr('Exceptions') }}</span>
                            </x-ui.dropdown-item>

                            <x-ui.dropdown-item wire:click="openHistoryModal({{ $employee->id }})">
                                <i class="fas fa-history me-2"></i>
                                <span>{{ tr('History') }}</span>
                            </x-ui.dropdown-item>
                        </x-ui.actions-menu>

                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-6 py-12 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center text-gray-300">
                                <i class="fas fa-users-slash fa-2xl"></i>
                            </div>
                            <p class="text-sm text-gray-500 italic">{{ tr('No employees found matching current criteria.') }}</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="sticky left-0 z-20 bg-gray-50 px-4 py-3 text-{{ $isRtl ? 'right' : 'left' }} text-xs font-bold text-gray-600 uppercase tracking-wider border-{{ $isRtl ? 'left' : 'right' }} border-gray-200 min-w-[200px]">
                                {{ tr('Employee') }}
                            </th>
                            @foreach($summaryDays as $day)
                                <th class="px-2 py-3 text-center text-[10px] font-bold {{ $day['is_today'] ? 'text-[color:var(--brand-via)] bg-[color:var(--brand-via)]/5' : 'text-gray-500' }} border-r border-gray-100 min-w-[100px]">
                                    <div>{{ $day['label'] }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($employees as $employee)
                            <tr class="hover:bg-gray-50/30 transition-colors">
                                <td class="sticky left-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-3 border-{{ $isRtl ? 'left' : 'right' }} border-gray-200 shadow-[2px_0_5px_rgba(0,0,0,0.02)]">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-[color:var(--brand-via)]/10 flex items-center justify-center text-[color:var(--brand-via)] text-[10px] font-bold">
                                            {{ mb_substr($employee->name_ar ?? $employee->name_en, 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold text-gray-900 truncate max-w-[140px]">{{ $employee->name_ar ?: $employee->name_en }}</p>
                                            <p class="text-[9px] text-gray-500 truncate">#{{ $employee->employee_no }}</p>
                                        </div>
                                    </div>
                                </td>
                                @foreach($summaryDays as $day)
                                    @php
                                        $dayData = collect($summaryData[$employee->id] ?? [])->firstWhere('date', $day['date']);
                                    @endphp
                                    <td class="px-1 py-2 text-center border-r border-gray-50 {{ $day['is_today'] ? 'bg-[color:var(--brand-via)]/[0.02]' : '' }}">
                                        @if($dayData && $dayData['type'] !== 'none')
                                            <div
                                                class="mx-auto px-2 py-1.5 rounded-md text-[9px] leading-tight font-bold {{ $dayData['disabled'] ? 'bg-gray-100 text-gray-400' : 'bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] border border-[color:var(--brand-via)]/20' }} shadow-sm"
                                                title="{{ $dayData['schedule'] }} {{ !empty($dayData['periods']) ? '(' . implode(', ', $dayData['periods']) . ')' : '' }}"
                                            >
                                                <div class="truncate">{{ $dayData['schedule'] }}</div>
                                                @if(!empty($dayData['periods']))
                                                    <div class="text-[8px] opacity-70 mt-0.5">{{ $dayData['periods'][0] }}</div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="flex items-center justify-center h-full">
                                                <span class="w-1 h-1 rounded-full bg-gray-200"></span>
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            {{-- Empty state handled by outer loop if needed --}}
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Pagination --}}
        @if($employees->hasPages())
            <div class="p-4 bg-gray-50/30 border-t border-gray-50">
                {{ $employees->links() }}
            </div>
        @endif
    </x-ui.card>

    <div wire:key="work-schedules-modals-{{ $modalTrigger }}">
        @include('attendance::livewire.work-schedules.modals.bulk-assignment-modal')
        @include('attendance::livewire.work-schedules.modals.exceptions-modal')
        @include('attendance::livewire.work-schedules.modals.confirm-delete')
        @include('attendance::livewire.work-schedules.modals.history-modal')
        @include('attendance::livewire.work-schedules.modals.schedule-preview-modal')
        @include('attendance::livewire.work-schedules.modals.schedules-eye-modal')
        @include('attendance::livewire.work-schedules.modals.schedule-edit-modal')
    </div>
</div>
</div>
