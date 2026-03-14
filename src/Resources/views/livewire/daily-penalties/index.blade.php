php
    $locale = app()->getLocale();
    $isRtl  = in_array(substr($locale, 0, 2), ['ar','fa','ur','he']);
    $dir    = $isRtl ? 'rtl' : 'ltr';
@endphp

@section('topbar-left-content')
    <x-ui.page-header
        :title="tr('Daily Penalty Calculation')"
        :subtitle="tr('Calculate and manage attendance-related financial penalties')"
    />
@endsection

<div class="space-y-6" dir="{{ $dir }}">

    {{-- Quick Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        {{-- Total Calculated --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-red-50 text-red-600 rounded-xl">
                <i class="fas fa-calculator fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Total Penalties') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ number_format($stats['total_calculated'], 2) }}</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Total Exempted --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-yellow-50 text-yellow-600 rounded-xl">
                <i class="fas fa-hand-holding-usd fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Waived Amount') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ number_format($stats['total_exempted'], 2) }}</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Total Net --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-green-50 text-green-600 rounded-xl">
                <i class="fas fa-file-invoice-dollar fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Net Deduction') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ number_format($stats['total_net'], 2) }}</p>
                </div>
            </div>
        </x-ui.card>

        {{-- Total Waivers --}}
        <x-ui.card hover class="flex items-center gap-4">
            <div class="p-3 bg-blue-50 text-blue-600 rounded-xl">
                <i class="fas fa-user-tag fa-lg"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ tr('Waivers Count') }}</p>
                <div class="flex items-baseline gap-1">
                    <p class="text-lg font-bold text-gray-900">{{ $stats['total_waivers'] }}</p>
                    <p class="text-xs text-gray-500">{{ tr('Times') }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters & Table --}}
    <x-ui.card padding="false" class="!overflow-visible">
        {{-- Filters Toolbar --}}
        <div class="p-3 border-b border-gray-100 bg-gray-50/30">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4 xxl:grid-cols-7 gap-3 items-end">
                    {{-- Search --}}
                    <div class="sm:col-span-2 lg:col-span-2">
                        <x-ui.search-box
                            model="search"
                            :placeholder="tr('Search employee...')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Date From --}}
                    <div>
                        <x-ui.company-date-picker
                            model="date_from"
                            :label="tr('From Date')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Date To --}}
                    <div>
                        <x-ui.company-date-picker
                            model="date_to"
                            :label="tr('To Date')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Violation --}}
                    <div>
                        <x-ui.filter-select
                            model="violation_type_filter"
                            :label="tr('Violation')"
                            :placeholder="tr('All')"
                            :options="[
                                ['value' => 'delay', 'label' => tr('Delay')],
                                ['value' => 'early_departure', 'label' => tr('Early Out')],
                                ['value' => 'absent', 'label' => tr('Absent')],
                                ['value' => 'auto_checkout', 'label' => tr('Missing Checkout')],
                            ]"
                            width="full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Status --}}
                    <div>
                        <x-ui.filter-select
                            model="status_filter"
                            :label="tr('Status')"
                            :placeholder="tr('All')"
                            :options="[
                                ['value' => 'pending', 'label' => tr('Pending')],
                                ['value' => 'confirmed', 'label' => tr('Confirmed')],
                                ['value' => 'waived', 'label' => tr('Waived')],
                            ]"
                            width="full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                 {{-- Branch --}}
                    <div>
                        <x-ui.filter-select
                            model="branch_id"
                            :label="tr('Branch')"
                            :placeholder="tr('All')"
                            :options="$branches->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->toArray()"
                            width="full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Department --}}
                    <div>
                        <x-ui.filter-select
                            model="department_id"
                            :label="tr('Department')"
                            :placeholder="tr('All')"
                            :options="$departments->map(fn($d) => ['value' => $d->id, 'label' => $d->name])->toArray()"
                            width="full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>

                    {{-- Job Title --}}
                    <div>
                        <x-ui.filter-select
                            model="job_title_id"
                            :label="tr('Job Title')"
                            :placeholder="tr('All')"
                            :options="$jobTitles->map(fn($j) => ['value' => $j->id, 'label' => $j->name])->toArray()"
                            width="full"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex flex-wrap items-center justify-between border-t border-gray-100 pt-3 mt-1">
                    <div class="flex items-center gap-2">
                        @if(!empty($selectedPenalties))
                            <div class="flex items-center gap-2 px-3 py-1.5 bg-[color:var(--brand-via)]/5 rounded-xl border border-[color:var(--brand-via)]/10">
                                <span class="text-xs font-bold text-[color:var(--brand-via)]">{{ count($selectedPenalties) }} {{ tr('Selected') }}</span>
                                <div class="w-px h-4 bg-[color:var(--brand-via)]/20 mx-1"></div>
                                @can('attendance.manage')
                                <button wire:click="bulkConfirm" class="text-xs font-bold text-green-600 hover:text-green-700 flex items-center gap-1 transition-colors">
                                    <i class="fas fa-check-double"></i>
                                    {{ tr('Confirm All') }}
                                </button>
                                <button wire:click="bulkDelete" class="text-xs font-bold text-red-600 hover:text-red-700 flex items-center gap-1 transition-colors">
                                    <i class="fas fa-trash-alt"></i>
                                    {{ tr('Delete') }}
                                </button>
                                @endcan
                            </div>
                        @else
                            <div class="text-[11px] text-gray-400 italic">
                                <i class="fas fa-info-circle me-1"></i>
                                {{ tr('Select records to perform bulk actions') }}
                            </div>
                        @endif
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <x-ui.secondary-button wire:click="refreshData" size="sm" class="!rounded-xl gap-2">
                            <i class="fas fa-sync text-xs"></i>
                            <span class="font-bold">{{ tr('Refresh') }}</span>
                        </x-ui.secondary-button>

                        @can('attendance.manage')
                        <x-ui.secondary-button wire:click="exportExcel" size="sm" class="!rounded-xl gap-2">
                            <i class="fas fa-file-excel text-xs"></i>
                            <span class="font-bold">{{ tr('Excel') }}</span>
                        </x-ui.secondary-button>
                        @endcan

                        @can('attendance.manage')
                        <x-ui.secondary-button wire:click="exportPdf" size="sm" class="!rounded-xl gap-2">
                            <i class="fas fa-file-pdf text-xs"></i>
                            <span class="font-bold">{{ tr('PDF') }}</span>
                        </x-ui.secondary-button>
                        @endcan

                        @can('attendance.manage')
                        <x-ui.primary-button wire:click="runCalculation" size="sm" class="!rounded-xl !px-6 gap-2 shadow-sm">
                            <i class="fas fa-play text-xs text-white"></i>
                            <span class="font-bold">{{ tr('Run Calculation') }}</span>
                        </x-ui.primary-button>
                        @endcan
                    </div>
                </div>
            </div>

        {{-- Table --}}
        @php
            $headers = [
                tr('<div class="flex items-center justify-center"><input type="checkbox" wire:model.live="selectAll" class="w-4 h-4 rounded-md border-gray-300 text-[color:var(--brand-via)] focus:ring-[color:var(--brand-via)] transition-all cursor-pointer" ' . (!auth()->user()->can('attendance.manage') ? 'disabled' : '') . '></div>'),
                tr('Employee'),
                tr('Dept/Job'),
                tr('Date'),
                tr('Actual In/Out'),
                tr('Violation'),
                tr('Duration'),
                tr('Calculated'),
                tr('Exemption'),
                tr('Net Amount'),
                tr('Status'),
                tr('Actions'),
            ];
            $headerAlign = ['center', 'start', 'start', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center', 'center'];
        @endphp

        <x-ui.table :headers="$headers" :headerAlign="$headerAlign" :enablePagination="false">
            @forelse($penalties as $penalty)
                @php
                    $violationBadge = match($penalty->violation_type) {
                        'delay' => ['type' => 'warning', 'label' => tr('Delay')],
                        'early_departure' => ['type' => 'orange', 'label' => tr('Early Out')],
                        'absent' => ['type' => 'danger', 'label' => tr('Absent')],
                        'auto_checkout' => ['type' => 'danger', 'label' => tr('Missing Checkout')],
                        default => ['type' => 'default', 'label' => $penalty->violation_type],
                    };

                    $statusBadge = match($penalty->status) {
                        'confirmed' => ['type' => 'success', 'label' => tr('Confirmed')],
                        'pending' => ['type' => 'warning', 'label' => tr('Pending')],
                        'waived' => ['type' => 'info', 'label' => tr('Waived')],
                        default => ['type' => 'default', 'label' => $penalty->status],
                    };
                @endphp
                <tr wire:key="penalty-{{ $penalty->id }}" 
                    class="group transition-all duration-200 hover:bg-[color:var(--brand-via)]/5 @if(in_array($penalty->id, $selectedPenalties)) bg-[color:var(--brand-via)]/10 @endif">
                    <td class="px-6 py-4 text-center">
                        <input type="checkbox" wire:model.live="selectedPenalties" value="{{ $penalty->id }}" 
                            class="w-4 h-4 rounded-md border-gray-300 text-[color:var(--brand-via)] focus:ring-[color:var(--brand-via)] transition-all cursor-pointer"
                            @cannot('attendance.manage') disabled @endcannot>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-[color:var(--brand-via)] font-bold">
                                {{ mb_substr($penalty->employee->name_ar ?? $penalty->employee->name_en, 0, 1) }}
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $penalty->employee->name_ar ?? $penalty->employee->name_en }}</p>
                                <p class="text-xs text-gray-500">#{{ $penalty->employee->employee_no }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-col">
                            <span class="text-xs font-medium text-gray-700">{{ $penalty->employee->department->name ?? '-' }}</span>
                            <span class="text-[10px] text-gray-500">{{ $penalty->employee->jobTitle->name ?? '-' }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="text-sm text-gray-700 font-medium whitespace-nowrap">{{ company_date($penalty->attendance_date) }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex flex-col items-center">
                            <span class="text-xs text-green-600 font-bold">{{ $penalty->attendanceLog->check_in_time ? \Carbon\Carbon::parse($penalty->attendanceLog->check_in_time)->format('H:i') : '-' }}</span>
                            <span class="text-xs text-red-600 font-bold">{{ $penalty->attendanceLog->check_out_time ? \Carbon\Carbon::parse($penalty->attendanceLog->check_out_time)->format('H:i') : '-' }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <x-ui.badge :type="$violationBadge['type']">{{ $violationBadge['label'] }}</x-ui.badge>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="text-sm font-semibold text-gray-700">{{ $penalty->violation_minutes }} {{ tr('min') }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="text-sm font-bold text-red-600">{{ number_format($penalty->calculated_amount, 2) }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        @if($penalty->exemption_amount > 0)
                            <div class="flex flex-col">
                                <span class="text-sm font-semibold text-yellow-600">-{{ number_format($penalty->exemption_amount, 2) }}</span>
                                <span class="text-[10px] text-gray-400">{{ $penalty->exemption_reason }}</span>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">-</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="text-sm font-bold text-gray-900">{{ number_format($penalty->net_amount, 2) }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                    <x-ui.badge :type="$statusBadge['type']">{{ $statusBadge['label'] }}</x-ui.badge>
                    @if($penalty->exemption_attachment)
                        <a href="{{ asset('storage/'.$penalty->exemption_attachment) }}" target="_blank" class="mt-1 block text-[10px] text-[color:var(--brand-via)] hover:underline">
                            <i class="fas fa-paperclip me-1"></i> {{ tr('Attachment') }}
                        </a>
                    @endif
                    </td>
                    <td class="px-6 py-4 text-center">
                        <x-ui.actions-menu>
                            @can('attendance.manage')
                            <x-ui.dropdown-item wire:click="openExemptionModal({{ $penalty->id }})" :disabled="$penalty->status === 'confirmed'">
                                <i class="fas fa-gift me-2 text-yellow-500"></i>
                                <span>{{ tr('Exempt/Waive') }}</span>
                            </x-ui.dropdown-item>
                            <x-ui.dropdown-item wire:click="openConfirmModal({{ $penalty->id }})" :disabled="$penalty->status !== 'pending'">
                                <i class="fas fa-check-circle me-2 text-green-500"></i>
                                <span>{{ tr('Confirm for Payroll') }}</span>
                            </x-ui.dropdown-item>
                            <x-ui.dropdown-item danger wire:click="deletePenalty({{ $penalty->id }})" :disabled="$penalty->status === 'confirmed'">
                                <i class="fas fa-trash me-2"></i>
                                <span>{{ tr('Delete') }}</span>
                            </x-ui.dropdown-item>
                            @else
                                <span class="text-gray-400 p-2 italic"><i class="fas fa-lock text-[10px] me-1"></i> {{ tr('Read Only') }}</span>
                            @endcan
                        </x-ui.actions-menu>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="px-6 py-12 text-center text-gray-500 italic">
                        {{ tr('No penalties found.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>

        @if($penalties->hasPages())
            <div class="p-4 border-t border-gray-100">
                {{ $penalties->links() }}
            </div>
        @endif
    </x-ui.card>

    {{-- Exemption Modal --}}
    <x-ui.modal wire:model="showExemptionModal" :title="tr('Apply Exemption/Waiver')">
        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Exemption Type') }}</label>
                    <select wire:model.live="exemptionForm.type" class="w-full border-gray-300 rounded-lg shadow-sm" :disabled="!auth()->user()->can('attendance.manage')">
                        <option value="full">{{ tr('Full Waiver (100%)') }}</option>
                        <option value="partial">{{ tr('Partial Exemption') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Exemption Reason') }}</label>
                    <select wire:model="exemptionForm.reason" class="w-full border-gray-300 rounded-lg shadow-sm" :disabled="!auth()->user()->can('attendance.manage')">
                        <option value="">{{ tr('Select reason...') }}</option>
                        <option value="business_mission">{{ tr('Business Mission') }}</option>
                        <option value="emergency_case">{{ tr('Emergency Case') }}</option>
                        <option value="technical_issue">{{ tr('Technical / Device Issue') }}</option>
                        <option value="late_permission">{{ tr('Late Permission/Leave') }}</option>
                        <option value="medical_emergency">{{ tr('Medical Emergency') }}</option>
                        <option value="other">{{ tr('Other') }}</option>
                    </select>
                </div>

                @if($exemptionForm['type'] === 'partial')
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Exempt Amount') }}</label>
                        <x-ui.input type="number" wire:model="exemptionForm.amount" :disabled="!auth()->user()->can('attendance.manage')" />
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Reason') }}</label>
                    <textarea wire:model="exemptionForm.details" rows="3" class="w-full border-gray-300 rounded-lg shadow-sm" placeholder="{{ tr('Why is this penalty being waived?') }}" :disabled="!auth()->user()->can('attendance.manage')"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Supporting Documents') }}</label>
                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl">
                        <div class="space-y-1 text-center">
                            <i class="fas fa-cloud-upload-alt fa-2x text-gray-400 mb-2"></i>
                            <div class="flex text-sm text-gray-600">
                                <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-[color:var(--brand-via)] hover:text-[color:var(--brand-via)]/80">
                                    <span>{{ tr('Upload a file') }}</span>
                                    <input id="file-upload" type="file" wire:model="exemptionForm.attachment" class="sr-only" @cannot('attendance.manage') disabled @endcannot>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500">PNG, JPG, PDF up to 10MB</p>
                        </div>
                    </div>
                    @if ($exemptionForm['attachment'])
                        <div class="mt-2 text-xs text-green-600 font-bold flex items-center gap-1">
                            <i class="fas fa-check-circle"></i>
                            {{ $exemptionForm['attachment']->getClientOriginalName() }}
                        </div>
                    @endif
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-ui.secondary-button @click="showExemptionModal = false">{{ tr('Cancel') }}</x-ui.secondary-button>
            @can('attendance.manage')
            <x-ui.primary-button wire:click="saveExemption">{{ tr('Apply Waiver') }}</x-ui.primary-button>
            @endcan
        </x-slot>
    </x-ui.modal>

    {{-- Confirm Modal --}}
    <x-ui.modal wire:model="showConfirmModal" :title="tr('Confirm Penalty')">
        <x-slot name="content">
            <div class="flex flex-col items-center text-center p-4">
                <div class="w-16 h-16 bg-yellow-50 text-yellow-500 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">{{ tr('Confirm for Payroll?') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ tr('This penalty will be sent to the payroll system as a deduction. Once confirmed, it can only be modified by administrators.') }}
                </p>
                
                @if($confirmPenaltyId)
                    @php $p = \Athka\Attendance\Models\AttendanceDailyPenalty::find($confirmPenaltyId); @endphp
                    @if($p)
                        <div class="mt-4 p-3 bg-gray-50 rounded-xl w-full border border-gray-100">
                            <p class="text-lg font-bold text-red-600">{{ number_format($p->net_amount, 2) }}</p>
                            <p class="text-xs text-gray-500">{{ $p->employee->name_ar ?? $p->employee->name_en }} ({{ company_date($p->attendance_date) }})</p>
                        </div>
                    @endif
                @endif
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-ui.secondary-button @click="showConfirmModal = false">{{ tr('Cancel') }}</x-ui.secondary-button>
            @can('attendance.manage')
            <x-ui.primary-button wire:click="confirmPenalty">{{ tr('Confirm & Send to Payroll') }}</x-ui.primary-button>
            @endcan
        </x-slot>
    </x-ui.modal>
</div>


