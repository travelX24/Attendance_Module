<x-ui.modal wire:model="showMonthlyEditModal" maxWidth="5xl">
    <x-slot:title>
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div>
                <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Monthly Attendance Sheet') }}</h3>
                <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">{{ $editingEmployeeName }} | <span class="text-[color:var(--brand-via)]">{{ $editingMonth }}</span></p>
            </div>
        </div>
    </x-slot:title>

    <x-slot:content>
        <div class="space-y-6">
            {{-- Employee Stats Summary --}}
            <div class="grid grid-cols-4 gap-4">
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--brand-via)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--brand-via)]">{{ tr('Total Days') }}</span>
                    <span class="text-2xl font-black text-gray-800">{{ count($monthlyEditForm) }}</span>
                 </div>
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--brand-via)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--brand-via)]">{{ tr('Scheduled Hours') }}</span>
                    <span class="text-2xl font-black text-gray-800">{{ collect($monthlyEditForm)->sum('scheduled_hours') }}</span>
                 </div>
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--brand-via)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--brand-via)]">{{ tr('Actual Hours') }}</span>
                    <span class="text-2xl font-black text-[color:var(--brand-via)]">{{ collect($monthlyEditForm)->sum('actual_hours') }}</span>
                 </div>
                 <div class="bg-gray-50 border border-gray-100 p-4 rounded-xl flex flex-col items-center justify-center gap-1 hover:bg-white hover:shadow-sm transition-all hover:border-[color:var(--brand-via)]/30 group">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block group-hover:text-[color:var(--brand-via)]">{{ tr('Compliance') }}</span>
                    @php
                        $sched = collect($monthlyEditForm)->sum('scheduled_hours');
                        $act = collect($monthlyEditForm)->sum('actual_hours');
                        $comp = $sched > 0 ? round(($act / $sched) * 100, 1) : 100;
                        $compColor = $comp >= 90 ? 'text-green-500' : ($comp >= 70 ? 'text-yellow-500' : 'text-red-500');
                    @endphp
                    <span class="text-2xl font-black {{ $compColor }}">{{ $comp }}%</span>
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
                                <th class="px-2 py-3 w-20 text-center text-gray-400">{{ tr('Sched In') }}</th>
                                <th class="px-2 py-3 w-20 text-center text-gray-400">{{ tr('Sched Out') }}</th>
                                <th class="px-2 py-3 w-28 text-center bg-[color:var(--brand-via)]/5 border-s border-[color:var(--brand-via)]/10 text-[color:var(--brand-via)]">{{ tr('Check In') }}</th>
                                <th class="px-2 py-3 w-28 text-center bg-[color:var(--brand-via)]/5 border-e border-[color:var(--brand-via)]/10 text-[color:var(--brand-via)]">{{ tr('Check Out') }}</th>
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
                                            <span class="text-[10px] text-gray-400 group-hover:text-[color:var(--brand-via)] transition-colors">{{ company_date($day['date']) }}</span>
                                        </div>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td class="px-2 py-2 text-center">
                                        <select wire:model="monthlyEditForm.{{ $index }}.status" 
                                             class="text-[10px] font-bold rounded-lg border-gray-200 focus:ring-[color:var(--brand-via)] focus:border-[color:var(--brand-via)] block w-full py-1.5 px-2 cursor-pointer
                                             {{ $day['status'] == 'present' ? 'text-green-700 bg-green-50 border-green-100' : 
                                               ($day['status'] == 'absent' ? 'text-red-700 bg-red-50 border-red-100' : 
                                               ($day['status'] == 'late' ? 'text-yellow-700 bg-yellow-50 border-yellow-100' : 
                                               ($day['status'] == 'weekend' ? 'text-gray-400 bg-gray-50' : 'text-gray-700 bg-white'))) }}"
                                             @cannot('attendance.manage') disabled @endcannot>
                                            <option value="present">{{ tr('Present') }}</option>
                                            <option value="late">{{ tr('Late') }}</option>
                                            <option value="early_departure">{{ tr('Early Leave') }}</option>
                                            <option value="absent">{{ tr('Absent') }}</option>
                                            <option value="on_leave">{{ tr('On Leave') }}</option>
                                            <option value="day_off">{{ tr('Day Off') }}</option>
                                            <option value="holiday">{{ tr('Holiday') }}</option>
                                        </select>
                                    </td>

                                    <!-- Scheduled -->
                                    <td class="px-2 py-2 text-center font-mono text-[10px] text-gray-400">
                                        {{ $day['scheduled_in'] ? \Carbon\Carbon::parse($day['scheduled_in'])->format('H:i') : '-' }}
                                    </td>
                                    <td class="px-2 py-2 text-center font-mono text-[10px] text-gray-400">
                                        {{ $day['scheduled_out'] ? \Carbon\Carbon::parse($day['scheduled_out'])->format('H:i') : '-' }}
                                    </td>

                                     <!-- Check In/Out Inputs -->
                                     <td colspan="2" class="px-0 py-0 bg-[color:var(--brand-via)]/5">
                                         <div class="flex flex-col divide-y divide-[color:var(--brand-via)]/10">
                                             @foreach($day['periods'] as $pIndex => $period)
                                                 <div class="flex items-center gap-1 p-1 group/period relative">
                                                     {{-- Check In --}}
                                                     <div class="flex-1">
                                                         <input type="time" wire:model.defer="monthlyEditForm.{{ $index }}.periods.{{ $pIndex }}.check_in" 
                                                              class="bg-white border text-gray-900 text-[10px] rounded-lg focus:ring-[color:var(--brand-via)] focus:border-[color:var(--brand-via)] block w-full py-1 px-1 font-mono text-center shadow-sm border-gray-200"
                                                              placeholder="--:--"
                                                              @cannot('attendance.manage') disabled @endcannot
                                                          >
                                                     </div>
                                                     {{-- divider --}}
                                                     <span class="text-gray-300">-</span>
                                                     {{-- Check Out --}}
                                                     <div class="flex-1">
                                                         <input type="time" wire:model.defer="monthlyEditForm.{{ $index }}.periods.{{ $pIndex }}.check_out" 
                                                              class="bg-white border text-gray-900 text-[10px] rounded-lg focus:ring-[color:var(--brand-via)] focus:border-[color:var(--brand-via)] block w-full py-1 px-1 font-mono text-center shadow-sm border-gray-200"
                                                              placeholder="--:--"
                                                              @cannot('attendance.manage') disabled @endcannot
                                                          >
                                                     </div>
                                                     {{-- Remove Button --}}
                                                     @if(count($day['periods']) > 1)
                                                         @can('attendance.manage')
                                                         <button type="button" wire:click="removeMonthlyPeriod({{ $index }}, {{ $pIndex }})" 
                                                             class="text-red-400 hover:text-red-600 transition-colors p-0.5" title="{{ tr('Remove') }}">
                                                             <i class="fas fa-times-circle text-[10px]"></i>
                                                         </button>
                                                         @endcan
                                                     @endif
                                                 </div>
                                             @endforeach
                                             {{-- Add Period Button --}}
                                             <div class="p-1 flex justify-center">
                                                 @can('attendance.manage')
                                                 <button type="button" wire:click="addMonthlyPeriod({{ $index }})" 
                                                     class="text-[9px] font-bold text-[color:var(--brand-via)] hover:text-blue-700 flex items-center gap-1 transition-colors px-2 py-0.5 rounded-full hover:bg-white border border-transparent hover:border-[color:var(--brand-via)]/20">
                                                     <i class="fas fa-plus-circle"></i> {{ tr('Add') }}
                                                 </button>
                                                 @endcan
                                             </div>
                                         </div>
                                     </td>

                                     <!-- Actual Hours -->
                                     <td class="px-2 py-2 text-center border-s border-gray-100">
                                         @if($day['actual_hours'] > 0)
                                             <span class="font-bold text-[color:var(--brand-via)]">{{ $day['actual_hours'] }}</span>
                                         @else
                                             <span class="text-gray-300">-</span>
                                         @endif
                                     </td>

                                    <!-- Notes -->
                                    <td class="px-4 py-2">
                                        <input type="text" 
                                            wire:model.defer="monthlyEditForm.{{ $index }}.notes" 
                                            placeholder="{{ tr('Add note...') }}" 
                                            class="bg-transparent border border-transparent text-gray-700 text-[11px] rounded transition-all focus:bg-white focus:border-[color:var(--brand-via)] focus:ring-1 focus:ring-[color:var(--brand-via)] block w-full py-1 px-2 hover:bg-gray-50 placeholder-gray-300"
                                            @cannot('attendance.manage') disabled @endcannot
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reason Field -->
            <div class="bg-yellow-50/50 border border-yellow-100 rounded-xl p-4">
                <label class="block mb-2 text-sm font-bold text-gray-700 flex items-center gap-2">
                    <i class="fas fa-edit text-yellow-500"></i>
                    {{ tr('Modification Reason') }} 
                    <span class="text-red-500">*</span>
                </label>
                <textarea 
                    wire:model.defer="monthlyEditReason" 
                    rows="2" 
                    class="block p-3 w-full text-sm text-gray-900 bg-white rounded-lg border border-gray-200 focus:ring-[color:var(--brand-via)] focus:border-[color:var(--brand-via)] shadow-sm placeholder-gray-400 transition-shadow" 
                    placeholder="{{ tr('Please describe the reason for these changes...') }}"
                    @cannot('attendance.manage') disabled @endcannot
                ></textarea>
                @error('monthlyEditReason') <span class="text-red-500 text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
            </div>
        </div>
    </x-slot:content>

    <x-slot:footer>
        <div class="flex items-center justify-between w-full">
            <div class="text-xs text-gray-400 italic">
                <i class="fas fa-info-circle me-1"></i> {{ tr('Changes will be applied immediately upon saving.') }}
            </div>
            <div class="flex items-center gap-3">
                <x-ui.secondary-button wire:click="$set('showMonthlyEditModal', false)">{{ tr('Cancel') }}</x-ui.secondary-button>
                @can('attendance.manage')
                <x-ui.brand-button wire:click="saveMonthlyEdit" class="shadow-lg shadow-[color:var(--brand-via)]/20">
                    <i class="fas fa-save me-2"></i> {{ tr('Save All Changes') }}
                </x-ui.brand-button>
                @endcan
            </div>
        </div>
    </x-slot:footer>
</x-ui.modal>
