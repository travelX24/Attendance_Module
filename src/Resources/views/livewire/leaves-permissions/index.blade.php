{{-- app/Modules/Attendance/Resources/views/livewire/leaves-permissions/index.blade.php --}}

@php
    $locale = app()->getLocale();
    $isRtl  = in_array(substr($locale, 0, 2), ['ar','fa','ur','he']);
    $dir    = $isRtl ? 'rtl' : 'ltr';
    $attendanceUser = auth()->user();
    $canManageLeaveRequests = $attendanceUser?->can('attendance.leaves.manage');

    $smartNumber = function (float $n, int $dec = 2): string {
        $r = round($n, $dec);
        return (abs($r - (int)$r) < 0.000001) ? (string)(int)$r : number_format($r, $dec);
    };

    $formatLeaveDuration = function ($r) use ($smartNumber): string {
        $unit = $r->duration_unit ?? 'full_day';

        if ($unit === 'hours') {
            $mins = (int) ($r->minutes ?? 0);
            if ($mins <= 0) return '-';
            $hours = $mins / 60;
            return $smartNumber((float)$hours, 2) . ' ' . tr('Hours');
        }

        if ($unit === 'half_day') {
            $from = $r->from_time ? substr((string) $r->from_time, 0, 5) : null;
            $to = $r->to_time ? substr((string) $r->to_time, 0, 5) : null;
            $period = ($from && $to) ? (' - ' . $from . ' - ' . $to) : '';
            return tr('Half day') . $period;
        }

        // For full day leaves, if requested_days is in the DB, use it.
        // But if it's 0 or missing, we can try to calculate it from dates.
        $days = (float) ($r->requested_days ?? 0);
        
        // If it's a full day leave and requested_days is 0, or if it looks like a calculation error (end-start+1)
        // we might want to be careful. However, to stay consistent with HR rules:
        if ($unit === 'full_day' && $days <= 0 && $r->start_date && $r->end_date) {
             $start = \Carbon\Carbon::parse($r->start_date);
             $end = \Carbon\Carbon::parse($r->end_date);
             if ($end->gte($start)) {
                 $days = $start->diffInDays($end) + 1;
             }
        }

        return $smartNumber($days, 2) . ' ' . tr('Days');
    };

    $formatMissionDuration = function ($r) use ($smartNumber): string {
        if ($r->type === 'partial') {
            return ($r->from_time ?? '--') . ' - ' . ($r->to_time ?? '--');
        }
        $start = \Carbon\Carbon::parse($r->start_date);
        $end = \Carbon\Carbon::parse($r->end_date ?: $r->start_date);
        $days = $start->diffInDays($end) + 1;
        return $smartNumber((float)$days, 0) . ' ' . tr('Days');
    };

    $approvalTaskClass = function (?string $status): string {
        return match ($status) {
            'approved' => 'bg-[color:var(--success)]',
            'rejected' => 'bg-[color:var(--error)]',
            'pending' => 'bg-[color:var(--warning)] animate-pulse',
            default => 'bg-gray-300',
        };
    };
@endphp


@section('topbar-left-content')
    <x-ui.page-header title="{{ tr('Leaves and Permissions') }}" subtitle="{{ tr('Manage requests, approvals, and balances') }}" />
@endsection

<div class="p-6 space-y-6" dir="{{ $dir }}" wire:poll.5s>

    {{-- Tab Transition Indicator using loading-bar component --}}
    <x-ui.loading-bar :fullPage="true" />

    {{-- Flash --}}
    <x-ui.flash-toast />

    {{-- Tabs & Actions Row --}}
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-4">
        <div class="flex items-center gap-1 bg-gray-100/50 p-1 rounded-xl border border-gray-200 shadow-sm w-fit">
            <button wire:click="setTab('pending')"
                onclick="showTabLoadingBar()"
                class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all duration-300 min-w-[100px] {{ $tab === 'pending' ? 'bg-[color:var(--accent-orange)] text-white shadow-sm' : 'bg-transparent text-gray-400 hover:text-gray-700 hover:bg-gray-200/50' }}">
                <i class="fas fa-clock-rotate-left me-1 opacity-70"></i>
                {{ tr('Pending') }}
            </button>

            <button wire:click="setTab('balances')"
                onclick="showTabLoadingBar()"
                class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all duration-300 min-w-[100px] {{ $tab === 'balances' ? 'bg-[color:var(--accent-orange)] text-white shadow-sm' : 'bg-transparent text-gray-400 hover:text-gray-700 hover:bg-gray-200/50' }}">
                <i class="fas fa-wallet me-1 opacity-70"></i>
                {{ tr('Balances') }}
            </button>

            <button wire:click="setTab('history')"
                onclick="showTabLoadingBar()"
                class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all duration-300 min-w-[100px] {{ $tab === 'history' ? 'bg-[color:var(--accent-orange)] text-white shadow-sm' : 'bg-transparent text-gray-400 hover:text-gray-700 hover:bg-gray-200/50' }}">
                <i class="fas fa-history me-1 opacity-70"></i>
                {{ tr('History') }}
            </button>
        </div>

        {{-- Action Button --}}
        @if($canManageLeaveRequests)
        <div class="shrink-0 relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
            <button
                type="button"
                @click="open = !open"
                class="flex items-center gap-2 px-5 py-2.5 bg-[color:var(--accent-orange)] text-white rounded-xl font-bold shadow-lg hover:bg-[color:var(--accent-orange-hover)] transition-all cursor-pointer group text-xs"
                aria-haspopup="true"
                :aria-expanded="open.toString()"
            >
                <i class="fas fa-plus"></i>
                <span>{{ tr('New Request') }}</span>
                <i class="fas fa-chevron-down text-[8px] opacity-70 transition-transform" :class="{ 'rotate-180': open, 'group-hover:translate-y-0.5': !open }"></i>
            </button>

            <div
                x-cloak
                x-show="open"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                x-transition:leave-end="opacity-0 scale-95 translate-y-1"
                @click="open = false"
                class="absolute end-0 top-full z-[10000] mt-2 w-56 max-w-[calc(100vw-2rem)] origin-top-end overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg"
            >
                <x-ui.dropdown-item wire:click="openCreateLeave">
                    <i class="fas fa-calendar-plus me-2 text-[color:var(--accent-orange)]"></i> {{ tr('New Leave Request') }}
                </x-ui.dropdown-item>
                
                <x-ui.dropdown-item wire:click="openCreatePermission">
                    <i class="fas fa-clock me-2 text-[color:var(--warning)]"></i> {{ tr('New Permission') }}
                </x-ui.dropdown-item>

                <x-ui.dropdown-item wire:click="openCreateMission">
                    <i class="fas fa-briefcase me-2 text-[color:var(--accent-orange)]"></i> {{ tr('New Mission Request') }}
                </x-ui.dropdown-item>
                
                <div class="border-t border-gray-100 my-1"></div>

                <x-ui.dropdown-item wire:click="openCreateGroupLeave">
                    <i class="fas fa-users me-2 text-gray-500"></i> {{ tr('New Group Leave') }}
                </x-ui.dropdown-item>

                <x-ui.dropdown-item wire:click="openCreateGroupPermission">
                    <i class="fas fa-users-cog me-2 text-gray-500"></i> {{ tr('New Group Permission') }}
                </x-ui.dropdown-item>

                <div class="border-t border-gray-100 my-1"></div>

                <x-ui.dropdown-item wire:click="openCutLeave">
                    <i class="fas fa-cut me-2 text-[color:var(--error)]"></i> {{ tr('Cut Leave') }}
                </x-ui.dropdown-item>
            </div>
        </div>
        @endif
    </div>

    {{-- Filters --}}
    <x-ui.card class="bg-white rounded-2xl border border-gray-200 p-4">

        <div class="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between">

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 w-full">
                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Employee') }}</div>
                    <x-ui.input
                        type="text"
                        wire:model.live="search"
                        :placeholder="tr('Search employee...')"
                        class="w-full"
                        :disabled="!$canManageLeaveRequests"
                    />
                </div>

                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Year') }}</div>
                    <x-ui.select wire:model.live="selectedYearId" class="w-full" disabled>
                        @foreach($years as $y)
                            <option value="{{ $y->id }}">{{ $y->year }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Branch') }}</div>
                    <x-ui.select wire:model.live="branchId" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="">{{ tr('All Branches') }}</option>
                        @foreach(($branches ?? []) as $br)
                            <option value="{{ $br->id }}">
                                {{ ($br->name ?? ('#'.$br->id)) }}{{ !empty($br->code) ? ' - '.$br->code : '' }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Department') }}</div>
                    <x-ui.select wire:model.live="departmentId" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="">{{ tr('All Departments') }}</option>
                        @foreach(($departments ?? []) as $d)
                            <option value="{{ $d->id }}">{{ $d->label ?? $d->name ?? ('#'.$d->id) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Job Title') }}</div>
                    <x-ui.select wire:model.live="jobTitleId" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="">{{ tr('All Job Titles') }}</option>
                        @foreach(($jobTitles ?? []) as $jt)
                            <option value="{{ $jt->id }}">{{ $jt->name ?? $jt->label ?? ('#'.$jt->id) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Employee Status') }}</div>
                    <x-ui.select wire:model.live="status" class="w-full" :disabled="!$canManageLeaveRequests">
                        @foreach(\Athka\Employees\Support\EmployeeStatus::filterOptions(true) as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Leave Type') }}</div>
                    <x-ui.select wire:model.live="filterLeavePolicyId" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="">{{ tr('All Leave Types') }}</option>
                        @foreach(($policies ?? []) as $p)
                            <option value="{{ $p->id }}">{{ $p->name ?? $p->label ?? ('#'.$p->id) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @if($tab !== 'balances')
                    <div>
                        <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('From Date') }}</div>
                        <x-ui.company-date-picker model="fromDate" :disabled="!$canManageLeaveRequests" />
                    </div>
                    <div>
                        <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('To Date') }}</div>
                        <x-ui.company-date-picker model="toDate" :disabled="!$canManageLeaveRequests" />
                    </div>
                @endif

                @if($tab === 'history')
                    <div>
                        <div class="text-[11px] text-gray-500 mb-1 font-bold uppercase tracking-wider">{{ tr('Status') }}</div>
                        <x-ui.select wire:model.live="historyStatus" class="w-full" :disabled="!$canManageLeaveRequests">
                            <option value="">{{ tr('All Statuses') }}</option>
                            <option value="approved">{{ tr('Approved') }}</option>
                            <option value="rejected">{{ tr('Rejected') }}</option>
                            <option value="cancelled">{{ tr('Cancelled') }}</option>
                        </x-ui.select>
                    </div>
                @endif
            </div>

        </div>

        {{-- Clear Filters Button --}}
        <div x-data="{
            hasFilters() {
                return ($wire.search && $wire.search.trim() !== '') ||
                    ($wire.branchId && $wire.branchId !== '') ||
                    ($wire.departmentId && $wire.departmentId !== '') ||
                    ($wire.jobTitleId && $wire.jobTitleId !== '') ||
                    ($wire.filterLeavePolicyId && $wire.filterLeavePolicyId !== '') ||
                    ($wire.fromDate && $wire.fromDate !== '') ||
                    ($wire.toDate && $wire.toDate !== '') ||
                    ($wire.status && $wire.status !== 'all') ||
                    ($wire.historyStatus && $wire.historyStatus !== '');
            }
        }" x-show="hasFilters()" x-transition class="flex items-center justify-end mt-4 pt-4 border-t border-gray-100">
            <button type="button" wire:click="clearAllFilters" wire:loading.attr="disabled"
                wire:target="clearAllFilters"
                class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-gray-900 transition-colors disabled:opacity-50 cursor-pointer">
                <i class="fas fa-times" wire:loading.remove wire:target="clearAllFilters"></i>
                <i class="fas fa-spinner fa-spin" wire:loading wire:target="clearAllFilters"></i>
                <span wire:loading.remove wire:target="clearAllFilters">{{ tr('Clear filters') }}</span>
            </button>
        </div>

    </x-ui.card>

    {{-- Content Area with Skeleton Loading for Tabs --}}
    <div wire:loading.delay.longest wire:target="tab, setPendingSubTab, setTab" class="w-full">
        @include('attendance::livewire.leaves.mini-placeholder')
    </div>

    <div wire:loading.remove.delay.longest wire:target="tab, setPendingSubTab, setTab">
        {{-- Pending Section --}}
        @if($tab === 'pending')
        <div class="space-y-6">
            {{-- Sub Tabs --}}
            <div class="flex items-center gap-6 border-b border-gray-200">
                <button wire:click="setPendingSubTab('leaves')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative {{ $pendingSubTab === 'leaves' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Leave Requests') }}</span>
                    @if($pendingLeaveRequests->total() > 0)
                        <span class="ms-2 px-1.5 py-0.5 rounded-full bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] text-[10px]">{{ $pendingLeaveRequests->total() }}</span>
                    @endif
                    @if($pendingSubTab === 'leaves') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setPendingSubTab('permissions')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative {{ $pendingSubTab === 'permissions' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Permission Requests') }}</span>
                    @if($pendingPermissionRequests->total() > 0)
                        <span class="ms-2 px-1.5 py-0.5 rounded-full bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] text-[10px]">{{ $pendingPermissionRequests->total() }}</span>
                    @endif
                    @if($pendingSubTab === 'permissions') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setPendingSubTab('cuts')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative {{ $pendingSubTab === 'cuts' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Cut Requests') }}</span>
                    @if($pendingCutLeaveRequests->total() > 0)
                        <span class="ms-2 px-1.5 py-0.5 rounded-full bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] text-[10px]">{{ $pendingCutLeaveRequests->total() }}</span>
                    @endif
                    @if($pendingSubTab === 'cuts') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setPendingSubTab('missions')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative {{ $pendingSubTab === 'missions' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Mission Requests') }}</span>
                    @if($pendingMissionRequests->total() > 0)
                        <span class="ms-2 px-1.5 py-0.5 rounded-full bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] text-[10px]">{{ $pendingMissionRequests->total() }}</span>
                    @endif
                    @if($pendingSubTab === 'missions') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>
            </div>

            <div class="grid grid-cols-1 gap-6">

            {{-- Leave Requests --}}
            @if($pendingSubTab === 'leaves')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Pending Leave Requests') }}</div>
                    <x-ui.badge class="text-xs">{{ $pendingLeaveRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee & Replacement') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Leave Type & Balance') }}</th>
                            <th class="text-start p-3">{{ tr('Period & Duration') }}</th>
                            <th class="text-start p-3">{{ tr('Reason') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($pendingLeaveRequests as $r)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $r->employee?->name_ar ?? $r->employee?->name_en ?? $r->employee?->name ?? $r->employee?->full_name ?? ('#' . $r->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $r->employee_id }}</div>
                                    @if($r->replacement_employee_id)
                                        <div class="mt-2 pt-2 border-t border-dashed border-gray-100 italic text-[10px] text-gray-500">
                                            <i class="fas fa-exchange-alt mr-1"></i>
                                            {{ tr('Replacement') }}: 
                                            <span class="font-bold text-gray-700">
                                                {{ $r->replacementEmployee?->name_ar ?? $r->replacementEmployee?->name_en ?? $r->replacementEmployee?->name ?? ('#' . $r->replacement_employee_id) }}
                                            </span>
                                            <div class="mt-1">
                                                @if($r->replacement_status === 'pending')
                                                    <span class="text-[color:var(--warning)] font-bold bg-[color:var(--warning)]/10 px-1.5 py-0.5 rounded border border-[color:var(--warning)]/20">
                                                        <i class="fas fa-clock mr-1"></i> {{ tr('Waiting Response') }}
                                                    </span>
                                                @elseif($r->replacement_status === 'approved')
                                                    <span class="text-[color:var(--success)] font-bold bg-[color:var(--success)]/10 px-1.5 py-0.5 rounded border border-[color:var(--success)]/20">
                                                        <i class="fas fa-check-circle mr-1"></i> {{ tr('Accepted') }}
                                                    </span>
                                                @elseif($r->replacement_status === 'rejected')
                                                    <span class="text-[color:var(--error)] font-bold bg-[color:var(--error)]/10 px-1.5 py-0.5 rounded border border-[color:var(--error)]/20">
                                                        <i class="fas fa-times-circle mr-1"></i> {{ tr('Rejected') }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </td>

                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($r->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="font-semibold text-gray-800">
                                        {{ $r->policy?->name ?? tr('Group absence') }}
                                    </div>
                                    @php
                                        $yearId = $r->policy_year_id ?: $selectedYearId;
                                        $balance = \Athka\Attendance\Models\AttendanceLeaveBalance::where('employee_id', $r->employee_id)
                                                    ->where('leave_policy_id', $r->leave_policy_id)
                                                    ->where('policy_year_id', $yearId)
                                                    ->first();
                                        $remaining = $balance ? (float) $balance->remaining_days : 0;
                                    @endphp
                                    @if($r->policy)
                                    <div class="mt-1 text-[10px] text-gray-500 font-bold">
                                        {{ tr('Remaining Balance') }}: <span class="{{ $remaining > 0 ? 'text-[color:var(--success)]' : 'text-[color:var(--error)]' }}">{{ $smartNumber($remaining) }}</span>
                                    </div>
                                    @endif
                                    @if($r->is_exception && $r->exception_status === 'pending_hr')
                                        <div class="mt-1">
                                            <x-ui.badge class="text-[10px] bg-[color:var(--warning)]/10 text-[color:var(--warning)] border border-[color:var(--warning)]/20">
                                                <i class="fas fa-exclamation-triangle mr-1"></i> {{ tr('Limit Exceeded - Exception') }}
                                            </x-ui.badge>
                                        </div>
                                    @endif
                                </td>

                                <td class="p-3">
                                    <div class="text-xs text-gray-600">
                                        {{ company_date($r->start_date) }} - {{ company_date($r->end_date) }}
                                    </div>
                                    <div class="mt-1 font-bold text-gray-900">
                                        {{ $formatLeaveDuration($r) }}
                                    </div>
                                </td>


                                <td class="p-3 text-gray-700">
                                    @php
                                        $reason = $r->status === 'rejected'
                                            ? ($r->reject_reason ?? null)
                                            : ($r->reason ?? null);
                                    @endphp
                                    <div class="max-w-[150px] truncate" title="{{ $reason }}">
                                        {{ $reason ?: '-' }}
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($r->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('leave', {{ $r->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>


                                <td class="p-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if($r->attachment_path)
                                            <a href="{{ asset('storage/' . $r->attachment_path) }}" target="_blank"
                                               class="p-2 text-xs font-bold bg-white text-[color:var(--accent-orange)] border border-[color:var(--accent-orange)]/20 rounded-lg shadow-sm hover:bg-[color:var(--accent-orange)]/10 transition-all"
                                               title="{{ $r->attachment_name ?: tr('View Attachment') }}">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        @endif

                                        @php
                                            $currentEmpId = auth()->user()->employee_id;
                                            $isMyTask = $currentEmpId && $r->approvalTasks->where('status', 'pending')->where('approver_employee_id', $currentEmpId)->isNotEmpty();
                                        @endphp

                                        @if($r->replacement_employee_id == $currentEmpId && $r->replacement_status === 'pending')
                                            <x-ui.primary-button
                                                type="button"
                                                wire:click.prevent="respondToReplacementRequest({{ $r->id }}, 'approve')"
                                                class="px-4 py-1.5 text-xs font-bold bg-[color:var(--success)] hover:brightness-95 text-white !rounded-lg"
                                            >
                                                {{ tr('Accept Coverage') }}
                                            </x-ui.primary-button>

                                            <x-ui.secondary-button
                                                type="button"
                                                wire:click.prevent="openReject('replacement', {{ $r->id }})"
                                                class="px-4 py-1.5 text-xs font-bold bg-[color:var(--error)]/10 text-[color:var(--error)] border-[color:var(--error)]/20 hover:bg-[color:var(--error)]/15 !rounded-lg"
                                            >
                                                {{ tr('Reject Coverage') }}
                                            </x-ui.secondary-button>
                                        @elseif($isMyTask)
                                            <x-ui.primary-button
                                                type="button"
                                                wire:click.prevent="approveLeave({{ $r->id }})"
                                                loading="approveLeave({{ $r->id }})"
                                                :full-width="false"
                                                size="sm"
                                                class="px-4 py-2 text-xs font-bold !rounded-lg shadow-sm"
                                            >
                                                <i class="fas fa-check me-2"></i> {{ tr('Approve') }}
                                            </x-ui.primary-button>

                                            <x-ui.secondary-button
                                                type="button"
                                                wire:click.prevent="openReject('leave', {{ $r->id }})"
                                                class="px-4 py-2 text-xs font-bold !text-[color:var(--error)] !border-[color:var(--error)]/30 hover:!bg-[color:var(--error)]/10 !rounded-lg"
                                            >
                                                <i class="fas fa-times me-2"></i> {{ tr('Reject') }}
                                            </x-ui.secondary-button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-file-signature text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No pending requests found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $pendingLeaveRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            {{-- Permission Requests --}}
            @if($pendingSubTab === 'permissions')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Pending Permissions') }}</div>
                    <x-ui.badge class="text-xs">{{ $pendingPermissionRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Date & Time') }}</th>
                            <th class="text-start p-3">{{ tr('Reason') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($pendingPermissionRequests as $r)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $r->employee->name_ar ?? $r->employee->name_en ?? $r->employee->name ?? $r->employee->full_name ?? ('#' . $r->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $r->employee_id }}</div>
                                </td>

                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($r->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="text-xs text-gray-600">
                                        {{ company_date($r->permission_date) }}
                                    </div>
                                    <div class="mt-1 font-mono text-xs text-gray-800 font-bold">
                                        {{ $r->from_time ?? '--:--' }} - {{ $r->to_time ?? '--:--' }}
                                    </div>
                                </td>

                                <td class="p-3 font-bold text-gray-900">
                                    {{ (int) $r->minutes }} {{ tr('min') }}
                                </td>

                                <td class="p-3 text-gray-700">
                                    <div class="max-w-[150px] truncate" title="{{ $r->reason }}">
                                        {{ $r->reason ?: '-' }}
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($r->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('permission', {{ $r->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>


                                <td class="p-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if($r->attachment_path)
                                            <a href="{{ asset('storage/' . $r->attachment_path) }}" target="_blank"
                                               class="p-2 text-xs font-bold bg-white text-[color:var(--accent-orange)] border border-[color:var(--accent-orange)]/20 rounded-lg shadow-sm hover:bg-[color:var(--accent-orange)]/10 transition-all"
                                               title="{{ $r->attachment_name ?: tr('View Attachment') }}">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        @endif

                                        @php
                                            $currentEmpId = auth()->user()->employee_id;
                                            $isMyTask = $currentEmpId && $r->approvalTasks->where('status', 'pending')->where('approver_employee_id', $currentEmpId)->isNotEmpty();
                                        @endphp
                                        
                                        @if($isMyTask)
                                            <x-ui.primary-button
                                                type="button"
                                                wire:click.prevent="approvePermission({{ $r->id }})"
                                                loading="approvePermission({{ $r->id }})"
                                                :full-width="false"
                                                size="sm"
                                                class="px-4 py-2 text-xs font-bold !rounded-lg shadow-sm"
                                            >
                                                <i class="fas fa-check me-2"></i> {{ tr('Approve') }}
                                            </x-ui.primary-button>

                                            <x-ui.secondary-button
                                                type="button"
                                                wire:click.prevent="openReject('permission', {{ $r->id }})"
                                                class="px-4 py-2 text-xs font-bold !text-[color:var(--error)] !border-[color:var(--error)]/30 hover:!bg-[color:var(--error)]/10 !rounded-lg"
                                            >
                                                <i class="fas fa-times me-2"></i> {{ tr('Reject') }}
                                            </x-ui.secondary-button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-user-clock text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No pending permissions found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $pendingPermissionRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            {{-- Pending Cut Leave Requests --}}
            @if($pendingSubTab === 'cuts')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Pending Leave Cut Requests') }}</div>
                    <x-ui.badge class="text-xs">{{ $pendingCutLeaveRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Original Period') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($pendingCutLeaveRequests as $row)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $row->employee->name_ar ?? $row->employee->name_en ?? $row->employee->name ?? $row->employee->full_name ?? ('#' . $row->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $row->employee_id }}</div>
                                </td>
    
                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($row->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3 text-gray-700">
                                    <div class="font-mono text-xs">
                                        {{ company_date($row->original_start_date) }} - {{ company_date($row->original_end_date) }}
                                    </div>
                                </td>

                                <td class="p-3 font-bold text-gray-900">
                                    {{ company_date($row->cut_end_date) }}
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($row->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('cut', {{ $row->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        @php
                                            $currentEmpId = auth()->user()->employee_id;
                                            $isMyTask = $currentEmpId && $row->approvalTasks->where('status', 'pending')->where('approver_employee_id', $currentEmpId)->isNotEmpty();
                                        @endphp

                                        @if($isMyTask)
                                            <x-ui.primary-button
                                                type="button"
                                                wire:click.prevent="approveCutLeave({{ (int) $row->id }})"
                                                loading="approveCutLeave({{ (int) $row->id }})"
                                                :full-width="false"
                                                size="sm"
                                                class="px-5 py-2.5 text-xs font-bold"
                                            >
                                                <i class="fas fa-check me-2"></i>
                                                {{ tr('Approve') }}
                                            </x-ui.primary-button>

                                            <x-ui.secondary-button
                                                type="button"
                                                wire:click.prevent="openReject('cut_leave', {{ (int) $row->id }})"
                                                class="px-5 py-2.5 text-xs font-bold !text-[color:var(--error)] !border-[color:var(--error)] hover:!bg-[color:var(--error)]/10"
                                            >
                                                {{ tr('Reject') }}
                                            </x-ui.secondary-button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-cut text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No pending cut requests found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $pendingCutLeaveRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            {{-- Pending Mission Requests --}}
            @if($pendingSubTab === 'missions')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white xl:col-span-2 shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Pending Mission Requests') }}</div>
                    <x-ui.badge class="text-xs">{{ $pendingMissionRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Type') }}</th>
                            <th class="text-start p-3">{{ tr('Date') }}</th>
                            <th class="text-start p-3">{{ tr('Duration') }}</th>
                            <th class="text-start p-3">{{ tr('Destination') }}</th>
                            <th class="text-start p-3">{{ tr('Reason') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($pendingMissionRequests as $r)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $r->employee->name_ar ?? $r->employee->name_en ?? $r->employee->name ?? $r->employee->full_name ?? ('#' . $r->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $r->employee_id }}</div>
                                </td>

                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($r->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <x-ui.badge class="text-xs">
                                        {{ $r->type === 'full_day' ? tr('Full day') : tr('Hours') }}
                                    </x-ui.badge>
                                </td>

                                <td class="p-3 text-gray-700 font-mono text-xs">
                                    {{ company_date($r->start_date) }} {{ $r->end_date && $r->end_date !== $r->start_date ? '- '.company_date($r->end_date) : '' }}
                                </td>

                                <td class="p-3 font-bold text-gray-900">
                                    {{ $formatMissionDuration($r) }}
                                </td>

                                <td class="p-3 text-gray-700">
                                    {{ $r->destination ?: '-' }}
                                </td>

                                <td class="p-3 text-gray-700">
                                    {{ $r->reason ?: '-' }}
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($r->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('mission', {{ $r->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if($r->attachment_path)
                                            <a href="{{ asset('storage/' . $r->attachment_path) }}" target="_blank"
                                               class="p-2 text-xs font-bold bg-white text-[color:var(--accent-orange)] border border-[color:var(--accent-orange)]/20 rounded-lg shadow-sm hover:bg-[color:var(--accent-orange)]/10 transition-all font-mono"
                                               title="{{ $r->attachment_name ?: tr('View Attachment') }}">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        @endif

                                        @php
                                            $currentEmpId = auth()->user()->employee_id;
                                            $isMyTask = $currentEmpId && $r->approvalTasks->where('status', 'pending')->where('approver_employee_id', $currentEmpId)->isNotEmpty();
                                        @endphp

                                        @if($isMyTask)
                                            <x-ui.primary-button
                                                type="button"
                                                wire:click.prevent="approveMission({{ $r->id }})"
                                                loading="approveMission({{ $r->id }})"
                                                :full-width="false"
                                                size="sm"
                                                class="px-4 py-2 text-xs font-bold !rounded-lg shadow-sm"
                                            >
                                                <i class="fas fa-check me-2"></i>
                                                {{ tr('Approve') }}
                                            </x-ui.primary-button>

                                            <x-ui.secondary-button
                                                type="button"
                                                wire:click.prevent="openReject('mission', {{ $r->id }})"
                                                class="px-4 py-2 text-xs font-bold !text-[color:var(--error)] !border-[color:var(--error)]/30 hover:!bg-[color:var(--error)]/10 !rounded-lg"
                                            >
                                                <i class="fas fa-times me-2"></i>
                                                {{ tr('Reject') }}
                                            </x-ui.secondary-button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-plane-departure text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No pending mission requests found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $pendingMissionRequests->links() }}
                </div>
            </x-ui.card>
            @endif

        </div>
    @endif

    {{-- Balances by employee --}}
    @if($tab === 'balances')
        <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div class="font-extrabold text-gray-900">{{ tr('Leave Balances') }}</div>
                <x-ui.badge class="text-xs">{{ $balances->total() }}</x-ui.badge>
            </div>

            <div class="overflow-x-auto">
                <x-ui.table class="min-w-full text-sm" :enable-pagination="false">
                    <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-start p-3 w-12"></th>
                        <th class="text-start p-3">{{ tr('Employee') }}</th>
                        <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                        <th class="text-start p-3">{{ tr('Leave Types') }}</th>
                        <th class="text-start p-3">{{ tr('Taken') }}</th>
                        <th class="text-start p-3">{{ tr('Remaining') }}</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100" x-data="{ expanded: {} }">
                    @forelse($balances as $employeeBalance)
                        @php
                            $balanceRows = collect($employeeBalance->leave_balance_rows ?? []);
                            $summary = $employeeBalance->leave_balance_summary ?? ['taken' => 0, 'remaining' => 0];
                        @endphp
                        <tr wire:key="balance-employee-row-{{ $employeeBalance->id }}">
                            <td class="p-3">
                                <button
                                    type="button"
                                    x-on:click="expanded[{{ $employeeBalance->id }}] = !expanded[{{ $employeeBalance->id }}]"
                                    class="w-8 h-8 rounded-lg border border-gray-200 bg-white text-gray-500 hover:text-[color:var(--accent-orange)] hover:border-[color:var(--accent-orange)] transition-all"
                                    title="{{ tr('Show Details') }}"
                                >
                                    <i class="fas" x-bind:class="expanded[{{ $employeeBalance->id }}] ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                </button>
                            </td>

                            <td class="p-3">
                                <div class="font-bold text-gray-900">
                                    {{ $employeeBalance->name_ar ?? $employeeBalance->name_en ?? $employeeBalance->name ?? $employeeBalance->full_name ?? ('#' . $employeeBalance->id) }}
                                </div>
                                <div class="text-xs text-gray-400">{{ $employeeBalance->employee_no ?? ('#' . $employeeBalance->id) }}</div>
                            </td>

                            <td class="p-3">
                                @php
                                    $empStatus = strtoupper($employeeBalance->status ?? 'ACTIVE');
                                    $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                @endphp
                                <div class="flex items-center gap-1.5">
                                    <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                    <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                </div>
                            </td>

                            <td class="p-3">
                                <span class="inline-flex items-center px-2 py-1 rounded-lg bg-gray-100 text-gray-700 text-xs font-bold">
                                    {{ $balanceRows->count() }}
                                </span>
                            </td>

                            <td class="p-3 font-mono text-xs">{{ number_format((float) ($summary['taken'] ?? 0), 2) }}</td>
                            <td class="p-3 font-black text-gray-900">{{ number_format((float) ($summary['remaining'] ?? 0), 2) }}</td>
                        </tr>

                            <tr
                                x-show="expanded[{{ $employeeBalance->id }}]"
                                x-cloak
                                class="bg-gray-50/70"
                                wire:key="balance-employee-details-{{ $employeeBalance->id }}"
                            >
                                <td class="p-0"></td>
                                <td colspan="5" class="p-4">
                                    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                                        <table class="min-w-full text-xs">
                                            <thead class="bg-gray-50 text-gray-500">
                                            <tr>
                                                <th class="text-start p-3">{{ tr('Leave Type') }}</th>
                                                <th class="text-start p-3">{{ tr('Entitled') }}</th>
                                                <th class="text-start p-3">{{ tr('Taken') }}</th>
                                                <th class="text-start p-3">{{ tr('Remaining') }}</th>
                                                <th class="text-start p-3">{{ tr('Usage %') }}</th>
                                                <th class="text-start p-3">{{ tr('Actions') }}</th>
                                            </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                            @forelse($balanceRows as $row)
                                                <tr wire:key="balance-policy-row-{{ $employeeBalance->id }}-{{ $row->policy_id }}">
                                                    <td class="p-3 font-bold text-gray-800">{{ $row->policy_name }}</td>
                                                    <td class="p-3 font-mono">{{ number_format((float) $row->entitled_days, 2) }}</td>
                                                    <td class="p-3 font-mono">{{ number_format((float) $row->taken_days, 2) }}</td>
                                                    <td class="p-3 font-mono font-black text-gray-900">{{ number_format((float) $row->remaining_days, 2) }}</td>
                                                    <td class="p-3 font-mono text-gray-700">
                                                        {{ $row->usage_percentage === null ? '-' : number_format((float) $row->usage_percentage, 2) . '%' }}
                                                    </td>
                                                    <td class="p-3">
                                                        @if($row->balance_id)
                                                            <x-ui.secondary-button
                                                                type="button"
                                                                wire:click="openBalanceAudit({{ $row->balance_id }})"
                                                                class="px-2 py-1 text-[10px] font-bold border-[color:var(--accent-orange)]/20 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10"
                                                                title="{{ tr('Audit History') }}"
                                                            >
                                                                <i class="fas fa-history"></i>
                                                            </x-ui.secondary-button>
                                                        @else
                                                            <span class="text-gray-400">-</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="p-4 text-center text-gray-500">
                                                        {{ tr('No leave policies found.') }}
                                                    </td>
                                                </tr>
                                            @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-4 text-center text-gray-500">
                                {{ tr('No employees found.') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            <div class="p-3 border-t border-gray-100">
                {{ $balances->links() }}
            </div>
        </x-ui.card>
    @endif

    {{-- Balances --}}
    @if(false && $tab === 'balances')
        <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div class="font-extrabold text-gray-900">{{ tr('Leave Balances') }}</div>
                <x-ui.badge class="text-xs">{{ $balances->total() }}</x-ui.badge>
            </div>

            <div class="overflow-x-auto">
                <x-ui.table class="min-w-full text-sm" :enable-pagination="false">
                    <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-start p-3">{{ tr('Employee') }}</th>
                        <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                        <th class="text-start p-3">{{ tr('Leave Type & Balance') }}</th>
                        <th class="text-start p-3">{{ tr('Entitled') }}</th>
                        <th class="text-start p-3">{{ tr('Taken') }}</th>
                        <th class="text-start p-3">{{ tr('Remaining') }}</th>
                        <th class="text-start p-3">{{ tr('Usage %') }}</th>
                        <th class="text-start p-3">{{ tr('Actions') }}</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                    @forelse($balances as $b)
                        <tr>
                            <td class="p-3">
                                <div class="font-bold text-gray-900">
                                    {{ $b->employee->name_ar ?? $b->employee->name_en ?? $b->employee->name ?? $b->employee->full_name ?? ('#' . $b->employee_id) }}
                                </div>
                                <div class="text-xs text-gray-400">#{{ $b->employee_id }}</div>
                            </td>

                            <td class="p-3">
                                @php
                                    $empStatus = strtoupper($b->employee->status ?? 'ACTIVE');
                                    $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                @endphp
                                <div class="flex items-center gap-1.5">
                                    <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                    <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                </div>
                            </td>

                            <td class="p-3 font-semibold text-gray-800">{{ $b->policy->name ?? '-' }}</td>

                            <td class="p-3 font-mono text-xs">{{ number_format((float) $b->entitled_days, 2) }}</td>
                            <td class="p-3 font-mono text-xs">{{ number_format((float) $b->taken_days, 2) }}</td>

                            <td class="p-3 font-black text-gray-900">{{ number_format((float) $b->remaining_days, 2) }}</td>

                            <td class="p-3 font-mono text-xs text-gray-700">
                                @php
                                    $entitled = (float) $b->entitled_days;
                                    $taken = (float) $b->taken_days;
                                    $usage = $entitled > 0 ? ($taken / $entitled) * 100 : null;
                                @endphp
                                {{ $usage === null ? '-' : number_format($usage, 2) . '%' }}
                            </td>

                             <td class="p-3">
                                <x-ui.secondary-button
                                    type="button"
                                    wire:click="openBalanceAudit({{ $b->id }})"
                                    class="px-2 py-1 text-[10px] font-bold border-[color:var(--accent-orange)]/20 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10"
                                    title="{{ tr('Audit History') }}"
                                >
                                    <i class="fas fa-history"></i>
                                </x-ui.secondary-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-4 text-center text-gray-500">
                                {{ tr('No balances yet. Balances will be created automatically after approvals.') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            <div class="p-3 border-t border-gray-100">
                {{ $balances->links() }}
            </div>
        </x-ui.card>
    @endif

    {{-- History (Previous Operations) --}}
    @if($tab === 'history')
        <div class="space-y-6">
            {{-- History Sub Tabs --}}
            <div class="flex items-center gap-6 border-b border-gray-200 overflow-x-auto">
                <button wire:click="setHistorySubTab('leaves')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative whitespace-nowrap {{ $historySubTab === 'leaves' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Previous Leave Requests') }}</span>
                    @if($historySubTab === 'leaves') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setHistorySubTab('permissions')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative whitespace-nowrap {{ $historySubTab === 'permissions' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Previous Permissions') }}</span>
                    @if($historySubTab === 'permissions') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setHistorySubTab('missions')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative whitespace-nowrap {{ $historySubTab === 'missions' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Previous Mission Requests') }}</span>
                    @if($historySubTab === 'missions') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setHistorySubTab('cuts')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative whitespace-nowrap {{ $historySubTab === 'cuts' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Previous Cut Requests') }}</span>
                    @if($historySubTab === 'cuts') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>

                <button wire:click="setHistorySubTab('activity')" 
                    onclick="showTabLoadingBar()"
                    class="pb-3 text-sm font-bold transition-all relative whitespace-nowrap {{ $historySubTab === 'activity' ? 'text-[color:var(--accent-orange)]' : 'text-gray-400 hover:text-gray-600' }}">
                    <span>{{ tr('Activity Log') }}</span>
                    @if($historySubTab === 'activity') <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-[color:var(--accent-orange)] rounded-full"></div> @endif
                </button>
            </div>

            <div class="grid grid-cols-1 gap-6">

            {{-- Previous Leave Requests --}}
            @if($historySubTab === 'leaves')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Previous Leave Requests') }}</div>
                    <x-ui.badge class="text-xs">{{ ($previousLeaveRequests ?? $history)->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee & Replacement') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Leave Type & Balance') }}</th>
                            <th class="text-start p-3">{{ tr('Date') }}</th>
                            <th class="text-start p-3">{{ tr('Duration') }}</th>
                            <th class="text-start p-3">{{ tr('Reason') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Status') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse(($previousLeaveRequests ?? []) as $r)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $r->employee?->name_ar ?? $r->employee?->name_en ?? $r->employee?->name ?? $r->employee?->full_name ?? ('#' . $r->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $r->employee_id }}</div>
                                    @if($r->replacement_employee_id)
                                        <div class="mt-2 pt-2 border-t border-dashed border-gray-100 italic text-[10px] text-gray-500">
                                            <i class="fas fa-exchange-alt mr-1"></i>
                                            {{ tr('Replacement') }}: 
                                            <span class="font-bold text-gray-700">
                                                {{ $r->replacementEmployee?->name_ar ?? $r->replacementEmployee?->name_en ?? $r->replacementEmployee?->name ?? ('#' . $r->replacement_employee_id) }}
                                            </span>
                                        </div>
                                    @endif
                                </td>

                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($r->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="font-semibold text-gray-800">
                                        {{ $r->policy?->name ?? tr('Group absence') }}
                                    </div>
                                    @php
                                        $yearId = $r->policy_year_id ?: $selectedYearId;
                                        $balance = \Athka\Attendance\Models\AttendanceLeaveBalance::where('employee_id', $r->employee_id)
                                                    ->where('leave_policy_id', $r->leave_policy_id)
                                                    ->where('policy_year_id', $yearId)
                                                    ->first();
                                        $remaining = $balance ? (float) $balance->remaining_days : 0;
                                    @endphp
                                    @if($r->policy)
                                        <div class="mt-1 text-[10px] text-gray-500 font-bold">
                                            {{ tr('Remaining Balance') }}: <span class="{{ $remaining > 0 ? 'text-[color:var(--success)]' : 'text-[color:var(--error)]' }}">{{ $smartNumber($remaining) }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td class="p-3 text-gray-700">
                                    <div class="font-mono text-[10px] whitespace-nowrap">
                                        {{ company_date($r->start_date) }}<br/>- {{ company_date($r->end_date) }}
                                    </div>
                                </td>

                            <td class="p-3 font-bold text-gray-900">
                                {{ $formatLeaveDuration($r) }}
                            </td>


                                <td class="p-3 text-gray-700">
                                    @php
                                        $reason = $r->status === 'rejected'
                                            ? ($r->reject_reason ?? null)
                                            : ($r->reason ?? null);
                                    @endphp
                                    <div class="max-w-[150px] truncate" title="{{ $reason }}">
                                        {{ $reason ?: '-' }}
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($r->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('leave', {{ $r->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <x-ui.badge class="text-xs">
                                        {{ tr($r->status) }}
                                    </x-ui.badge>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-1.5">
                                        @if($r->attachment_path)
                                            <a href="{{ asset('storage/' . $r->attachment_path) }}" target="_blank"
                                               class="p-2 text-xs font-bold bg-white text-[color:var(--accent-orange)] border border-[color:var(--accent-orange)]/20 rounded-lg shadow-sm hover:bg-[color:var(--accent-orange)]/10 transition-all font-mono"
                                               title="{{ $r->attachment_name ?: tr('View Attachment') }}">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        @endif

                                        @if(in_array($r->status, ['approved','pending'], true))
                                            @if($canManageLeaveRequests)
                                            <x-ui.secondary-button
                                                type="button"
                                                @click.prevent="$dispatch('open-confirm-cancel-leave-dialog', { id: {{ $r->id }} })"
                                                class="px-3 py-1.5 text-xs font-bold bg-white text-[color:var(--error)] border-[color:var(--error)]/20 hover:bg-[color:var(--error)]/10 !rounded-lg shadow-sm transition-all"
                                            >
                                                {{ tr('Cancel') }}
                                            </x-ui.secondary-button>
                                            @else
                                                <span class="text-xs text-gray-400 italic">{{ tr('Locked') }}</span>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-history text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No previous leave requests found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $previousLeaveRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            {{-- Previous Permissions --}}
            @if($historySubTab === 'permissions')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Previous Permissions') }}</div>
                    <x-ui.badge class="text-xs">{{ $previousPermissionRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Date & Time') }}</th>
                            <th class="text-start p-3">{{ tr('Reason') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Status') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($previousPermissionRequests as $r)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $r->employee->name_ar ?? $r->employee->name_en ?? $r->employee->name ?? $r->employee->full_name ?? ('#' . $r->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $r->employee_id }}</div>
                                </td>

                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($r->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="text-xs text-gray-600">
                                        {{ company_date($r->permission_date) }}
                                    </div>
                                    <div class="mt-1 font-mono text-xs text-gray-800 font-bold">
                                        {{ $r->from_time ?? '--:--' }} - {{ $r->to_time ?? '--:--' }}
                                    </div>
                                    <div class="mt-1 text-[10px] font-bold text-gray-400">
                                        {{ (int) $r->minutes }} {{ tr('min') }}
                                    </div>
                                </td>

                                <td class="p-3 text-gray-700">
                                    <div class="max-w-[150px] truncate" title="{{ $r->reason }}">
                                        {{ $r->reason ?: '-' }}
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($r->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('permission', {{ $r->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <x-ui.badge class="text-xs">
                                        {{ tr($r->status) }}
                                    </x-ui.badge>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-1.5">
                                        @if($r->attachment_path)
                                            <a href="{{ asset('storage/' . $r->attachment_path) }}" target="_blank"
                                               class="p-2 text-xs font-bold bg-white text-[color:var(--accent-orange)] border border-[color:var(--accent-orange)]/20 rounded-lg shadow-sm hover:bg-[color:var(--accent-orange)]/10 transition-all font-mono"
                                               title="{{ $r->attachment_name ?: tr('View Attachment') }}">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        @endif
                                        <span class="text-xs text-gray-400">-</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-history text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No previous permissions found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $previousPermissionRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            @if($historySubTab === 'cuts')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Previous Leave Cut Requests') }}</div>
                    <x-ui.badge class="text-xs">{{ $previousCutLeaveRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Original Period') }}</th>
                            <th class="text-start p-3">{{ tr('Cut End Date') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Status') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($previousCutLeaveRequests as $row)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $row->employee->name_ar ?? $row->employee->name_en ?? $row->employee->name ?? $row->employee->full_name ?? ('#' . $row->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $row->employee_id }}</div>
                                </td>
    
                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($row->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3 text-gray-700">
                                    <div class="font-mono text-xs">
                                        {{ company_date($row->original_start_date) }} - {{ company_date($row->original_end_date) }}
                                    </div>
                                </td>

                                <td class="p-3 font-bold text-gray-900">
                                    {{ company_date($row->cut_end_date) }}
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($row->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('cut', {{ $row->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <x-ui.badge class="text-xs">
                                        {{ tr($row->status) }}
                                    </x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-3">
                                        <i class="fas fa-history text-3xl opacity-20"></i>
                                        <span class="italic">{{ tr('No previous cut requests found') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $previousCutLeaveRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            {{-- Previous Mission Requests --}}
            @if($historySubTab === 'missions')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white xl:col-span-2 shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Previous Mission Requests') }}</div>
                    <x-ui.badge class="text-xs">{{ $previousMissionRequests->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Type') }}</th>
                            <th class="text-start p-3">{{ tr('Date') }}</th>
                            <th class="text-start p-3">{{ tr('Duration') }}</th>
                            <th class="text-start p-3">{{ tr('Reason') }}</th>
                            <th class="text-start p-3">{{ tr('Workflow') }}</th>
                            <th class="text-start p-3">{{ tr('Status') }}</th>
                            <th class="text-start p-3">{{ tr('Actions') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($previousMissionRequests as $r)
                            <tr>
                                <td class="p-3">
                                    <div class="font-bold text-gray-900">
                                        {{ $r->employee->name_ar ?? $r->employee->name_en ?? $r->employee->name ?? $r->employee->full_name ?? ('#' . $r->employee_id) }}
                                    </div>
                                    <div class="text-xs text-gray-400">#{{ $r->employee_id }}</div>
                                </td>

                                <td class="p-3">
                                    @php
                                        $empStatus = strtoupper($r->employee->status ?? 'ACTIVE');
                                        $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                        <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <x-ui.badge class="text-xs">
                                        {{ $r->type === 'full_day' ? tr('Full day') : tr('Hours') }}
                                    </x-ui.badge>
                                </td>

                                <td class="p-3 text-gray-700 font-mono text-xs">
                                    {{ company_date($r->start_date) }} {{ $r->end_date && $r->end_date !== $r->start_date ? '- '.company_date($r->end_date) : '' }}
                                </td>

                                <td class="p-3 font-bold text-gray-900">
                                    {{ $formatMissionDuration($r) }}
                                </td>

                                <td class="p-3 text-gray-700">
                                    <div class="max-w-[150px] truncate" title="{{ $r->reason }}">
                                        {{ $r->reason ?: '-' }}
                                    </div>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex -space-x-2 overflow-hidden">
                                            @foreach($r->approvalTasks as $task)
                                                <div class="w-7 h-7 rounded-full border-2 border-white flex items-center justify-center text-[10px] font-bold text-white shadow-sm transition-all hover:scale-110 hover:z-10
                                                    {{ $approvalTaskClass($task->status) }}"
                                                    title="{{ ($task->approver?->name ?? $task->approver_name) . ': ' . tr($task->status) }}">
                                                    @if($task->status === 'approved') <i class="fas fa-check"></i>
                                                    @elseif($task->status === 'rejected') <i class="fas fa-times"></i>
                                                    @elseif($task->status === 'pending') <i class="fas fa-hourglass-half"></i>
                                                    @else <i class="fas fa-minus"></i> @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <button wire:click="openWorkflow('mission', {{ $r->id }})" class="p-1.5 text-[color:var(--accent-orange)] hover:bg-[color:var(--accent-orange)]/10 rounded-lg transition-all" title="{{ tr('View sequence') }}">
                                            <i class="fas fa-project-diagram text-base"></i>
                                        </button>
                                    </div>
                                </td>

                                <td class="p-3">
                                    <x-ui.badge class="text-xs">
                                        {{ tr($r->status) }}
                                    </x-ui.badge>
                                </td>

                                <td class="p-3">
                                    <div class="flex items-center gap-1.5">
                                        @if($r->attachment_path)
                                            <a href="{{ asset('storage/' . $r->attachment_path) }}" target="_blank"
                                               class="p-2 text-xs font-bold bg-white text-[color:var(--accent-orange)] border border-[color:var(--accent-orange)]/20 rounded-lg shadow-sm hover:bg-[color:var(--accent-orange)]/10 transition-all font-mono"
                                               title="{{ $r->attachment_name ?: tr('View Attachment') }}">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-4 text-center text-gray-500">
                                    {{ tr('No previous mission requests') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $previousMissionRequests?->links() }}
                </div>
            </x-ui.card>
            @endif

            {{-- Activity Log --}}
            @if($historySubTab === 'activity')
            <x-ui.card class="overflow-hidden border border-gray-200 rounded-2xl bg-white shadow-sm">
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="font-extrabold text-gray-900">{{ tr('Activity Log') }}</div>
                    <x-ui.badge class="text-xs">{{ $history->total() }}</x-ui.badge>
                </div>

                <div class="overflow-x-auto">
                    <x-ui.table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-start p-3">{{ tr('When') }}</th>
                            <th class="text-start p-3">{{ tr('Approved By') }}</th>
                            <th class="text-start p-3">{{ tr('Employee') }}</th>
                            <th class="text-start p-3">{{ tr('Employee Status') }}</th>
                            <th class="text-start p-3">{{ tr('Type') }}</th>
                            <th class="text-start p-3">{{ tr('Action') }}</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                        @forelse($history as $h)
                            <tr>
                                    <td class="p-3 text-xs font-mono text-gray-600">
                                        {{ company_date($h->created_at, 'Y-m-d H:i') }}
                                    </td>

                                <td class="p-3 text-gray-800 font-semibold">
                                    {{ $h->actor->name ?? ('#' . ($h->actor_user_id ?? '-')) }}
                                </td>

                                <td class="p-3 text-gray-800">
                                    {{ $h->employee->name_ar ?? $h->employee->name_en ?? $h->employee->name ?? $h->employee->full_name ?? ($h->employee_id ? '#' . $h->employee_id : '-') }}
                                </td>

                                <td class="p-3">
                                    @if($h->employee)
                                        @php
                                            $empStatus = strtoupper($h->employee->status ?? 'ACTIVE');
                                            $empStatusColor = \Athka\Employees\Support\EmployeeStatus::color($empStatus);
                                        @endphp
                                        <div class="flex items-center gap-1.5">
                                            <div class="w-2 h-2 rounded-full bg-{{ $empStatusColor }}-500"></div>
                                            <span class="text-[10px] text-{{ $empStatusColor }}-700 font-bold uppercase">{{ \Athka\Employees\Support\EmployeeStatus::label($empStatus) }}</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400">-</span>
                                    @endif
                                </td>

                                <td class="p-3 text-gray-700">
                                    @php
                                        $type = strtolower((string) $h->subject_type);
                                        $typeMap = [
                                            'leave' => tr('Leave'),
                                            'permission' => tr('Permission'),
                                            'mission' => tr('Mission'),
                                            'leave_cut' => tr('Leave Cut'),
                                            'cut_leave' => tr('Leave Cut'),
                                            'replacement' => tr('Replacement'),
                                        ];
                                    @endphp
                                    {{ $typeMap[$type] ?? tr($h->subject_type) }}
                                </td>

                                <td class="p-3 text-gray-900 font-bold">{{ tr($h->action) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-4 text-center text-gray-500">
                                    {{ tr('No history yet') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="p-3 border-t border-gray-100">
                    {{ $history?->links() }}
                </div>
            </x-ui.card>
            @endif

            </div>
        </div>
    @endif

    {{-- Create Leave Modal --}}
    <x-ui.modal wire:model="createLeaveOpen" max-width="lg">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] rounded-lg">
                    <i class="fas fa-calendar-plus text-lg"></i>
                </div>
                <div>
                    <div class="font-black text-gray-900">{{ tr('New Leave Request') }}</div>
                    <div class="text-[10px] text-gray-500 font-medium">{{ tr('Submit a new absence request') }}</div>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">


                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div wire:key="create-leave-emp-select">
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Employee') }}</div>
                        <x-ui.select wire:model.live="employee_id" class="w-full" :disabled="!$canManageLeaveRequests">
                            <option value="0">--</option>
                            @foreach($employeesForSelect as $e)
                                <option value="{{ $e->id }}">
                                    {{ trim(($e->employee_no ? $e->employee_no.' - ' : '') . ($e->name_ar ?? $e->name_en ?? $e->name ?? $e->full_name ?? '')) ?: ('#'.$e->id) }}

                                </option>
                            @endforeach
                        </x-ui.select>
                        @error('employee_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Policy') }}</div>
                      <div wire:key="leave-policy-select-{{ (int) $employee_id }}">
                            <x-ui.select wire:model.live="leave_policy_id" class="w-full" :disabled="!$canManageLeaveRequests">
                                <option value="0">--</option>
                                @foreach($createLeavePolicies as $p)
                                    <option value="{{ $p->id }}">{{ $p->name ?? $p->label ?? ('#'.$p->id) }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>


                        @if($employee_id > 0 && $createLeavePolicies->isEmpty())
                            <div class="text-[11px] text-[color:var(--warning)] mt-1">
                                {{ tr('No leave policies available for this employee.') }}
                            </div>
                        @endif

                        @error('leave_policy_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Duration (from policy settings) --}}
                @if($leave_policy_id > 0)
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-3">
                        <div class="text-[11px] text-gray-500 font-bold mb-1">{{ tr('Duration') }}</div>

                        <div class="text-sm font-black text-gray-900">
                            @if($create_leave_duration_unit === 'full_day')
                                {{ tr('Full day') }}
                            @elseif($create_leave_duration_unit === 'half_day')
                                {{ tr('Half day') }}
                            @else
                                {{ tr('Hours') }}
                            @endif
                        </div>

                        @if($create_leave_duration_unit === 'hours' && $leave_minutes > 0)
                            <div class="text-[11px] text-gray-600 mt-1">
                                {{ tr('Total minutes') }}: <span class="font-bold">{{ (int) $leave_minutes }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Date/Time fields based on duration unit --}}
                @if($create_leave_duration_unit === 'full_day')
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Start Date') }}</div>
                            <x-ui.company-date-picker model="start_date" :disabled="!$canManageLeaveRequests" />
                            @error('start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('End Date') }}</div>
                            <x-ui.company-date-picker model="end_date" :disabled="!$canManageLeaveRequests" />
                            @error('end_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                @elseif($create_leave_duration_unit === 'half_day')
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Date') }}</div>
                            <x-ui.company-date-picker model="start_date" />
                            @error('start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Work period') }}</div>
                            @php($leaveWorkPeriodOptions = $this->leaveWorkPeriodOptions)

                            @if((int) $employee_id <= 0 || trim($start_date) === '')
                                <div class="text-xs text-gray-500 bg-gray-50 border border-gray-100 rounded-lg px-3 py-2">
                                    {{ tr('Please select employee and date first.') }}
                                </div>
                            @elseif(count($leaveWorkPeriodOptions) <= 1)
                                <div class="text-xs text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                                    {{ tr('Half-day leave is only available when the employee schedule has more than one work period.') }}
                                </div>
                            @else
                                <x-ui.select wire:model="leave_work_schedule_period_id" class="w-full" :disabled="!$canManageLeaveRequests">
                                    <option value="0">{{ tr('Select work period') }}</option>
                                    @foreach($leaveWorkPeriodOptions as $period)
                                        <option value="{{ (int) $period['id'] }}">{{ $period['label'] }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif

                            @error('leave_work_schedule_period_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                @else
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Date') }}</div>
                            <x-ui.company-date-picker model="start_date" />
                            @error('start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                        @php
                        // Passed from the server after columns are known
                        $workStart = $workStart ?? '08:00';
                        $workEnd   = $workEnd   ?? '16:00';
                        @endphp


                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('From') }}</div>
                                <x-ui.input type="time" wire:model.blur="leave_from_time" class="w-full"
                                    min="{{ $workStart }}" max="{{ $workEnd }}" step="60" />

                            @error('leave_from_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('To') }}</div>
                            <x-ui.input type="time" wire:model.blur="leave_to_time" class="w-full"
                                min="{{ $workStart }}" max="{{ $workEnd }}" step="60" :disabled="!$canManageLeaveRequests" />
                            @error('leave_to_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endif

                {{-- Policy Note --}}
                @if(trim($create_leave_note_text) !== '')
                    <div class="bg-[color:var(--warning)]/10 border border-[color:var(--warning)]/20 rounded-xl p-3">
                        <div class="text-[11px] text-[color:var(--warning)] font-bold mb-1">
                            {{ tr('Note') }}
                        </div>
                        <div class="text-sm text-gray-800 leading-relaxed">
                            {{ $create_leave_note_text }}
                        </div>

                        @if($create_leave_note_ack_required)
                            <label class="flex items-center gap-2 mt-3 text-sm text-gray-800">
                                <input type="checkbox" wire:model="leave_note_ack" class="rounded border-[color:var(--warning)]/30 text-[color:var(--accent-orange)] focus:ring-[color:var(--accent-orange)]" @if(!$canManageLeaveRequests) disabled @endif />
                                <span class="font-semibold">{{ tr('I acknowledge this note') }}</span>
                            </label>
                            @error('leave_note_ack') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        @endif
                    </div>
                @endif

                {{-- Attachment (required by policy OR note exists) --}}
                @if($create_leave_attachment_required)
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-3">
                        <div class="text-[11px] text-gray-500 font-bold mb-1">{{ tr('Attachment') }}</div>

                        <input
                            type="file"
                            wire:model="leave_attachment"
                            class="block w-full text-sm text-gray-700
                                file:mr-3 file:py-2 file:px-3
                                file:rounded-lg file:border-0
                                file:bg-white file:text-gray-700
                                hover:file:bg-gray-50"
                            accept="{{ collect($create_leave_attachment_types)->map(fn($t)=>'.'.$t)->implode(',') }}"
                            @disabled(!$canManageLeaveRequests)
                        />

                        <div class="text-[11px] text-gray-500 mt-2">
                            {{ tr('Allowed') }}: {{ implode(', ', $create_leave_attachment_types) }}
                            - {{ tr('Max') }}: {{ (int) $create_leave_attachment_max_mb }}MB
                        </div>

                        <div wire:loading wire:target="leave_attachment" class="text-[11px] text-[color:var(--accent-orange)] mt-2">
                            {{ tr('Uploading...') }}
                        </div>

                        @error('leave_attachment') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                @endif

                <div wire:key="create-leave-rep-select-{{ (int) $employee_id }}">
                    <div class="text-xs text-gray-500 mb-1">{{ tr('Replacement Employee') }} ({{ tr('Optional') }})</div>
                    <x-ui.select wire:model.live="replacement_employee_id" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="0">--</option>
                        @foreach($replacementEmployees as $e)
                            <option value="{{ $e->id }}">
                                {{ trim(($e->employee_no ? $e->employee_no.' - ' : '') . ($e->name_ar ?? $e->name_en ?? $e->name ?? $e->full_name ?? '')) ?: ('#'.$e->id) }}
                            </option>
                        @endforeach
                    </x-ui.select>
                    @error('replacement_employee_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                </div>

                <div>
                    <div class="text-xs text-gray-500 mb-1">{{ tr('Reason') }}</div>
                    <x-ui.textarea wire:model="reason" class="w-full" :disabled="!$canManageLeaveRequests" />
                    @error('reason') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3">
                <x-ui.secondary-button 
                    type="button" 
                    wire:click="closeCreateLeave"
                    :full-width="false"
                    size="sm"
                >
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                @if($canManageLeaveRequests)
                <x-ui.primary-button 
                    type="button" 
                    wire:click="saveLeave" 
                    :loading="'saveLeave'"
                    :full-width="false"
                    size="sm"
                    class="!px-10"
                >
                    {{ tr('Save') }}
                </x-ui.primary-button>
                @endif
            </div>
        </x-slot>
    </x-ui.modal>

    {{-- Create Permission Modal --}}
    <x-ui.modal wire:model="createPermissionOpen" max-width="lg">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-[color:var(--warning)]/10 text-[color:var(--warning)] rounded-lg">
                    <i class="fas fa-clock text-lg"></i>
                </div>
                <div>
                    <div class="font-black text-gray-900">{{ tr('New Permission') }}</div>
                    <div class="text-[10px] text-gray-500 font-medium">{{ tr('Request short time-off signature') }}</div>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <div class="text-xs text-gray-500 mb-1">{{ tr('Employee') }}</div>
                    <x-ui.select wire:model="permission_employee_id" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="0">--</option>
                        @foreach($employeesForSelect as $e)
                            <option value="{{ $e->id }}">
                                {{ trim(($e->employee_no ? $e->employee_no.' - ' : '') . ($e->name_ar ?? $e->name_en ?? $e->name ?? $e->full_name ?? '')) ?: ('#'.$e->id) }}

                            </option>
                        @endforeach
                    </x-ui.select>
                    @error('permission_employee_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Date') }}</div>
                        <x-ui.company-date-picker model="permission_date" :disabled="!$canManageLeaveRequests" />
                        @error('permission_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('From') }}</div>
                        <x-ui.input type="time" wire:model="from_time" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('from_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('To') }}</div>
                        <x-ui.input type="time" wire:model="to_time" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('to_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Minutes') }}</div>

                        <x-ui.input
                            type="number"
                            min="0"
                            wire:model="minutes"
                            class="w-full"
                            readonly
                        />

                        @error('minutes') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>


                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Reason') }}</div>
                        <x-ui.input type="text" wire:model="permission_reason" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('permission_reason') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3">
                <x-ui.secondary-button 
                    type="button" 
                    wire:click="closeCreatePermission"
                    :full-width="false"
                    size="sm"
                >
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                @if($canManageLeaveRequests)
                <x-ui.primary-button 
                    type="button" 
                    wire:click="savePermission" 
                    :loading="'savePermission'"
                    :full-width="false"
                    size="sm"
                    class="!px-10"
                >
                    {{ tr('Save') }}
                </x-ui.primary-button>
                @endif
            </div>
        </x-slot>
    </x-ui.modal>

    {{-- Reject Modal --}}
    <x-ui.modal wire:model="rejectOpen" max-width="md">
        <x-slot name="title">{{ tr('Reject') }}</x-slot>

        <x-slot name="content">
            <div class="space-y-3">
                <x-ui.textarea wire:model="rejectReason" class="w-full" :placeholder="tr('Please provide a reason...')" />
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3">
                <x-ui.secondary-button 
                    type="button" 
                    wire:click="closeReject"
                    :full-width="false"
                    size="sm"
                >
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                <x-ui.primary-button 
                    type="button" 
                    wire:click="confirmReject" 
                    :loading="'confirmReject'"
                    :full-width="false"
                    size="sm"
                    class="!px-10"
                >
                    {{ tr('Confirm') }}
                </x-ui.primary-button>
            </div>
        </x-slot>
    </x-ui.modal>

    {{-- Create Group Leave Modal --}}
    <x-ui.modal wire:model="createGroupLeaveOpen" max-width="4xl">
        <x-slot name="title">
             <div class="flex items-center gap-3">
                <div class="p-2 bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] rounded-lg">
                    <i class="fas fa-users text-lg"></i>
                </div>
                <div>
                    <div class="font-black text-gray-900">{{ tr('New Group Leave') }}</div>
                    <div class="text-[10px] text-gray-500 font-medium">{{ tr('Apply absence with mass selection') }}</div>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
         <div class="space-y-4">

            <div class="text-xs text-gray-500 mb-1 font-bold">{{ tr('Reason') }}</div>
            <x-ui.textarea
                wire:model.defer="group_reason"
                rows="3"
                class="w-full"
                placeholder="{{ tr('Type the reason for the leave...') }}"
                :disabled="!$canManageLeaveRequests"
            />

                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input
                        type="checkbox"
                        wire:model.live="group_leave_deduct_from_balance"
                        class="rounded border-gray-300 text-[color:var(--accent-orange)] focus:ring-[color:var(--accent-orange)]"
                        @if(!$canManageLeaveRequests) disabled @endif
                    />
                    <span class="font-semibold">{{ tr('Deduct from leave balance') }}</span>
                </label>

                @if($group_leave_deduct_from_balance)
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Leave Type') }}</div>
                        <x-ui.select wire:model.live="group_leave_policy_id" class="w-full">
                            <option value="0">--</option>
                            @foreach(($policies ?? []) as $p)
                                @if($p)
                                    <option value="{{ $p->id }}">{{ $p->name ?? $p->label ?? ('#'.$p->id) }}</option>
                                @endif
                            @endforeach
                        </x-ui.select>
                        @error('group_leave_policy_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                @endif

                @if($group_leave_deduct_from_balance && $group_leave_policy_id > 0)
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-3">
                        <div class="text-[11px] text-gray-500 font-bold mb-1">{{ tr('Duration') }}</div>

                        <div class="text-sm font-black text-gray-900">
                            @if($group_leave_duration_unit === 'full_day')
                                {{ tr('Full day') }}
                            @elseif($group_leave_duration_unit === 'half_day')
                                {{ tr('Half day') }}
                            @else
                                {{ tr('Hours') }}
                            @endif
                        </div>

                        @if($group_leave_duration_unit === 'hours' && $group_leave_minutes > 0)
                            <div class="text-[11px] text-gray-600 mt-1">
                                {{ tr('Total minutes') }}: <span class="font-bold">{{ (int) $group_leave_minutes }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Date/Time fields based on duration unit --}}
                @if($group_leave_duration_unit === 'full_day')
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Start Date') }}</div>
                            <x-ui.company-date-picker model="group_start_date" />
                            @error('group_start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('End Date') }}</div>
                            <x-ui.company-date-picker model="group_end_date" />
                            @error('group_end_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                @elseif($group_leave_duration_unit === 'half_day')
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Date') }}</div>
                            <x-ui.company-date-picker model="group_start_date" />
                            @error('group_start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Half day') }}</div>
                            <x-ui.select wire:model="group_leave_half_day_part" class="w-full">
                                <option value="first_half">{{ tr('First half') }}</option>
                                <option value="second_half">{{ tr('Second half') }}</option>
                            </x-ui.select>
                            @error('group_leave_half_day_part') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                @else
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('Date') }}</div>
                            <x-ui.company-date-picker model="group_start_date" />
                            @error('group_start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('From') }}</div>
                            <x-ui.input type="time" wire:model.defer="group_leave_from_time" class="w-full"
                                        min="{{ $workStart }}" max="{{ $workEnd }}" step="60" />
                            @error('group_leave_from_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 mb-1">{{ tr('To') }}</div>
                            <x-ui.input type="time" wire:model.defer="group_leave_to_time" class="w-full"
                                        min="{{ $workStart }}" max="{{ $workEnd }}" step="60" />
                            @error('group_leave_to_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endif


                <x-ui.card class="p-3 border border-gray-200 rounded-2xl bg-gray-50">
                    <div class="font-bold text-gray-900 mb-2">{{ tr('Select Employees') }}</div>

                   <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3 mb-3">
    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Search') }}</div>
        <x-ui.input type="text" wire:model.live="groupEmployeeSearch" class="w-full" :disabled="!$canManageLeaveRequests" />
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Branch') }}</div>
        <x-ui.select wire:model.live="groupBranchId" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Branches') }}</option>
            @foreach(($branches ?? []) as $br)
                <option value="{{ $br->id }}">
                    {{ ($br->name ?? ('#'.$br->id)) }}{{ !empty($br->code) ? ' - '.$br->code : '' }}
                </option>
            @endforeach
        </x-ui.select>
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Department') }}</div>
        <x-ui.select wire:model.live="groupDepartmentId" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Departments') }}</option>
            @foreach(($departments ?? []) as $d)
                <option value="{{ $d->id }}">{{ $d->label ?? $d->name ?? ('#'.$d->id) }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Job Title') }}</div>
        <x-ui.select wire:model.live="groupJobTitleId" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Job Titles') }}</option>
            @foreach(($jobTitles ?? []) as $jt)
                <option value="{{ $jt->id }}">{{ $jt->label ?? $jt->name ?? ('#'.$jt->id) }}</option>
            @endforeach
        </x-ui.select>
    </div>

 <div>
    <div class="text-xs text-gray-500 mb-1">{{ tr('Contract Type') }}</div>
    <x-ui.select wire:model.live="groupContractType" class="w-full" :disabled="!$canManageLeaveRequests">
        <option value="">{{ tr('All Contract Types') }}</option>
        <option value="permanent">{{ tr('Permanent') }}</option>
        <option value="temporary">{{ tr('Temporary') }}</option>
        <option value="probation">{{ tr('Probation') }}</option>
        <option value="contractor">{{ tr('Contractor') }}</option>
    </x-ui.select>
</div>
</div>

                    <div class="max-h-64 overflow-auto bg-white rounded-xl border border-gray-200 p-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            @foreach($groupEmployeesForSelect as $e)
                                <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 border border-transparent hover:border-gray-100 transition-all cursor-pointer group">
                                    <input type="checkbox" value="{{ $e->id }}" wire:model="groupEmployeeIds" 
                                        class="w-4 h-4 rounded border-gray-300 text-[color:var(--accent-orange)] focus:ring-[color:var(--accent-orange)]"
                                        @if(!$canManageLeaveRequests) disabled @endif />
                                    <div class="flex flex-col">
                                        <span class="text-xs font-bold text-gray-800 group-hover:text-[color:var(--accent-orange)] transition-colors">
                                            {{ $e->name_ar ?? $e->name_en ?? $e->name ?? $e->full_name ?? ('#'.$e->id) }}
                                        </span>
                                        <span class="text-[10px] text-gray-400">#{{ $e->id }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @error('groupEmployeeIds') <div class="text-xs text-red-600 mt-2">{{ $message }}</div> @enderror
                </x-ui.card>

                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-3">
                        <x-ui.secondary-button type="button" wire:click="closeCreateGroupLeave">{{ tr('Cancel') }}</x-ui.secondary-button>
                        @if($canManageLeaveRequests)
                        <x-ui.primary-button type="button" wire:click="saveGroupLeave" class="!px-8">{{ tr('Save') }}</x-ui.primary-button>
                        @endif
                    </div>
                </x-slot>
            </div>

        </x-slot>
    </x-ui.modal>

    {{-- Cut Leave Modal --}}
    <x-ui.modal wire:model="cutLeaveOpen" max-width="lg">
        <x-slot name="title">
             <div class="flex items-center gap-3">
                <div class="p-2 bg-[color:var(--error)]/10 text-[color:var(--error)] rounded-lg">
                    <i class="fas fa-cut text-lg"></i>
                </div>
                <div>
                    <div class="font-black text-gray-900">{{ tr('Cut Leave') }}</div>
                    <div class="text-[10px] text-gray-500 font-medium">{{ tr('Interrupt an existing approved leave') }}</div>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
           <div class="space-y-4">

            <div>
                <div class="text-xs text-gray-500 mb-1">{{ tr('Approved Leave') }}</div>
                <x-ui.select wire:model="cut_leave_request_id" class="w-full" :disabled="!$canManageLeaveRequests">
                    <option value="0">--</option>
                    @foreach($approvedLeavesForCut as $l)
                        <option value="{{ $l->id }}">
                            #{{ $l->id }} -
                            {{ $l->employee->name_ar ?? $l->employee->name_en ?? ('#'.$l->employee_id) }}
                            ({{ optional($l->start_date)->toDateString() }} - {{ optional($l->end_date)->toDateString() }})
                        </option>
                    @endforeach
                </x-ui.select>
                @error('cut_leave_request_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
            </div>

            <div>
                <div class="text-xs text-gray-500 mb-1">{{ tr('Cut End Date') }}</div>
                <x-ui.company-date-picker model="cut_new_end_date" :disabled="!$canManageLeaveRequests" />
                @error('cut_new_end_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
            </div>

            <div>
                <div class="text-xs text-gray-500 mb-1">{{ tr('Reason') }}</div>
                <x-ui.textarea rows="3" wire:model="cut_reason" class="w-full" :disabled="!$canManageLeaveRequests" />
                @error('cut_reason') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
            </div>

            <x-slot name="footer">
                <div class="flex items-center justify-end gap-3">
                    <x-ui.secondary-button type="button" wire:click="closeCutLeave">{{ tr('Cancel') }}</x-ui.secondary-button>
                    @if($canManageLeaveRequests)
                    <x-ui.primary-button type="button" wire:click="saveCutLeaveRequest" class="!px-8">{{ tr('Save') }}</x-ui.primary-button>
                    @endif
                </div>
            </x-slot>
        </div>

        </x-slot>
    </x-ui.modal>

    {{-- Balance Audit Modal --}}
    <x-ui.modal wire:model="balanceAuditOpen" max-width="2xl">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <i class="fas fa-file-invoice-dollar text-[color:var(--accent-orange)]"></i>
                <span>{{ tr('Leave Balance Audit') }}</span>
            </div>
        </x-slot>

        <x-slot name="content">
            @if($selectedBalance)
                @php
                    $auditSummary = $selectedBalanceSummary ?? ['entitled' => 0, 'taken' => 0, 'remaining' => 0];
                @endphp
                <div class="space-y-6">
                    {{-- Summary Card --}}
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                        <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-bold">{{ tr('Employee') }}</p>
                                <p class="text-sm font-bold text-gray-900">{{ $selectedBalance->employee?->name_ar ?? $selectedBalance->employee?->name_en ?? ('#' . $selectedBalance->employee_id) }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-bold">{{ tr('Policy') }}</p>
                                <p class="text-sm font-bold text-gray-900">{{ $selectedBalance->policy?->name ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-bold">{{ tr('Year') }}</p>
                                <p class="text-sm font-bold text-gray-900">{{ $selectedBalance->year?->year ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-bold">{{ tr('Entitled') }}</p>
                                <p class="text-sm font-black text-gray-900">{{ number_format((float) ($auditSummary['entitled'] ?? 0), 2) }} {{ tr('Days') }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-bold">{{ tr('Taken') }}</p>
                                <p class="text-sm font-black text-[color:var(--error)]">{{ number_format((float) ($auditSummary['taken'] ?? 0), 2) }} {{ tr('Days') }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500 uppercase font-bold">{{ tr('Remaining') }}</p>
                                <p class="text-sm font-black text-[color:var(--success)]">{{ number_format((float) ($auditSummary['remaining'] ?? 0), 2) }} {{ tr('Days') }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Audit Table --}}
                    <div class="space-y-2">
                        <h4 class="text-xs font-bold text-gray-700 flex items-center gap-2">
                            <i class="fas fa-list-ul"></i>
                            {{ tr('Consumption History (Approved Leaves)') }}
                        </h4>
                        
                        <div class="border border-gray-100 rounded-xl overflow-hidden">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-start p-3">{{ tr('Date Range') }}</th>
                                        <th class="text-center p-3">{{ tr('Days Consumed') }}</th>
                                        <th class="text-start p-3">{{ tr('Reason') }}</th>
                                        <th class="text-center p-3">{{ tr('Approved Date') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @forelse($balanceHistory as $log)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="p-3 font-mono">
                                                {{ $log->start_date ? $log->start_date->toDateString() : '-' }} <i class="fas fa-arrow-right text-[8px] mx-1 opacity-50"></i> {{ $log->end_date ? $log->end_date->toDateString() : '-' }}
                                            </td>
                                            <td class="p-3 text-center font-bold text-[color:var(--error)]">
                                                -{{ number_format($log->requested_days, 2) }}
                                            </td>
                                            <td class="p-3 text-gray-600 truncate max-w-[150px]" title="{{ $log->reason }}">
                                                {{ $log->reason ?: '-' }}
                                            </td>
                                            <td class="p-3 text-center text-gray-400">
                                                {{ $log->approved_at ? $log->approved_at->format('Y-m-d') : '-' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="p-8 text-center text-gray-400 italic">
                                                {{ tr('No consumption records found for this balance.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot class="bg-[color:var(--accent-orange)]/5">
                                    <tr>
                                        <td class="p-3 font-bold text-gray-900 text-end">{{ tr('Total Consumed') }}:</td>
                                        <td class="p-3 text-center font-black text-[color:var(--error)] underline">
                                            {{ number_format((float) ($auditSummary['taken'] ?? 0), 2) }}
                                        </td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <x-ui.secondary-button wire:click="closeBalanceAudit">
                            {{ tr('Close') }}
                        </x-ui.secondary-button>
                    </div>
                </div>
            @endif
        </x-slot>
    </x-ui.modal>


    {{-- Create Group Permission Modal --}}
    <x-ui.modal wire:model="createGroupPermissionOpen" max-width="4xl">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-[color:var(--warning)]/10 text-[color:var(--warning)] rounded-lg">
                    <i class="fas fa-users-cog text-lg"></i>
                </div>
                <div>
                    <div class="font-black text-gray-900">{{ tr('New Group Permission') }}</div>
                    <div class="text-[10px] text-gray-500 font-medium">{{ tr('Assign common exit to multiple staff members') }}</div>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Date') }}</div>
                        <x-ui.company-date-picker model="group_permission_date" :disabled="!$canManageLeaveRequests" />
                        @error('group_permission_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('From') }}</div>
                        <x-ui.input type="time" wire:model="group_from_time" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('group_from_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('To') }}</div>
                        <x-ui.input type="time" wire:model="group_to_time" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('group_to_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Minutes') }}</div>
                        <x-ui.input type="number" min="0" wire:model="group_minutes" class="w-full" readonly />
                        @error('group_minutes') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Reason') }}</div>
                        <x-ui.input type="text" wire:model="group_permission_reason" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('group_permission_reason') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Employees --}}
                <x-ui.card class="p-3 border border-gray-200 rounded-2xl bg-gray-50">
                    <div class="flex items-center justify-between mb-2">
                        <div class="font-bold text-gray-900">{{ tr('Select Employees') }}</div>
                        <div class="text-xs text-gray-500">
                            {{ tr('Selected') }}: <span class="font-bold">{{ is_array($groupPermissionEmployeeIds ?? null) ? count($groupPermissionEmployeeIds) : 0 }}</span>
                        </div>
                    </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3 mb-3">
    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Search') }}</div>
        <x-ui.input type="text" wire:model.live="groupEmployeeSearch" class="w-full" :disabled="!$canManageLeaveRequests" />
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Branch') }}</div>
        <x-ui.select wire:model.live="groupBranchId" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Branches') }}</option>
            @foreach(($branches ?? []) as $br)
                <option value="{{ $br->id }}">
                    {{ ($br->name ?? ('#'.$br->id)) }}{{ !empty($br->code) ? ' - '.$br->code : '' }}
                </option>
            @endforeach
        </x-ui.select>
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Department') }}</div>
        <x-ui.select wire:model.live="groupDepartmentId" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Departments') }}</option>
            @foreach(($departments ?? []) as $d)
                <option value="{{ $d->id }}">{{ $d->label ?? $d->name ?? ('#'.$d->id) }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Job Title') }}</div>
        <x-ui.select wire:model.live="groupJobTitleId" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Job Titles') }}</option>
            @foreach(($jobTitles ?? []) as $jt)
                <option value="{{ $jt->id }}">{{ $jt->label ?? $jt->name ?? ('#'.$jt->id) }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div>
        <div class="text-xs text-gray-500 mb-1">{{ tr('Contract Type') }}</div>
        <x-ui.select wire:model.live="groupContractType" class="w-full" :disabled="!$canManageLeaveRequests">
            <option value="">{{ tr('All Contract Types') }}</option>
            <option value="permanent">{{ tr('Permanent') }}</option>
            <option value="temporary">{{ tr('Temporary') }}</option>
            <option value="probation">{{ tr('Probation') }}</option>
            <option value="contractor">{{ tr('Contractor') }}</option>
        </x-ui.select>
    </div>

</div>
                    <div class="max-h-64 overflow-auto bg-white rounded-xl border border-gray-200 p-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            @foreach($groupEmployeesForSelect as $e)
                                <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 border border-transparent hover:border-gray-100 transition-all cursor-pointer group">
                                    <input type="checkbox" value="{{ $e->id }}" wire:model="groupPermissionEmployeeIds" 
                                        class="w-4 h-4 rounded border-gray-300 text-[color:var(--accent-orange)] focus:ring-[color:var(--accent-orange)]"
                                        @if(!$canManageLeaveRequests) disabled @endif />
                                    <div class="flex flex-col">
                                        <span class="text-xs font-bold text-gray-800 group-hover:text-[color:var(--accent-orange)] transition-colors">
                                            {{ $e->name_ar ?? $e->name_en ?? $e->name ?? $e->full_name ?? ('#'.$e->id) }}
                                        </span>
                                        <span class="text-[10px] text-gray-400">#{{ $e->id }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @error('groupPermissionEmployeeIds') <div class="text-xs text-red-600 mt-2">{{ $message }}</div> @enderror
                </x-ui.card>

                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-3">
                        <x-ui.secondary-button type="button" wire:click="closeCreateGroupPermission">{{ tr('Cancel') }}</x-ui.secondary-button>
                        @if($canManageLeaveRequests)
                        <x-ui.primary-button type="button" wire:click="saveGroupPermission" class="!px-8">{{ tr('Save') }}</x-ui.primary-button>
                        @endif
                    </div>
                </x-slot>
            </div>
        </x-slot>
    </x-ui.modal>

    {{-- Workflow Modal --}}
    <x-ui.modal wire:model="workflowModalOpen" max-width="2xl">
        <x-slot name="title">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="absolute -inset-1 bg-[color:var(--accent-orange)]/10 rounded-xl blur opacity-80"></div>
                    <div class="relative p-2.5 bg-white rounded-xl shadow-sm text-[color:var(--accent-orange)] border border-gray-100">
                        <i class="fas fa-project-diagram text-xl"></i>
                    </div>
                </div>
                <div>
                    <h3 class="text-xl font-black text-gray-900 tracking-tight">{{ tr('Approval Tracking') }}</h3>
                    <p class="text-[12px] text-gray-500 font-medium">{{ tr('Visualize the real-time approval journey') }}</p>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
            @if($currentRequest)
                <div class="space-y-8">
                    {{-- Request Quick Info card --}}
                    <div class="relative overflow-hidden group">
                        <div class="absolute inset-0 bg-gradient-to-br from-gray-50 to-white -z-10"></div>
                        <div class="p-6 rounded-2xl border border-gray-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 shadow-sm">
                            <div class="flex items-center gap-4">
                                <div class="relative group-hover:scale-105 transition-transform duration-500">
                                    <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center border-2 border-white shadow-xl overflow-hidden ring-4 ring-gray-50">
                                         @if($currentRequest->employee?->avatar_url)
                                            <img src="{{ $currentRequest->employee->avatar_url }}" alt="" class="w-full h-full object-cover">
                                         @else
                                            <div class="w-full h-full bg-gradient-to-tr from-gray-100 to-gray-50 flex items-center justify-center text-gray-300">
                                                <i class="fas fa-user-tie text-2xl"></i>
                                            </div>
                                         @endif
                                    </div>
                                    <div class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-[color:var(--success)] border-2 border-white"></div>
                                </div>
                                <div>
                                    <div class="text-base font-black text-gray-900 leading-none mb-1.5 flex items-center gap-2">
                                        {{ $currentRequest->employee->name_ar ?? $currentRequest->employee->name_en ?? $currentRequest->employee->name ?? ('#' . $currentRequest->employee_id) }}
                                    </div>
                                    <div class="flex flex-wrap items-center gap-y-1 gap-x-3">
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-[color:var(--accent-orange)] bg-[color:var(--accent-orange)]/10 px-2 py-0.5 rounded-md">
                                            <i class="fas {{ $currentRequestType === 'leave' ? 'fa-calendar-day' : ($currentRequestType === 'permission' ? 'fa-hourglass-half' : 'fa-briefcase') }} opacity-70"></i>
                                            {{ $currentRequestType === 'leave' ? tr('Leave') : ($currentRequestType === 'permission' ? tr('Permission') : tr('Work Mission')) }}
                                        </span>
                                        <span class="text-[11px] font-bold text-gray-400">
                                            <i class="far fa-calendar-alt opacity-70 mr-0.5"></i>
                                            {{ company_date($currentRequest->start_date ?? $currentRequest->permission_date) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-2">
                                @php
                                    $statusClasses = [
                                        'approved' => 'bg-[color:var(--success)]/10 text-[color:var(--success)]',
                                        'rejected' => 'bg-[color:var(--error)]/10 text-[color:var(--error)]',
                                        'pending' => 'bg-[color:var(--warning)]/10 text-[color:var(--warning)]',
                                        'canceled' => 'bg-gray-100 text-gray-700',
                                    ];
                                    $class = $statusClasses[$currentRequest->status] ?? 'bg-gray-100 text-gray-700';
                                @endphp
                                <span class="px-4 py-1.5 rounded-xl text-[12px] font-black uppercase tracking-widest {{ $class }} ring-4 ring-white shadow-sm">
                                    {{ tr($currentRequest->status) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Dynamic Workflow Timeline --}}
                    <div>
                        <div class="flex items-center gap-2 mb-8 px-2">
                            <span class="h-px flex-1 bg-gray-100"></span>
                            <span class="text-[10px] uppercase font-black text-gray-400 tracking-[0.2em]">{{ tr('Workflow Path') }}</span>
                            <span class="h-px flex-1 bg-gray-100"></span>
                        </div>

                        @if(count($currentWorkflowTasks ?? []) > 0)
                            <div class="px-2">
                                <x-ui.approval-workflow :tasks="$currentWorkflowTasks" :currentStatus="$currentRequest->status" />
                            </div>
                        @else
                            <div class="py-16 text-center group">
                                <div class="relative inline-block mb-6">
                                    <div class="absolute inset-0 bg-[color:var(--accent-orange)]/10 blur-2xl rounded-full opacity-30 group-hover:opacity-60 transition-opacity"></div>
                                    <i class="fas fa-shield-alt text-6xl text-gray-200 relative"></i>
                                </div>
                                <h4 class="text-sm font-black text-gray-600 mb-2">{{ tr('Standard Security Route') }}</h4>
                                <p class="text-xs text-gray-400 font-medium max-w-xs mx-auto leading-relaxed">
                                    {{ tr('This request follows the default organizational structure managed directly by the department head.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-center w-full">
                <x-ui.secondary-button wire:click="closeWorkflow" class="!px-12 !py-2.5 !rounded-2xl !bg-gray-50 hover:!bg-gray-100 !border-gray-200 !text-gray-600 !font-black !text-xs !shadow-sm transition-all active:scale-95">
                    {{ tr('Close Tracking') }}
                </x-ui.secondary-button>
            </div>
        </x-slot>
    </x-ui.modal>

    {{-- Create Mission Modal --}}
    <x-ui.modal wire:model="createMissionOpen" max-width="lg">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] rounded-lg">
                    <i class="fas fa-briefcase text-lg"></i>
                </div>
                <div>
                    <div class="font-black text-gray-900">{{ tr('New Mission Request') }}</div>
                    <div class="text-[10px] text-gray-500 font-medium">{{ tr('Log external assignments and field work') }}</div>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <div class="text-xs text-gray-500 mb-1">{{ tr('Employee') }}</div>
                    <x-ui.select wire:model.live="mission_employee_id" class="w-full" :disabled="!$canManageLeaveRequests">
                        <option value="">-- {{ tr('Select Employee') }} --</option>
                        @foreach($employeesForSelect as $e)
                            <option value="{{ $e->id }}">
                                {{ trim(($e->employee_no ? $e->employee_no.' - ' : '') . ($e->name_ar ?? $e->name_en ?? $e->name ?? $e->full_name ?? '')) ?: ('#'.$e->id) }}
                            </option>
                        @endforeach
                    </x-ui.select>
                    @error('mission_employee_id') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Type') }}</div>
                        <x-ui.select wire:model.live="mission_type" class="w-full" :disabled="!$canManageLeaveRequests">
                            <option value="full_day">{{ tr('Full day') }}</option>
                            <option value="partial">{{ tr('Hours') }}</option>
                        </x-ui.select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('Start Date') }}</div>
                        <x-ui.company-date-picker model="mission_start_date" :disabled="!$canManageLeaveRequests" />
                        @error('mission_start_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                    @if($mission_type === 'full_day')
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('End Date') }}</div>
                        <x-ui.company-date-picker model="mission_end_date" :disabled="!$canManageLeaveRequests" />
                        @error('mission_end_date') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                    @endif
                </div>

                @if($mission_type === 'partial')
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('From Time') }}</div>
                        <x-ui.input type="time" wire:model.live="mission_from_time" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('mission_from_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">{{ tr('To Time') }}</div>
                        <x-ui.input type="time" wire:model.live="mission_to_time" class="w-full" :disabled="!$canManageLeaveRequests" />
                        @error('mission_to_time') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
                @endif

                <div>
                    <div class="text-xs text-gray-500 mb-1">{{ tr('Destination') }}</div>
                    <x-ui.input type="text" wire:model.live="mission_destination" class="w-full" :disabled="!$canManageLeaveRequests" />
                    @error('mission_destination') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                </div>

                <div>
                    <div class="text-xs text-gray-500 mb-1">{{ tr('Reason') }}</div>
                    <x-ui.textarea wire:model.live="mission_reason" class="w-full" rows="3" :disabled="!$canManageLeaveRequests"></x-ui.textarea>
                    @error('mission_reason') <div class="text-xs text-red-600 mt-1">{{ $message }}</div> @enderror
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3">
                <x-ui.secondary-button 
                    type="button" 
                    wire:click="closeCreateMission"
                    :full-width="false"
                    size="sm"
                >
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>

                @if($canManageLeaveRequests)
                <x-ui.primary-button 
                    type="button" 
                    wire:click="saveMission" 
                    :loading="'saveMission'"
                    :full-width="false"
                    size="sm"
                    class="!px-10"
                >
                    {{ tr('Save') }}
                </x-ui.primary-button>
                @endif
            </div>
        </x-slot>
    </x-ui.modal>

    {{-- Tab Loading Bar JavaScript --}}
    <script>
        function showTabLoadingBar() {
            // Show the loading bar
            window.dispatchEvent(new CustomEvent('tab-transition-start'));
            
            // Hide after reasonable time (adjust based on your performance)
            setTimeout(() => {
                window.dispatchEvent(new CustomEvent('tab-transition-stop'));
            }, 1000);
        }
    </script>

    <x-ui.confirm-dialog
        id="cancel-leave-dialog"
        title="{{ tr('Cancel Leave Request') }}"
        message="{{ tr('Are you sure you want to cancel this leave request? This action cannot be undone if the month is closed.') }}"
        confirmText="{{ tr('Yes, Cancel') }}"
        confirmAction="wire:cancelLeave(__ID__)"
        type="danger"
    />
</div>


