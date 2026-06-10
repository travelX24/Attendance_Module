{{-- Approval Modal --}}
    <x-ui.modal wire:model="showApprovalModal" max-width="2xl">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[color:var(--success)]/10 flex items-center justify-center text-[color:var(--success)] border border-[color:var(--success)]/25 shadow-sm">
                    <i class="fas fa-check-double"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900 leading-tight">{{ tr('Attendance Review & Approval') }}</h3>
                    <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wider">{{ tr('Confirm and process attendance record') }}</p>
                </div>
            </div>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                {{-- Quick Tip --}}
                <div class="flex items-center gap-3 p-3 bg-[color:var(--app-soft-bg)] rounded-xl border border-[color:var(--border-soft)]">
                    <i class="fas fa-info-circle text-[color:var(--accent-orange)]"></i>
                    <p class="text-[11px] text-[color:var(--text-secondary)] leading-relaxed">
                        {{ tr('Approved records are locked and used for calculating monthly salaries. Ensure all times and status are correct before proceeding.') }}
                    </p>
                </div>

                {{-- Approval Preview Content --}}
                @if(!empty($approvalPreview))
                    <div class="grid grid-cols-12 gap-6">
                        
                        {{-- Left Column: Employee & Summary --}}
                        <div class="col-span-12 lg:col-span-5 space-y-4">
                            {{-- Employee Card --}}
                            <div class="p-4 bg-gray-50 rounded-2xl border border-gray-100">
                                <div class="flex items-center gap-4">
                                     <div class="w-14 h-14 rounded-full bg-white border-2 border-[color:var(--border-soft)] flex items-center justify-center text-[color:var(--brand-via)] font-black text-xl shadow-sm">
                                         {{ mb_substr($approvalPreview['employee_name'] ?? '?', 0, 1) }}
                                     </div>
                                     <div class="flex-1">
                                         <h4 class="font-bold text-gray-900 leading-tight">{{ $approvalPreview['employee_name'] ?? '-' }}</h4>
                                         <p class="text-xs text-gray-500 font-medium">#{{ $approvalPreview['employee_no'] ?? '-' }}</p>
                                         <div class="flex items-center gap-2 mt-2">
                                             <x-ui.badge :type="$this->stats_colors[$approvalPreview['status'] ?? 'absent'] ?? 'gray'" size="xs">
                                                 {{ tr(ucfirst($approvalPreview['status'] ?? 'unknown')) }}
                                             </x-ui.badge>
                                             <span class="text-[10px] text-gray-400 font-mono">{{ $approvalPreview['attendance_date'] ?? '-' }}</span>
                                         </div>
                                     </div>
                                </div>
                            </div>

                            {{-- Compliance Gauge --}}
                            <div class="p-4 bg-white rounded-2xl border border-gray-100 shadow-sm relative overflow-hidden group">
                                <div class="absolute top-0 right-0 p-2 opacity-5 group-hover:opacity-10 transition-opacity">
                                    <i class="fas fa-chart-line fa-3x"></i>
                                </div>
                                <div class="flex justify-between items-center relative z-10">
                                    <div>
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">{{ tr('Compliance Rate') }}</p>
                                        <p class="text-2xl font-black text-gray-900">{{ number_format($approvalPreview['compliance_percentage'] ?? 0, 1) }}%</p>
                                    </div>
                                    <div class="flex gap-1.5">
                                        @php $comp = (float)($approvalPreview['compliance_percentage'] ?? 0); @endphp
                                        @for($i=1; $i<=5; $i++)
                                            <div class="w-1.5 h-8 rounded-full {{ $comp >= ($i*20) ? 'bg-[color:var(--success)]' : ($comp >= ($i*20)-10 ? 'bg-[color:var(--warning)]' : 'bg-gray-100') }}"></div>
                                        @endfor
                                    </div>
                                </div>
                            </div>

                            {{-- Summary Metrics --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-3 bg-white border border-gray-100 rounded-xl text-center">
                                    <p class="text-[10px] text-gray-400 font-bold uppercase">{{ tr('Actual') }}</p>
                                    <p class="text-sm font-bold text-gray-900">{{ number_format($approvalPreview['actual_hours'] ?? 0, 2) }} <span class="text-[10px] font-normal text-gray-400">{{ tr('Hrs') }}</span></p>
                                </div>
                                <div class="p-3 bg-white border border-gray-100 rounded-xl text-center">
                                    <p class="text-[10px] text-gray-400 font-bold uppercase">{{ tr('Scheduled') }}</p>
                                    <p class="text-sm font-bold text-gray-700">{{ number_format($approvalPreview['scheduled_hours'] ?? 0, 2) }} <span class="text-[10px] font-normal text-gray-400">{{ tr('Hrs') }}</span></p>
                                </div>
                            </div>
                        </div>

                        {{-- Right Column: Details & Audit --}}
                        <div class="col-span-12 lg:col-span-7 space-y-6">
                            
                            {{-- Shift Comparison --}}
                            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden shadow-sm">
                                <div class="px-4 py-2.5 bg-gray-50/50 border-b border-gray-100 flex justify-between items-center">
                                    <span class="text-[11px] font-bold text-gray-600">{{ tr('Shift Comparison') }}</span>
                                    <span class="text-[10px] font-mono text-gray-400">{{ $approvalPreview['schedule_name'] ?? '-' }}</span>
                                </div>
                                <div class="p-4 grid grid-cols-2 gap-4 sm:gap-8 relative">
                                    {{-- Connector Arrow --}}
                                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-20">
                                        <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
                                    </div>

                                    <div class="text-center">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase mb-3">{{ tr('Schedule (Ideal)') }}</p>
                                        <div class="flex flex-col gap-1 font-mono text-xs text-gray-600 bg-gray-50 rounded-lg py-2">
                                            <span class="font-bold text-gray-400">{{ $approvalPreview['scheduled_in'] ?? '--:--' }}</span>
                                            <span class="font-black text-gray-800">{{ $approvalPreview['scheduled_out'] ?? '--:--' }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-[10px] font-bold text-[color:var(--accent-orange)] uppercase mb-3">{{ tr('Actual (Punch)') }}</p>
                                        <div class="flex flex-col gap-1 font-mono text-xs text-[color:var(--accent-orange)] bg-[color:var(--accent-orange)]/10 rounded-lg py-2">
                                            <span class="font-bold">{{ $approvalPreview['check_in'] ?? '--:--' }}</span>
                                            <span class="font-black">{{ $approvalPreview['check_out'] ?? '--:--' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Issues & Audit Trail --}}
                            <div class="space-y-4">
                                {{-- Critical Issues --}}
                                @if(!empty($approvalIssues))
                                    @foreach($approvalIssues as $issue)
                                        <div class="flex items-start gap-3 p-3 bg-[color:var(--error)]/10 text-[color:var(--error)] rounded-xl border border-[color:var(--error)]/25 text-[11px]">
                                            <i class="fas fa-exclamation-triangle mt-0.5"></i>
                                            <p>{{ $issue }}</p>
                                        </div>
                                    @endforeach
                                @endif

                                {{-- Audit Trail --}}
                                @if(!empty($approvalEditHistory))
                                    <div class="bg-gray-50/50 rounded-2xl border border-gray-100 p-4">
                                        <div class="flex items-center gap-2 mb-3">
                                            <i class="fas fa-fingerprint text-gray-400 text-xs"></i>
                                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">{{ tr('Modification History') }}</span>
                                        </div>
                                        <div class="space-y-3 max-h-40 overflow-y-auto pr-2 custom-scrollbar">
                                            @foreach($approvalEditHistory as $h)
                                                <div class="flex gap-3 relative pb-3 border-l-2 border-gray-200/50 pl-4 ml-1 last:border-0 last:pb-0">
                                                    <div class="absolute -left-[5px] top-0 w-2 h-2 rounded-full bg-gray-300"></div>
                                                    <div class="flex-1">
                                                        <div class="flex justify-between items-start mb-1">
                                                            <span class="text-[11px] font-bold text-gray-800">{{ $h['actor'] }}</span>
                                                            <span class="text-[9px] text-gray-400">{{ $h['at'] }}</span>
                                                        </div>
                                                        <div class="flex items-center gap-2 text-[10px] text-gray-500 bg-white shadow-sm border border-gray-100 rounded-lg px-2 py-1">
                                                            <span class="font-medium text-[color:var(--accent-orange)] uppercase">{{ $h['action'] }}</span>
                                                            @if($h['from_in'] || $h['to_in'])
                                                                <div class="flex items-center gap-1 font-mono">
                                                                    <span class="text-gray-300">{{ $h['from_in'] ?? '??' }}</span>
                                                                    <i class="fas fa-arrow-right text-[8px] text-gray-200"></i>
                                                                    <span class="font-bold text-gray-700">{{ $h['to_in'] ?? '??' }}</span>
                                                                </div>
                                                            @endif
                                                        </div>
                                                        @if(!empty($h['reason']))
                                                            <p class="text-[9px] text-gray-400 italic mt-1 px-1">"{{ $h['reason'] }}"</p>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Approval Actions --}}
                <div class="pt-2">
                    <x-ui.textarea
                        wire:model="approvalNotes"
                        :label="tr('Final Remarks')"
                        :placeholder="tr('Optional internal notes regarding this approval...')"
                        rows="2"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex flex-col-reverse sm:flex-row sm:items-center justify-between w-full gap-3 sm:gap-0">
                <div class="w-full sm:w-auto">
                    <x-ui.secondary-button wire:click="$set('showApprovalModal', false)" class="w-full sm:w-auto justify-center">
                        {{ tr('Close') }}
                    </x-ui.secondary-button>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                     @can('attendance.manage')
                     <x-ui.secondary-button wire:click="openRejectModal({{ $approvingLogId }})" class="w-full sm:w-auto justify-center !bg-[color:var(--error)]/10 !text-[color:var(--error)] !border-[color:var(--error)]/25 hover:!bg-[color:var(--error)]/15">
                        {{ tr('Reject') }}
                    </x-ui.secondary-button>
                    <x-ui.primary-button wire:click="approveSingle" wire:loading.attr="disabled" class="w-full sm:w-auto justify-center gap-2 sm:!px-8 shadow-lg shadow-[color:var(--accent-orange)]/20">
                        <i class="fas fa-check-circle" wire:loading.remove></i>
                        <i class="fas fa-spinner fa-spin" wire:loading></i>
                        <span>{{ tr('Approve Record') }}</span>
                    </x-ui.primary-button>
                    @endcan
                </div>
            </div>
        </x-slot>
    </x-ui.modal>
