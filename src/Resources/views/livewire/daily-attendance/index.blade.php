@php
    // DEBUG: Verifying template file
    $locale = app()->getLocale();
    $isRtl  = in_array(substr($locale, 0, 2), ['ar','fa','ur','he']);
    $dir    = $isRtl ? 'rtl' : 'ltr';
@endphp

@section('topbar-left-content')
    <x-ui.page-header
        :title="tr('Daily Attendance Log')"
        :subtitle="tr('Monitor and manage daily employee attendance records')"
    />
@endsection

<div class="space-y-6" dir="{{ $dir }}">

    {{-- Quick Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {{-- Present --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-green-50 text-green-600 rounded-xl">
                <i class="fas fa-user-check fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Present') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['present_percentage'] }}</p>
                    <p class="text-xs text-gray-500">%</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Late --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-yellow-50 text-yellow-600 rounded-xl">
                <i class="fas fa-clock fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Late') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['late_percentage'] }}</p>
                    <p class="text-xs text-gray-500">%</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Absent --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-red-50 text-red-600 rounded-xl">
                <i class="fas fa-user-times fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Absent') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['absent_percentage'] }}</p>
                    <p class="text-xs text-gray-500">%</p>
                </div>
            </div>
        </x-ui.card>

        {{-- On Leave --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-blue-50 text-blue-600 rounded-xl">
                <i class="fas fa-umbrella-beach fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('On Leave') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['on_leave_percentage'] }}</p>
                    <p class="text-xs text-gray-500">%</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Early Departure --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-orange-50 text-orange-600 rounded-xl">
                <i class="fas fa-sign-out-alt fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Early Out') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['early_departure_percentage'] }}</p>
                    <p class="text-xs text-gray-500">%</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Auto Checkout --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-gray-50 text-gray-600 rounded-xl">
                <i class="fas fa-robot fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Auto Out') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['auto_checkout_percentage'] }}</p>
                    <p class="text-xs text-gray-500">%</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters & Table --}}
    <x-ui.card padding="false" class="!overflow-visible">
        {{-- Filters Toolbar --}}
        <div class="p-3 border-b border-gray-100 bg-gray-50/30">
            <div class="flex flex-col gap-3">
                {{-- First Row: Main Filters --}}
                <div class="flex flex-wrap items-end gap-2.5">
                    {{-- Search --}}
                    <div class="flex-1 min-w-[220px]">
                        <x-ui.search-box
                            model="search"
                            :placeholder="tr('Search by name or employee ID...')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Date Filtering --}}
                    @if($view_mode === 'daily')
                        {{-- Single Date for Daily Log --}}
                        <div class="w-48">
                            <x-ui.company-date-picker
                                model="date_from"
                                :label="tr('Select Date')"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        </div>
                    @else
                        {{-- Range for Summary --}}
                        <div class="w-40">
                            <x-ui.company-date-picker
                                model="date_from"
                                :label="tr('From Date')"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        </div>
                        <div class="w-40">
                            <x-ui.company-date-picker
                                model="date_to"
                                :label="tr('To Date')"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        </div>
                    @endif

                    {{-- Attendance Status --}}
                    <div class="w-40">
                        <x-ui.filter-select
                            model="attendance_status_filter"
                            :label="tr('Status')"
                            :placeholder="tr('All')"
                            :options="[
                                ['value' => 'present', 'label' => tr('Present')],
                                ['value' => 'late', 'label' => tr('Late')],
                                ['value' => 'absent', 'label' => tr('Absent')],
                                ['value' => 'on_leave', 'label' => tr('On Leave')],
                                ['value' => 'early_departure', 'label' => tr('Early Departure')],
                                ['value' => 'auto_checkout', 'label' => tr('Auto Checkout')],
                            ]"
                            width="full"
                            :defer="false"
                            :applyOnChange="true"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Approval Status --}}
                    <div class="w-32">
                        <x-ui.filter-select
                            model="approval_status_filter"
                            :label="tr('Approval')"
                            :placeholder="tr('All')"
                            :options="[
                                ['value' => 'pending', 'label' => tr('Pending')],
                                ['value' => 'approved', 'label' => tr('Approved')],
                                ['value' => 'rejected', 'label' => tr('Rejected')],
                            ]"
                            width="full"
                            :defer="false"
                            :applyOnChange="true"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>
                    {{-- Work Schedule --}}
                    <div class="w-44">
                        <x-ui.filter-select
                            model="work_schedule_id"
                            :label="tr('Schedule')"
                            :placeholder="tr('All')"
                            :options="$workSchedules->map(fn($s) => ['value' => $s->id, 'label' => $s->name])->toArray()"
                            width="full"
                            :defer="false"
                            :applyOnChange="true"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Job Title --}}
                    <div class="w-44">
                        <x-ui.filter-select
                            model="job_title_id"
                            :label="tr('Job Title')"
                            :placeholder="tr('All')"
                            :options="$jobTitles->map(fn($j) => ['value' => $j->id, 'label' => $j->name])->toArray()"
                            width="full"
                            :defer="false"
                            :applyOnChange="true"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Compliance From/To --}}
                    <div class="w-28">
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Compliance From') }}</label>
                        <input 
                            type="number"
                            min="0"
                            max="200"
                            step="1"
                            wire:model.live="compliance_from"
                            class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[color:var(--brand-via)] focus:border-transparent"
                            placeholder="0"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        >
                    </div>

                    <div class="w-28">
                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ tr('Compliance To') }}</label>
                        <input 
                            type="number"
                            min="0"
                            max="200"
                            step="1"
                            wire:model.live="compliance_to"
                            class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[color:var(--brand-via)] focus:border-transparent"
                            placeholder="100"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        >
                    </div>


                   {{-- Department --}}
                        <div class="w-40">
                            <x-ui.filter-select
                                model="department_id"
                                :label="tr('Department')"
                                :placeholder="tr('All Depts')"
                                :options="$departments->map(fn($d) => ['value' => $d->id, 'label' => $d->name])->toArray()"
                                width="full"
                                :defer="false"
                                :applyOnChange="true"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        </div>

                        {{-- Branch --}}
                        <div class="w-40">
                            <x-ui.filter-select
                                model="branch_id"
                                :label="tr('Branch')"
                                :placeholder="tr('All Branches')"
                                :options="$branches->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->toArray()"
                                width="full"
                                :defer="false"
                                :applyOnChange="true"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        </div>
                </div>

                {{-- Second Row: Action Buttons --}}
                <div class="flex gap-2 justify-end border-t border-gray-100 pt-2.5">

                    {{-- View Mode Toggle --}}
                    <div class="flex items-center bg-gray-100 rounded-lg p-1 me-auto">
                        <button 
                            wire:click="switchView('daily')" 
                            class="px-3 py-1 text-xs font-bold rounded-md transition-all {{ $view_mode === 'daily' ? 'bg-white shadow text-blue-600' : 'text-gray-500 hover:text-gray-700' }}"
                        >
                            <i class="fas fa-list-ul me-1"></i> {{ tr('Daily Log') }}
                        </button>
                        <button 
                            wire:click="switchView('summary')" 
                            class="px-3 py-1 text-xs font-bold rounded-md transition-all {{ $view_mode === 'summary' ? 'bg-white shadow text-blue-600' : 'text-gray-500 hover:text-gray-700' }}"
                        >
                            <i class="fas fa-users me-1"></i> {{ tr('Employee Summary') }}
                        </button>
                    </div>

                    {{-- Refresh --}}
                    <x-ui.secondary-button wire:click="refreshData" wire:loading.attr="disabled" size="sm" class="gap-1.5 group" title="{{ tr('Refresh') }}">
                        <i class="fas fa-sync text-xs text-gray-400 group-hover:text-[color:var(--brand-via)]" wire:loading.class="fa-spin"></i>
                        <span class="text-xs">{{ tr('Refresh') }}</span>
                    </x-ui.secondary-button>

                    @can('attendance.manage')
                    {{-- Export Excel --}}
                    <x-ui.secondary-button wire:click="exportExcel" size="sm" class="gap-1.5 group" title="{{ tr('Export to Excel') }}">
                        <i class="fas fa-file-excel text-xs text-gray-400 group-hover:text-green-600"></i>
                        <span class="text-xs">{{ tr('Excel') }}</span>
                    </x-ui.secondary-button>

                    {{-- Export PDF --}}
                    <x-ui.secondary-button wire:click="exportPDF" size="sm" class="gap-1.5 group" title="{{ tr('Export to PDF') }}">
                        <i class="fas fa-file-pdf text-xs text-gray-400 group-hover:text-red-600"></i>
                        <span class="text-xs">{{ tr('PDF') }}</span>
                    </x-ui.secondary-button>
                    @endcan
                </div>
            </div>
        </div>

        {{-- Table --}}
        @if($view_mode === 'daily')
            @php
                $headers = [
                    '',
                    tr('Employee'),
                    tr('Scheduled Hours'),
                    tr('Actual Hours'),
                    tr('Check In'),
                    tr('Check Out'),
                    tr('Status'),
                    tr('Approval'),
                    tr('Compliance'),
                    tr('Actions'),
                ];
                $headerAlign = ['center', 'start', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center'];
            @endphp
    
            <x-ui.table :headers="$headers" :headerAlign="$headerAlign" :enablePagination="false">
                {{-- Select All Row --}}
                <tr class="bg-gray-50/50 border-b border-gray-100">
                    <td class="px-6 py-3">
                        <input 
                            type="checkbox" 
                            wire:model.live="selectAll" 
                            class="w-4 h-4 text-[color:var(--brand-via)] border-gray-300 rounded focus:ring-[color:var(--brand-via)]"
                            @cannot('attendance.manage') disabled @endcannot
                        >
                    </td>
                    <td colspan="9" class="px-6 py-3">
                        @if(count($selectedLogs) > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-700">
                                    {{ tr('Selected') }}: {{ count($selectedLogs) }}
                                </span>
                                @can('attendance.manage')
                                <x-ui.primary-button wire:click="openBulkApprovalModal" class="gap-2" size="sm">
                                    <i class="fas fa-check-double"></i>
                                    <span>{{ tr('Approve Selected') }}</span>
                                </x-ui.primary-button>
                                @endcan
                            </div>
                        @else
                            <span class="text-xs text-gray-500">{{ tr('Select attendance records to approve') }}</span>
                        @endif
                    </td>
                </tr>
    
                @php $lastDate = null; @endphp
                @forelse($attendanceLogs as $log)
                    @php
                        $currentDate = $log->attendance_date ? $log->attendance_date->format('Y-m-d') : null;
                        
                        $employee = $log->employee;
                        $statusColor = $log->status_color;
                        $approvalColor = $log->approval_color;
                        
                        // Status badge
                        $statusBadge = match($log->attendance_status) {
                            'present' => ['type' => 'success', 'label' => tr('Present')],
                            'late' => ['type' => 'warning', 'label' => tr('Late')],
                            'absent' => ['type' => 'danger', 'label' => tr('Absent')],
                            'on_leave' => ['type' => 'info', 'label' => tr('On Leave')],
                            'early_departure' => ['type' => 'orange', 'label' => tr('Early Out')],
                            'auto_checkout' => ['type' => 'danger', 'label' => tr('Auto Out')],
                            'day_off' => ['type' => 'default', 'label' => tr('Day Off')],
                            default => ['type' => 'default', 'label' => $log->attendance_status],
                        };
    
                        $approvalBadge = match($log->approval_status) {
                            'approved' => ['type' => 'success', 'label' => tr('Approved')],
                            'pending' => ['type' => 'default', 'label' => tr('Pending')],
                            'rejected' => ['type' => 'danger', 'label' => tr('Rejected')],
                            default => ['type' => 'default', 'label' => $log->approval_status],
                        };
    
                        // Compliance color
                        $compliancePercentage = $log->compliance_percentage ?? 0;
                        $complianceColor = in_array($log->attendance_status, ['absent','on_leave','day_off'])
                            ? 'text-gray-500'
                            : ($compliancePercentage >= 95 ? 'text-green-700' : ($compliancePercentage >= 80 ? 'text-yellow-700' : 'text-red-700'));
                    @endphp
    
                    @if($currentDate && $currentDate !== $lastDate)
                         <tr class="bg-gray-50/80 border-y border-gray-100">
                             <td colspan="10" class="px-6 py-2">
                                 <div class="flex items-center gap-2 text-blue-800">
                                     <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600 shadow-sm">
                                         <i class="fas fa-calendar-day text-xs"></i>
                                     </div>
                                     <div class="flex flex-col">
                                         <span class="text-[10px] text-blue-400 font-bold uppercase tracking-widest leading-none mb-0.5">{{ $log->attendance_date->format('l') }}</span>
                                         <span class="text-xs font-black text-blue-900 leading-none">{{ company_date($log->attendance_date) }}</span>
                                     </div>
                                 </div>
                             </td>
                         </tr>
                         @php $lastDate = $currentDate; @endphp
                    @endif

                    <tr wire:key="log-{{ $log->id }}" class="hover:bg-gray-50/50 transition-colors">
                        {{-- Checkbox --}}
                        <td class="px-6 py-4 text-center">
                            <input 
                                type="checkbox" 
                                wire:model.live="selectedLogs" 
                                value="{{ $log->id }}" 
                                class="w-4 h-4 text-[color:var(--brand-via)] border-gray-300 rounded focus:ring-[color:var(--brand-via)]"
                                @cannot('attendance.manage') disabled @endcannot
                            >
                        </td>
    
                        {{-- Employee --}}
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-[color:var(--brand-via)]/10 flex items-center justify-center text-[color:var(--brand-via)] font-bold shrink-0">
                                    {{ $employee ? mb_substr($employee->name_ar ?? $employee->name_en, 0, 1) : '?' }}
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="text-sm font-semibold text-gray-900">
                                            {{ $employee ? ($employee->name_ar ?: $employee->name_en) : '-' }}
                                        </p>
    
                                        {{-- 🔴 No attendance for 3 consecutive days --}}
                                        @if(in_array($log->employee_id, $warningNoAttendanceEmployeeIds ?? [], true))
                                            <span class="text-red-600" title="{{ tr('No attendance for 3 consecutive days') }}">
                                                <i class="fas fa-exclamation-circle"></i>
                                            </span>
                                        @endif
    
                                        {{-- 🟠 Many edits on approved record --}}
                                        @if($log->approval_status === 'approved' && (int)($log->edits_count ?? 0) >= 2)
                                            <span class="text-orange-600" title="{{ tr('High edits count on approved record') }}">
                                                <i class="fas fa-history"></i>
                                            </span>
                                        @endif
    
                                        {{-- 🟡 Late > 60 min (today row check) --}}
                                        @php
                                            $lateToday = 0;
                                            if(!empty($log->scheduled_check_in) && !empty($log->check_in_time)) {
                                                try {
                                                    $dateStr = $log->attendance_date ? $log->attendance_date->format('Y-m-d') : (string)$log->attendance_date;
                                                    $sIn = \Carbon\Carbon::parse($dateStr.' '.substr((string)$log->scheduled_check_in,0,5));
                                                    $cIn = \Carbon\Carbon::parse($dateStr.' '.substr((string)$log->check_in_time,0,5));
                                                    $lateToday = $cIn->greaterThan($sIn) ? $cIn->diffInMinutes($sIn) : 0;
                                                } catch (\Throwable $e) { $lateToday = 0; }
                                            }
                                        @endphp
    
                                        @if($lateToday > 60)
                                            <span class="text-yellow-600" title="{{ tr('Late more than 60 minutes') }}">
                                                <i class="fas fa-clock"></i>
                                            </span>
                                        @endif
                                    </div>
    
                                  <p class="text-xs text-gray-500">
                                        #{{ $employee?->employee_no }}
                                    </p>

                                    <p class="text-[10px] text-gray-400">
                                        {{ tr('Branch') }}: {{ $employee?->branch?->name ?: '-' }}
                                    </p>
                                </div>
                            </div>
                        </td>
    
                        {{-- Scheduled Hours --}}
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-semibold text-gray-700">
                                {{ number_format($log->scheduled_hours ?? 0, 2) }}
                            </span>
                        </td>
    
                        {{-- Actual Hours --}}
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-bold {{ $complianceColor }}">
                                {{ number_format($log->actual_hours ?? 0, 2) }}
                            </span>
                        </td>
    
                        {{-- Check In Time --}}
                        <td class="px-6 py-4 text-center">
                            @if($log->details->isNotEmpty())
                                <div class="flex flex-col items-center gap-0.5">
                                    @foreach($log->details as $detail)
                                        <span class="text-sm font-bold {{ $statusColor === 'green' ? 'text-green-700' : ($statusColor === 'yellow' ? 'text-yellow-700' : ($statusColor === 'red' ? 'text-red-700' : 'text-gray-700')) }}">
                                            {{ $detail->check_in_time ? \Carbon\Carbon::parse($detail->check_in_time)->format('H:i') : '-' }}
                                        </span>
                                    @endforeach
                                </div>
                            @elseif($log->check_in_time)
                                <span class="text-sm font-bold {{ $statusColor === 'green' ? 'text-green-700' : ($statusColor === 'yellow' ? 'text-yellow-700' : ($statusColor === 'red' ? 'text-red-700' : 'text-gray-700')) }}">
                                    {{ $log->check_in_hm ?? '-' }}
                                </span>
                            @else
                                <span class="text-xs text-gray-400">-</span>
                            @endif
                        </td>
    
                        {{-- Check Out Time --}}
                        <td class="px-6 py-4 text-center">
                            @if($log->details->isNotEmpty())
                                <div class="flex flex-col items-center gap-0.5">
                                    @foreach($log->details as $detail)
                                        <span class="text-sm font-bold {{ $statusColor === 'green' ? 'text-green-700' : ($statusColor === 'yellow' ? 'text-yellow-700' : ($statusColor === 'red' ? 'text-red-700' : 'text-gray-700')) }}">
                                            {{ $detail->check_out_time ? \Carbon\Carbon::parse($detail->check_out_time)->format('H:i') : '-' }}
                                        </span>
                                    @endforeach
                                </div>
                            @elseif($log->check_out_time)
                                <span class="text-sm font-bold {{ $statusColor === 'green' ? 'text-green-700' : ($statusColor === 'yellow' ? 'text-yellow-700' : ($statusColor === 'red' ? 'text-red-700' : 'text-gray-700')) }}">
                                    {{ $log->check_out_hm ?? '-' }}
                                </span>
                            @else
                                @if($log->attendance_status === 'auto_checkout')
                                    <x-ui.badge type="danger" size="xs">{{ tr('Auto Out') }}</x-ui.badge>
                                @else
                                    <span class="text-xs text-gray-400">-</span>
                                @endif
                            @endif
                        </td>
    
                        {{-- Attendance Status --}}
                        <td class="px-6 py-4 text-center">
                            <x-ui.badge :type="$statusBadge['type']">
                                {{ $statusBadge['label'] }}
                            </x-ui.badge>
                        </td>
    
                        {{-- Approval Status --}}
                        <td class="px-6 py-4 text-center">
                            <x-ui.badge :type="$approvalBadge['type']">
                                {{ $approvalBadge['label'] }}
                            </x-ui.badge>
                            @if($log->is_edited)
                                <i class="fas fa-pencil-alt text-xs text-orange-500 ml-1" title="{{ tr('Edited') }}"></i>
                            @endif
                        </td>
    
                        {{-- Compliance Percentage --}}
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-bold {{ $complianceColor }}">
                                {{ number_format($compliancePercentage, 0) }}%
                            </span>
                        </td>
    
                        {{-- Actions --}}
                        <td class="px-6 py-4 text-center">
                            @can('attendance.manage')
                            <x-ui.actions-menu wire:key="actions-{{ $log->id }}">
                                <x-ui.dropdown-item wire:click="openEditModal({{ $log->id }})">
                                    <i class="fas fa-pen me-2"></i>
                                    <span>{{ tr('Edit') }}</span>
                                </x-ui.dropdown-item>
    
                                <x-ui.dropdown-item
                                    wire:click="openApprovalModal({{ $log->id }})"
                                    :disabled="in_array($log->approval_status, ['approved','rejected'])"
                                >
                                    <i class="fas fa-check me-2"></i>
                                    <span>{{ tr('Approve') }}</span>
                                </x-ui.dropdown-item>
    
                                <x-ui.dropdown-item
                                    danger
                                    wire:click="openRejectModal({{ $log->id }})"
                                    :disabled="in_array($log->approval_status, ['approved','rejected'])"
                                >
                                    <i class="fas fa-times me-2"></i>
                                    <span>{{ tr('Reject') }}</span>
                                </x-ui.dropdown-item>
    
                                @if(in_array($log->approval_status, ['approved','rejected']))
                                    <x-ui.dropdown-item wire:click="openUnapproveModal({{ $log->id }})">
                                        <i class="fas fa-undo me-2"></i>
                                        <span>{{ tr('Reopen') }}</span>
                                    </x-ui.dropdown-item>
                                @endif
    
                            </x-ui.actions-menu>
                            @else
                                <span class="text-gray-400"><i class="fas fa-lock text-[10px]"></i></span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center text-gray-300">
                                    <i class="fas fa-clipboard-list fa-2xl"></i>
                                </div>
                                <p class="text-sm text-gray-500 italic">{{ tr('No attendance records found for selected filters.') }}</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        @else
            {{-- SUMMARY TABLE - BRILLIANT REDESIGN --}}
            @php
                $summaryHeaders = [
                    tr('Employee'),
                    tr('Hire Date'),
                    tr('Attendance Analytics'),
                    tr('Hours Summary'),
                    tr('Compliance'),
                    tr('Actions'),
                ];
                $summaryAlign = ['start', 'center', 'center', 'center', 'center', 'center'];
            @endphp

            <x-ui.table :headers="$summaryHeaders" :headerAlign="$summaryAlign" :enablePagination="false">
                @forelse($attendanceLogs as $employee)
                     <tr wire:key="emp-summary-{{ $employee->id }}" class="hover:bg-gray-50/80 transition-all text-xs group">
                        {{-- Employee --}}
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="relative">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-400 flex items-center justify-center text-white font-bold shadow-md transform group-hover:scale-110 transition-transform">
                                        {{ mb_substr($employee->name_ar ?? $employee->name_en, 0, 1) }}
                                    </div>
                                    <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 border-2 border-white rounded-full"></div>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-gray-900 group-hover:text-blue-600 transition-colors">{{ $employee->name_ar ?? $employee->name_en }}</p>
                                    <p class="text-xs text-gray-500">
                                        <span class="inline-block px-1.5 py-0.5 bg-gray-100 rounded text-[10px] font-mono">#{{ $employee->employee_no }}</span>
                                        <span class="mx-1 text-gray-300">|</span>
                                        <span class="text-[10px]">{{ $employee->summary->schedule_name }}</span>
                                    </p>
                                </div>
                            </div>
                        </td>

                        {{-- Hire Date --}}
                        <td class="px-6 py-4 text-center">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-bold text-gray-700 bg-gray-100/50 px-2 py-1 rounded-full border border-gray-100">
                                    {{ company_date($employee->hired_at) }}
                                </span>
                                <span class="text-[9px] text-gray-400 mt-1 uppercase tracking-wider">{{ tr('Hired At') }}</span>
                            </div>
                        </td>

                        {{-- Attendance Analytics (The Brilliant Column) --}}
                        <td class="px-6 py-4">
                             <div class="flex flex-col gap-2 max-w-[280px] mx-auto">
                                 {{-- Distribution Bar --}}
                                 @php
                                     $total = $employee->summary->total_days ?: 1;
                                     $p = ($employee->summary->present_days / $total) * 100;
                                     $l = ($employee->summary->late_days / $total) * 100;
                                     $a = ($employee->summary->absent_days / $total) * 100;
                                     $e = ($employee->summary->early_departure_days / $total) * 100;
                                     $v = ($employee->summary->on_leave_days / $total) * 100;
                                     $au = ($employee->summary->auto_checkout_days / $total) * 100;
                                 @endphp
                                 <div class="h-1.5 w-full bg-gray-100 rounded-full flex overflow-hidden shadow-inner">
                                     <div class="h-full bg-green-500" style="width: {{ $p }}%" title="{{ tr('Present') }}"></div>
                                     <div class="h-full bg-yellow-400" style="width: {{ $l }}%" title="{{ tr('Late') }}"></div>
                                     <div class="h-full bg-red-400" style="width: {{ $a }}%" title="{{ tr('Absent') }}"></div>
                                     <div class="h-full bg-orange-400" style="width: {{ $e }}%" title="{{ tr('Early Departure') }}"></div>
                                     <div class="h-full bg-blue-400" style="width: {{ $v }}%" title="{{ tr('On Leave') }}"></div>
                                     <div class="h-full bg-gray-400" style="width: {{ $au }}%" title="{{ tr('Auto Out') }}"></div>
                                 </div>

                                 {{-- Interactive Icons Layout --}}
                                 <div class="grid grid-cols-6 gap-1">
                                     {{-- Present --}}
                                     <div class="flex flex-col items-center p-1 rounded bg-green-50/50 border border-green-100/50" title="{{ tr('Present') }}">
                                         <span class="text-[10px] font-bold text-green-700">{{ $employee->summary->present_days }}</span>
                                         <i class="fas fa-check-circle text-[8px] text-green-400"></i>
                                     </div>
                                     {{-- Late --}}
                                     <div class="flex flex-col items-center p-1 rounded bg-yellow-50/50 border border-yellow-100/50" title="{{ tr('Late') }}">
                                         <span class="text-[10px] font-bold text-yellow-700">{{ $employee->summary->late_days }}</span>
                                         <i class="fas fa-clock text-[8px] text-yellow-400"></i>
                                     </div>
                                     {{-- Absent --}}
                                     <div class="flex flex-col items-center p-1 rounded bg-red-50/50 border border-red-100/50" title="{{ tr('Absent') }}">
                                         <span class="text-[10px] font-bold text-red-700">{{ $employee->summary->absent_days }}</span>
                                         <i class="fas fa-times-circle text-[8px] text-red-400"></i>
                                     </div>
                                     {{-- Early Out --}}
                                     <div class="flex flex-col items-center p-1 rounded bg-orange-50/50 border border-orange-100/50" title="{{ tr('Early Departure') }}">
                                         <span class="text-[10px] font-bold text-orange-700">{{ $employee->summary->early_departure_days }}</span>
                                         <i class="fas fa-sign-out-alt text-[8px] text-orange-400"></i>
                                     </div>
                                     {{-- Leave --}}
                                     <div class="flex flex-col items-center p-1 rounded bg-blue-50/50 border border-blue-100/50" title="{{ tr('On Leave') }}">
                                         <span class="text-[10px] font-bold text-blue-700">{{ $employee->summary->on_leave_days }}</span>
                                         <i class="fas fa-umbrella-beach text-[8px] text-blue-400"></i>
                                     </div>
                                     {{-- Auto Out --}}
                                     <div class="flex flex-col items-center p-1 rounded bg-gray-50/50 border border-gray-100/50" title="{{ tr('Auto Out') }}">
                                         <span class="text-[10px] font-bold text-gray-700">{{ $employee->summary->auto_checkout_days }}</span>
                                         <i class="fas fa-robot text-[8px] text-gray-400"></i>
                                     </div>
                                 </div>
                             </div>
                        </td>

                        {{-- Hours --}}
                        <td class="px-6 py-4 text-center">
                            <div class="inline-flex flex-col items-center p-2 bg-gray-50 rounded-lg border border-gray-100">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                    <span class="text-[10px] text-gray-500 font-medium">S: <span class="text-gray-900 font-bold">{{ $employee->summary->total_scheduled_hours }}h</span></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                    <span class="text-[10px] text-gray-500 font-medium">A: <span class="text-green-600 font-bold">{{ $employee->summary->total_actual_hours }}h</span></span>
                                </div>
                            </div>
                        </td>

                        {{-- Compliance --}}
                        <td class="px-6 py-4 text-center">
                            @php $comp = $employee->summary->avg_compliance; @endphp
                            <div class="relative inline-flex items-center justify-center">
                                <svg class="w-12 h-12 transform -rotate-90">
                                    <circle cx="24" cy="24" r="20" stroke="currentColor" stroke-width="3" fill="transparent" class="text-gray-100" />
                                    <circle cx="24" cy="24" r="20" stroke="currentColor" stroke-width="3" fill="transparent" 
                                        class="{{ $comp >= 90 ? 'text-green-500' : ($comp >= 70 ? 'text-yellow-500' : 'text-red-500') }}"
                                        style="stroke-dasharray: 125.6; stroke-dashoffset: {{ 125.6 - (125.6 * $comp) / 100 }}" />
                                </svg>
                                <span class="absolute text-[10px] font-black {{ $comp >= 90 ? 'text-green-700' : ($comp >= 70 ? 'text-yellow-700' : 'text-red-700') }}">
                                    {{ round($comp, 0) }}%
                                </span>
                            </div>
                        </td>

                        {{-- Actions --}}
                        <td class="px-6 py-4 text-center">
                            @can('attendance.manage')
                            <x-ui.secondary-button wire:click="openMonthlyEditModal({{ $employee->id }})" size="xs">
                                <i class="fas fa-edit me-1"></i> {{ tr('Edit Sheet') }}
                            </x-ui.secondary-button>
                            @else
                                <span class="text-gray-400"><i class="fas fa-lock text-[10px]"></i></span>
                            @endcan
                        </td>
                     </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500 italic">{{ tr('No employees found.') }}</td>
                    </tr>
                @endforelse
            </x-ui.table>
        @endif

        {{-- Pagination --}}
        @if($attendanceLogs->hasPages())
            <div class="p-4 bg-gray-50/30 border-t border-gray-50">
                {{ $attendanceLogs->links() }}
            </div>
        @endif
    </x-ui.card>

    <div wire:key="attendance-modals-{{ $modalTrigger }}">
        @include('attendance::livewire.daily-attendance.modals.edit-modal')
        @include('attendance::livewire.daily-attendance.modals.approval-modal')
        @include('attendance::livewire.daily-attendance.modals.reject-modal')
        @include('attendance::livewire.daily-attendance.modals.unapprove-modal')
        @include('attendance::livewire.daily-attendance.modals.create-manual-modal')
        @include('attendance::livewire.daily-attendance.modals.edit-confirm-modal')
        @include('attendance::livewire.daily-attendance.modals.bulk-approval-modal')
        @include('attendance::livewire.daily-attendance.modals.monthly-edit-modal')
    </div>
</div>
