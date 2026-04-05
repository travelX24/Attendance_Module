@php
    $locale = app()->getLocale();
    $isRtl  = in_array(substr($locale, 0, 2), ['ar','fa','ur','he']);
    $dir    = $isRtl ? 'rtl' : 'ltr';
@endphp
<div class="w-full">
    {{-- Top Fixed Loading Bar --}}
    <div wire:loading class="fixed top-0 left-0 right-0 h-[3px] z-[9999] overflow-hidden pointer-events-none">
        <div class="h-full w-full bg-gradient-to-r from-[color:var(--brand-from)] via-[color:var(--brand-via)] to-[color:var(--brand-to)]">
            <div class="h-full w-full bg-white/30 animate-[loading-sweep_1.5s_infinite]"></div>
        </div>
    </div>
    <style>
        @keyframes loading-sweep {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
    </style>
@php
    // The top bar slot is now handled within the main view for better contextual placement
@endphp

<div class="space-y-6" dir="{{ $dir }}">

    {{-- Stats Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
        {{-- Total Employees - Reset Filter --}}
        <div class="relative">
            <x-ui.card hover
                wire:click="clearAllFilters"
                :title="tr('Shows all active employees in the company')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'all' && $schedule_type === 'all' ? 'border-[color:var(--brand-via)] bg-[color:var(--brand-via)]/5' : 'border-transparent' }}"
            >
                <div class="p-3 bg-indigo-50 text-[color:var(--brand-via)] rounded-xl">
                    <i class="fas fa-users-cog fa-lg"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">{{ tr('Total Employees') }}</p>
                    <p class="text-lg font-bold text-gray-900">{{ $stats['total_employees'] }}</p>
                </div>
            </x-ui.card>
        </div>

        {{-- NEW: Linked Employees (Blue) --}}
        <div class="relative">
            <x-ui.card hover
                wire:click="$set('schedule_type', 'linked')"
                :title="tr('Employees who have an active work schedule assigned')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $schedule_type === 'linked' ? 'border-blue-500 bg-blue-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-blue-50 text-blue-600 rounded-xl">
                    <i class="fas fa-calendar-check fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('Linked Employees') }}</p>
                    <p class="text-lg font-bold text-gray-900">{{ $stats['with_schedule'] }}</p>
                </div>
            </x-ui.card>
        </div>

        {{-- Red Warning: No Schedule --}}
        <div class="relative">
            <x-ui.card hover
                wire:click="$set('schedule_type', 'unlinked')"
                :title="tr('Employees who are not yet assigned to any work schedule')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $schedule_type === 'unlinked' || $filterWarning === 'no_schedule' ? 'border-red-500 bg-red-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-red-50 text-red-600 rounded-xl">
                    <i class="fas fa-user-slash fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('No Schedule') }}</p>
                    <p class="text-lg font-bold text-gray-900">{{ $earlyWarnings['no_schedule_overdue'] }}</p>
                </div>
            </x-ui.card>
        </div>

        {{-- Yellow Warning: Ending Soon --}}
        <div class="relative">
            <x-ui.card hover
                wire:click="setWarningFilter('ending_soon')"
                :title="tr('Employees whose current schedule expires within the next 3 business days')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'ending_soon' ? 'border-yellow-500 bg-yellow-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-yellow-50 text-yellow-600 rounded-xl">
                    <i class="fas fa-hourglass-half fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('Ending Soon') }}</p>
                    <p class="text-lg font-bold text-gray-900">{{ $earlyWarnings['ending_soon'] }}</p>
                </div>
            </x-ui.card>
        </div>

        {{-- Orange Warning: Changed Too Much --}}
        <div class="relative">
            <x-ui.card hover
                wire:click="setWarningFilter('changed_too_much')"
                :title="tr('Employees with more than two schedule changes during the current month')"
                class="flex items-center gap-4 cursor-pointer transition-all border-2 {{ $filterWarning === 'changed_too_much' ? 'border-orange-500 bg-orange-50' : 'border-transparent' }}"
            >
                <div class="p-3 bg-orange-50 text-orange-600 rounded-xl">
                    <i class="fas fa-history fa-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="text-xs text-gray-500">{{ tr('High Turnover') }}</p>
                    <p class="text-lg font-bold text-gray-900">{{ $earlyWarnings['changed_too_much'] }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>
    
    {{-- Tab Switcher & Bulk Actions --}}
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4"
         x-data="{ selectionCount: {{ count($selectedEmployees) }} }"
         x-on:selected-employees-count-updated.window="selectionCount = $event.detail[0].count">
        
        {{-- Tabs --}}
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

        {{-- Bulk Actions Toolbar (Appears only on selection) --}}
        @can('attendance.manage')
        <div 
            x-show="selectionCount > 0"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-4"
            class="flex items-center gap-3 bg-gray-900 text-white px-4 py-2 rounded-2xl shadow-xl border border-gray-700"
        >
            <div class="flex items-center gap-2 border-e border-gray-700 pe-4 me-1">
                <div class="w-8 h-8 rounded-full bg-[color:var(--brand-via)] flex items-center justify-center text-white text-xs font-bold shadow-lg shadow-[color:var(--brand-via)]/20">
                    <span x-text="selectionCount"></span>
                </div>
                <span class="text-xs font-semibold text-gray-300">{{ tr('Selected') }}</span>
            </div>

            <div class="flex items-center gap-2">
                <button 
                    x-on:click="$dispatch('triggerOpenBulkModal')"
                    class="group flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 text-white text-xs font-bold rounded-xl transition-all active:scale-95"
                >
                    <i class="fas fa-calendar-plus text-blue-400 group-hover:scale-110 transition-transform"></i>
                    <span>{{ tr('Assign Schedule') }}</span>
                </button>

                <button 
                    x-on:click="$dispatch('triggerOpenRotationModal')"
                    class="group flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 text-white text-xs font-bold rounded-xl transition-all active:scale-95 border border-dashed border-gray-600 hover:border-gray-400"
                >
                    <i class="fas fa-sync-alt text-purple-400 group-hover:rotate-45 transition-transform"></i>
                    <span>{{ tr('Assign Rotation') }}</span>
                </button>

                <button 
                    wire:click="$set('selectedEmployees', [])"
                    class="p-2 text-gray-400 hover:text-red-400 transition-colors"
                    title="{{ tr('Clear Selection') }}"
                >
                    <i class="fas fa-times-circle fa-lg"></i>
                </button>
            </div>
        </div>
        @endcan
    </div>

    {{-- Filters & Content --}}
    <x-ui.card padding="false" class="!overflow-visible" wire:poll.60s>
        {{-- Toolbar / Filters --}}
        <div class="p-4 border-b border-gray-100 bg-gray-50/30 relative">
            {{-- Filters Grid --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-4 items-end">

                {{-- Search --}}
                <div class="lg:col-span-1 xl:col-span-1">
                    <x-ui.search-box
                        model="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="tr('Search by name or employee ID...')"
                        class="w-full"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>

                {{-- Department --}}
                <div class="">
                    <x-ui.filter-select
                        model="department_id"
                        :label="tr('Department')"
                        :placeholder="tr('All Departments')"
                        :options="array_merge([['value' => 'all', 'label' => tr('All Departments')]], $departments->map(fn($d) => ['value' => (string)$d->id, 'label' => $d->name])->toArray())"
                        width="full"
                        :defer="false"
                        :applyOnChange="true"
                        class="w-full"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>

                {{-- Link Status --}}
                <div class="">
                    <x-ui.filter-select
                        model="schedule_type"
                        :label="tr('Link Status')"
                        :placeholder="tr('All')"
                        :options="[
                            ['value' => 'linked', 'label' => tr('Linked')],
                            ['value' => 'unlinked', 'label' => tr('Unlinked')],
                        ]"
                        width="full"
                        :defer="false"
                        :applyOnChange="true"
                        class="w-full"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>

                {{-- Work Schedule --}}
                <div class="">
                    <x-ui.filter-select
                        model="work_schedule_id"
                        :label="tr('Work Schedule')"
                        :placeholder="tr('All Schedules')"
                        :options="array_merge([['value' => 'all', 'label' => tr('All Schedules')]], $workSchedules->map(fn($s) => ['value' => (string)$s->id, 'label' => $s->name])->toArray())"
                        width="full"
                        :defer="false"
                        :applyOnChange="true"
                        class="w-full"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>

                {{-- Status --}}
                <div class="">
                    <x-ui.filter-select
                        model="status"
                        :label="tr('Employee Status')"
                        :placeholder="tr('All Statuses')"
                        :options="[
                            ['value' => 'all', 'label' => tr('All')],
                            ['value' => 'ACTIVE', 'label' => tr('Active')],
                            ['value' => 'SUSPENDED', 'label' => tr('Suspended')],
                            ['value' => 'TERMINATED', 'label' => tr('Terminated')],
                        ]"
                        width="full"
                        :defer="false"
                        :applyOnChange="true"
                        class="w-full"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>

                {{-- Warnings --}}
                <div class="">
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
                        width="full"
                        :defer="false"
                        :applyOnChange="true"
                        class="w-full"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>
            </div>

                    {{-- Clear Filters Button --}}
                    <div
                        x-data="{
                            hasFilters() {
                                return ($wire.search && $wire.search.trim() !== '') ||
                                       $wire.department_id !== 'all' ||
                                       $wire.schedule_type !== 'all' ||
                                       $wire.work_schedule_id !== 'all' ||
                                       $wire.status !== 'all' ||
                                       $wire.filterWarning !== 'all' ||
                                       ($wire.location_id !== 'all' && $wire.location_id != '{{ auth()->user()->branch_id ?: 0 }}');
                            }
                        }"
                        x-show="hasFilters()"
                        x-transition
                        class="flex items-center"
                    >
                        <button
                            type="button"
                            wire:click="clearAllFilters"
                            wire:loading.attr="disabled"
                            wire:target="clearAllFilters"
                            class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900 transition-colors disabled:opacity-50"
                        >
                            <i class="fas fa-times" wire:loading.remove wire:target="clearAllFilters"></i>
                            <i class="fas fa-spinner fa-spin" wire:loading wire:target="clearAllFilters"></i>
                            <span wire:loading.remove wire:target="clearAllFilters">{{ tr('Clear all filters') }}</span>
                            <span wire:loading wire:target="clearAllFilters">{{ tr('Clearing...') }}</span>
                        </button>
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
                            @php
                                $cId = $this->getCompanyId();
                                $currentCarbon = \Carbon\Carbon::parse($summaryDate);
                            @endphp
                            @if($summaryPeriod === 'weekly')
                                {{ $this->formatCompanyDate($currentCarbon->copy()->startOfWeek(\Carbon\Carbon::SATURDAY)->toDateString(), $cId) }} - 
                                {{ $this->formatCompanyDate($currentCarbon->copy()->startOfWeek(\Carbon\Carbon::SATURDAY)->addDays(6)->toDateString(), $cId) }}
                            @else
                                {{ $this->formatCompanyMonthYear($currentCarbon, $cId) }}
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
                tr('Employee Status'),
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

                        @if($allCount >= 1)
                            <button
                                type="button"
                                wire:click="openScheduleEyeModal({{ $employee->id }})"
                                class="inline-flex items-center gap-1 text-xs font-bold text-gray-400 hover:text-[color:var(--brand-via)] transition-colors"
                                title="{{ tr('View all schedules') }}"
                            >
                                <i class="fas fa-eye shadow-sm"></i>
                                @if($extraCount > 0)
                                    <span>+{{ $extraCount }}</span>
                                @endif
                            </button>
                        @endif


                        </div>
                    </td>


                    <td class="px-6 py-4 text-sm text-gray-600 font-mono">
                        {{ $this->formatCompanyDate($employee->current_schedule_start, $this->getCompanyId()) }}
                    </td>

                    <td class="px-6 py-4 text-sm text-gray-600 font-mono">
                        {{ $employee->current_schedule_end ? $this->formatCompanyDate($employee->current_schedule_end, $this->getCompanyId()) : tr('Permanent') }}
                    </td>

                    <td class="px-6 py-4">
                        @php
                            $status = strtoupper($employee->status ?? 'ACTIVE');
                            $statusColor = 'green';
                            if ($status === 'SUSPENDED') $statusColor = 'orange';
                            elseif ($status === 'TERMINATED') $statusColor = 'red';
                        @endphp
                        <div class="flex items-center gap-1.5">
                            <div class="w-2 h-2 rounded-full bg-{{ $statusColor }}-500"></div>
                            <span class="text-xs text-{{ $statusColor }}-700 font-bold">{{ tr($status) }}</span>
                        </div>
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
                           <button type="button" 
                               wire:click="openBulkModalForSingleEmployee({{ $employee->id }})" 
                               wire:loading.attr="disabled" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-xs font-bold transition-all border border-blue-100 hover:border-blue-600 active:scale-95 group shadow-sm" 
                               title="{{ tr('Assign or change schedule') }}"
                            >
                                <i class="fas fa-calendar-plus opacity-70 group-hover:scale-110 transition-transform"></i>
                                {{ tr('Assign') }}
                            </button>

                            <button type="button" 
                                wire:click="openRotationModalForSingleEmployee({{ $employee->id }})" 
                                wire:loading.attr="disabled" 
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-50 text-purple-700 hover:bg-purple-600 hover:text-white rounded-lg text-xs font-bold transition-all border border-purple-100 hover:border-purple-600 active:scale-95 group shadow-sm" 
                                title="{{ tr('Assign rotation schedule') }}"
                            >
                                <i class="fas fa-sync-alt opacity-70 group-hover:rotate-45 transition-transform"></i>
                                {{ tr('Rotation') }}
                            </button>
                            @else
                                <span class="text-gray-400"><i class="fas fa-lock text-[10px]"></i></span>
                            @endcan

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
                                            @php
                                                $isHoliday = $dayData['type'] === 'holiday';
                                                $isLeave   = $dayData['type'] === 'leave';
                                                $isExcept  = ($dayData['type'] ?? '') === 'exception';

                                                $bgClass = $dayData['disabled'] 
                                                    ? 'bg-gray-100 text-gray-400' 
                                                    : ($isExcept
                                                        ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                        : ($isHoliday 
                                                            ? 'bg-slate-50 text-slate-400 border-slate-100' 
                                                            : ($isLeave 
                                                                ? 'bg-blue-50 text-blue-600 border-blue-100' 
                                                                : 'bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] border-[color:var(--brand-via)]/20')));
                                            @endphp
                                            <div
                                                class="mx-auto px-2 py-1.5 rounded-md text-[9px] leading-tight font-bold {{ $bgClass }} border shadow-sm"
                                                title="{{ $dayData['schedule'] }} {{ !empty($dayData['periods']) ? '(' . implode(', ', $dayData['periods']) . ')' : '' }}"
                                            >
                                                <div class="truncate flex items-center justify-center gap-1">
                                                    @if($isExcept) <i class="fas fa-star text-[7px]"></i> @endif
                                                    {{ $dayData['schedule'] }}
                                                </div>
                                                @if(!empty($dayData['periods']))
                                                    <div class="text-[8px] opacity-70 mt-0.5" dir="ltr">
                                                        <span class="inline-block">{{ implode(' | ', $dayData['periods']) }}</span>
                                                    </div>
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

    {{-- Global Confirmation Dialogs --}}
    <x-ui.confirm-dialog
        id="assignment-delete"
        confirm-action="wire:deleteAssignment"
        :title="tr('Delete Schedule Assignment')"
        :message="tr('Are you sure you want to delete this schedule assignment? This action cannot be undone and may affect attendance calculations.')"
        type="danger"
        confirm-text="{{ tr('Delete') }}"
    />
</div>
</div>
