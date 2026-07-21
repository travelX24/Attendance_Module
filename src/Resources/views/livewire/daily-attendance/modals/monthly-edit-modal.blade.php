<x-ui.modal wire:model="showMonthlyEditModal" maxWidth="5xl">
    <x-slot:title>
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--accent-orange)]/20 shadow-sm">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div>
                <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Monthly Attendance Sheet') }}</h3>
                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">{{ $editingEmployeeName }} | <span class="text-[color:var(--accent-orange)]">{{ $editingMonth }}</span></p>
            </div>
        </div>
    </x-slot:title>

    <x-slot:content>
        <div class="space-y-6">
            {{-- Employee Stats Summary --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-4">
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--accent-orange)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--accent-orange)]">{{ tr('Total Days') }}</span>
                    <span class="text-2xl font-black text-gray-800">{{ count($monthlyEditForm) }}</span>
                 </div>
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--accent-orange)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--accent-orange)]">{{ tr('Scheduled Hours') }}</span>
                    <span class="text-2xl font-black text-gray-800">{{ collect($monthlyEditForm)->sum('scheduled_hours') }}</span>
                 </div>
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--accent-orange)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--accent-orange)]">{{ tr('Actual Hours') }}</span>
                    <span class="text-2xl font-black text-[color:var(--accent-orange)]">{{ collect($monthlyEditForm)->sum('actual_hours') }}</span>
                 </div>
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--accent-orange)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--accent-orange)]">{{ tr('Compliance') }}</span>
                    @php
                        $sched = collect($monthlyEditForm)->sum('scheduled_hours');
                        $act = collect($monthlyEditForm)->sum('actual_hours');
                        $comp = $sched > 0 ? round(($act / $sched) * 100, 1) : 100;
                        $compCssVar = $comp >= 90 ? '--success' : ($comp >= 70 ? '--warning' : '--error');
                    @endphp
                    <span class="text-2xl font-black" style="color: var({{ $compCssVar }});">{{ $comp }}%</span>
                 </div>
            </div>

            {{-- Monthly Grid --}}
            <div class="rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left text-gray-500">
                        <thead class="text-[10px] text-gray-500 uppercase bg-gray-50/80 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 min-w-[140px]">{{ tr('Date') }}</th>
                                <th class="px-2 py-3 w-32 text-center">{{ tr('Status') }}</th>
                                <th class="px-2 py-3 w-32 text-center text-gray-400 font-bold tracking-tight">{{ tr('Scheduled Periods') }}</th>
                                <th class="px-2 py-3 w-28 text-center bg-[color:var(--accent-orange)]/5 border-s border-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)]">{{ tr('Check In') }}</th>
                                <th class="px-2 py-3 w-28 text-center bg-[color:var(--accent-orange)]/5 border-e border-[color:var(--accent-orange)]/10 text-[color:var(--accent-orange)]">{{ tr('Check Out') }}</th>
                                <th class="px-2 py-3 w-20 text-center">{{ tr('Actual') }}</th>
                                <th class="px-4 py-3 min-w-[200px]">{{ tr('Notes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach($monthlyEditForm as $index => $day)
                                <tr class="group hover:bg-gray-50 transition-colors {{ $day['is_weekend'] ? 'bg-gray-50/40' : '' }}">
                                    <!-- Date -->
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-gray-800">{{ \Carbon\Carbon::parse($day['date'])->translatedFormat('l') }}</span>
                                            <span class="text-[10px] text-gray-400 group-hover:text-[color:var(--accent-orange)] transition-colors">{{ company_date($day['date']) }}</span>
                                        </div>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td class="px-2 py-2 text-center">
                                         <div class="flex items-center gap-1">
                                            <select wire:model="monthlyEditForm.{{ $index }}.status" 
                                                 class="text-[10px] font-bold rounded-lg border-gray-200 focus:ring-[color:var(--accent-orange)] focus:border-[color:var(--accent-orange)] block w-full py-1.5 px-2 cursor-pointer
                                                 {{ $day['status'] == 'present' ? 'text-[color:var(--success)] bg-[color:var(--success)]/10 border-[color:var(--success)]/25' : 
                                                   ($day['status'] == 'absent' ? 'text-[color:var(--error)] bg-[color:var(--error)]/10 border-[color:var(--error)]/25' : 
                                                   ($day['status'] == 'late' ? 'text-[color:var(--warning)] bg-[color:var(--warning)]/10 border-[color:var(--warning)]/25' : 
                                                   ($day['status'] == 'weekend' ? 'text-gray-400 bg-gray-50' : 'text-gray-700 bg-white'))) }}"
                                                 @if(!$canManageDaily) disabled @endif>
                                                <option value="present">{{ tr('Present') }}</option>
                                                <option value="late">{{ tr('Late') }}</option>
                                                <option value="early_departure">{{ tr('Early Leave') }}</option>
                                                <option value="absent">{{ tr('Absent') }}</option>
                                                <option value="on_leave">{{ tr('On Leave') }}</option>
                                                <option value="day_off">{{ tr('Day Off') }}</option>
                                                <option value="holiday">{{ tr('Holiday') }}</option>
                                            </select>
                                            @if($day['is_exception'] ?? false)
                                                @php
                                                    $tooltipPrefix = ($day['is_official_holiday'] ?? false) ? tr('Official Holiday') : tr('Exceptional Day');
                                                @endphp
                                                <span class="text-[color:var(--warning)] shrink-0" title="{{ $tooltipPrefix }}: {{ $day['exception_name'] }}">
                                                    <i class="fas fa-star text-[10px]"></i>
                                                </span>
                                            @endif
                                         </div>
                                     </td>

                                    <!-- Scheduled Periods -->
                                    <td class="px-2 py-2 text-center font-mono text-[10px] text-gray-400 border-x border-gray-50">
                                        <div class="flex flex-col gap-0.5">
                                            @if(!empty($day['scheduled_periods']))
                                                @foreach($day['scheduled_periods'] as $sp)
                                                    <div class="whitespace-nowrap px-1 bg-gray-50 rounded border border-gray-100/50">
                                                        {{ company_time($sp['start']) }} - {{ company_time($sp['end']) }}
                                                    </div>
                                                @endforeach
                                            @else
                                                <span class="text-gray-300">-</span>
                                            @endif
                                        </div>
                                    </td>

                                     <!-- Check In/Out Inputs -->
                                     <td colspan="2" class="px-0 py-0 bg-[color:var(--accent-orange)]/5">
                                         <div class="flex flex-col divide-y divide-[color:var(--accent-orange)]/10">
                                             @foreach($day['periods'] as $pIndex => $period)
                                                 <div class="flex items-center gap-1 p-1 group/period relative">
                                                     {{-- Check In --}}
                                                     <div class="flex-1">
                                                         <input type="time" wire:model.defer="monthlyEditForm.{{ $index }}.periods.{{ $pIndex }}.check_in" 
                                                              class="bg-white border text-gray-900 text-[10px] rounded-lg focus:ring-[color:var(--accent-orange)] focus:border-[color:var(--accent-orange)] block w-full py-1 px-1 font-mono text-center shadow-sm border-gray-200"
                                                              placeholder="--:--"
                                                              @if(!$canManageDaily) disabled @endif
                                                          >
                                                     </div>
                                                     {{-- divider --}}
                                                     <span class="text-gray-300">-</span>
                                                     {{-- Check Out --}}
                                                     <div class="flex-1">
                                                         <input type="time" wire:model.defer="monthlyEditForm.{{ $index }}.periods.{{ $pIndex }}.check_out" 
                                                              class="bg-white border text-gray-900 text-[10px] rounded-lg focus:ring-[color:var(--accent-orange)] focus:border-[color:var(--accent-orange)] block w-full py-1 px-1 font-mono text-center shadow-sm border-gray-200"
                                                              placeholder="--:--"
                                                              @if(!$canManageDaily) disabled @endif
                                                          >
                                                     </div>
                                                     {{-- Remove Button --}}
                                                     @if(count($day['periods']) > 1)
                                                         @if($canManageDaily)
                                                         <button type="button" wire:click="removeMonthlyPeriod({{ $index }}, {{ $pIndex }})" 
                                                             class="text-[color:var(--error)]/70 hover:text-[color:var(--error)] transition-colors p-0.5" title="{{ tr('Remove') }}">
                                                             <i class="fas fa-times-circle text-[10px]"></i>
                                                         </button>
                                                         @endif
                                                     @endif
                                                 </div>
                                             @endforeach
                                             {{-- Add Period Button --}}
                                             <div class="p-1 flex justify-center">
                                                 @if($canManageDaily)
                                                 <button type="button" wire:click="addMonthlyPeriod({{ $index }})" 
                                                     class="text-[9px] font-bold text-[color:var(--accent-orange)] hover:brightness-90 flex items-center gap-1 transition-colors px-2 py-0.5 rounded-full hover:bg-white border border-transparent hover:border-[color:var(--accent-orange)]/20">
                                                     <i class="fas fa-plus-circle"></i> {{ tr('Add') }}
                                                 </button>
                                                 @endif
                                             </div>
                                         </div>
                                     </td>

                                     <!-- Actual Hours -->
                                     <td class="px-2 py-2 text-center border-s border-gray-100">
                                         @if($day['actual_hours'] > 0)
                                             <span class="font-bold text-[color:var(--accent-orange)]">{{ $day['actual_hours'] }}</span>
                                         @else
                                             <span class="text-gray-300">-</span>
                                         @endif
                                     </td>

                                    <!-- Notes -->
                                    <td class="px-4 py-2">
                                        <input type="text" 
                                            wire:model.defer="monthlyEditForm.{{ $index }}.notes" 
                                            placeholder="{{ tr('Add note...') }}" 
                                            class="bg-transparent border border-transparent text-gray-700 text-[11px] rounded transition-all focus:bg-white focus:border-[color:var(--accent-orange)] focus:ring-1 focus:ring-[color:var(--accent-orange)] block w-full py-1 px-2 hover:bg-gray-50 placeholder-gray-300"
                                            @if(!$canManageDaily) disabled @endif
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reason Field -->
            <div class="bg-[color:var(--warning)]/10 border border-[color:var(--warning)]/25 rounded-xl p-4">
                <label class="block mb-2 text-sm font-bold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-edit text-[color:var(--warning)]"></i>
                    {{ tr('Modification Reason') }} 
                    <span class="text-[color:var(--error)]">*</span>
                </label>
                <textarea 
                    wire:model.defer="monthlyEditReason" 
                    rows="2" 
                    class="block p-3 w-full text-sm text-gray-900 bg-white rounded-lg border border-gray-200 focus:ring-[color:var(--accent-orange)] focus:border-[color:var(--accent-orange)] shadow-sm placeholder-gray-400 transition-shadow" 
                    placeholder="{{ tr('Please describe the reason for these changes...') }}"
                    @if(!$canManageDaily) disabled @endif
                ></textarea>
                @error('monthlyEditReason') <span class="text-[color:var(--error)] text-xs mt-1 block font-bold">{{ \Athka\AuthKit\Support\UiMsg::toText($message) ?? $message }}</span> @enderror
            </div>
        </div>
    </x-slot:content>

    <x-slot:footer>
        <div class="flex flex-col-reverse sm:flex-row sm:items-center justify-between w-full gap-4 sm:gap-0">
            <div class="text-xs text-gray-400 italic text-center sm:text-start w-full sm:w-auto">
                <i class="fas fa-info-circle me-1"></i> {{ tr('Changes will be applied immediately upon saving.') }}
            </div>
            <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                <x-ui.secondary-button wire:click="$set('showMonthlyEditModal', false)" class="w-full sm:w-auto justify-center">{{ tr('Cancel') }}</x-ui.secondary-button>
                @if($canManageDaily)
                <x-ui.brand-button wire:click="saveMonthlyEdit" class="w-full sm:w-auto justify-center shadow-lg shadow-[color:var(--accent-orange)]/20">
                    <i class="fas fa-save me-2"></i> {{ tr('Save All Changes') }}
                </x-ui.brand-button>
                @endif
            </div>
        </div>
    </x-slot:footer>
</x-ui.modal>
