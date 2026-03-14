{{-- Edit Modal --}}
    <x-ui.modal wire:model="showEditModal" max-width="2xl">
        <x-slot name="title">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                    <i class="fas fa-edit"></i>
                </div>
                <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Edit Attendance') }}</h3>
            </div>
        </x-slot>

        <x-slot name="content">
            <div class="space-y-5">
                {{-- Employee Info Card --}}
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-100">
                    <div class="w-10 h-10 rounded-full bg-[color:var(--brand-via)]/10 flex items-center justify-center text-[color:var(--brand-via)] font-bold shrink-0">
                        {{ mb_substr($editingEmployeeName ?? '?', 0, 1) }}
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $editingEmployeeName ?? '-' }}</p>
                        <p class="text-xs text-gray-500">#{{ $editingEmployeeId ?? '-' }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $editingDate ?? '-' }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 p-3 bg-yellow-50 rounded-xl border border-yellow-100">
                    <i class="fas fa-exclamation-triangle text-yellow-600"></i>
                    <p class="text-xs text-yellow-900 font-medium">
                        {{ tr('Editing attendance records requires a valid reason and will be logged for audit purposes.') }}
                    </p>
                </div>

                {{-- Dynamic Periods --}}
                <div class="bg-gray-50/50 rounded-xl border border-gray-100 p-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-bold text-gray-700">{{ tr('Attendance Periods') }}</label>
                        @can('attendance.manage')
                        <button type="button" wire:click="addPeriodRow" class="text-xs text-[color:var(--brand-via)] hover:underline font-semibold flex items-center gap-1">
                            <i class="fas fa-plus-circle"></i> {{ tr('Add Period') }}
                        </button>
                        @endcan
                    </div>

                    @foreach($editForm['periods'] as $index => $period)
                        <div class="group relative grid grid-cols-2 gap-6 p-4 bg-white rounded-lg border border-gray-200 shadow-sm">
                            
                            {{-- Remove Button (absolute) --}}
                            @if(count($editForm['periods']) > 1)
                                @can('attendance.manage')
                                <button type="button" wire:click="removePeriodRow({{ $index }})" 
                                    class="absolute -top-2 -right-2 w-6 h-6 bg-red-50 text-red-500 rounded-full border border-red-100 flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors shadow-sm z-10"
                                    title="{{ tr('Remove Period') }}">
                                    <i class="fas fa-times text-xs"></i>
                                </button>
                                @endcan
                            @endif

                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-gray-700">{{ tr('Check In') }} <span class="text-gray-400 font-light text-[10px]">#{{ $index + 1 }}</span></label>
                                <div class="flex items-stretch border border-gray-200 rounded-lg overflow-hidden bg-white hover:border-[color:var(--brand-via)] focus-within:border-[color:var(--brand-via)] focus-within:ring-1 focus-within:ring-[color:var(--brand-via)] transition-all h-10">
                                    {{-- Scheduled (Readonly) --}}
                                    <div class="px-2.5 bg-gray-50/50 text-gray-400 text-[10px] font-mono border-r border-gray-100 flex items-center justify-center min-w-[70px]" title="{{ tr('Scheduled Time') }}">
                                        {{ $period['scheduled_in'] ?? '--:--' }}
                                    </div>
                                    
                                    {{-- Actual Input --}}
                                    <input 
                                        type="time" 
                                        wire:model="editForm.periods.{{ $index }}.check_in_time"
                                        class="flex-1 w-full border-0 focus:ring-0 text-sm px-3 text-gray-900 placeholder-gray-400 h-full bg-transparent"
                                        @cannot('attendance.manage') disabled @endcannot
                                    >
                                </div>
                                @error("editForm.periods.$index.check_in_time") 
                                    <span class="text-xs text-red-500">{{ $message }}</span> 
                                @enderror
                            </div>

                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-gray-700">{{ tr('Check Out') }} <span class="text-gray-400 font-light text-[10px]">#{{ $index + 1 }}</span></label>
                                <div class="flex items-stretch border border-gray-200 rounded-lg overflow-hidden bg-white hover:border-[color:var(--brand-via)] focus-within:border-[color:var(--brand-via)] focus-within:ring-1 focus-within:ring-[color:var(--brand-via)] transition-all h-10">
                                    {{-- Scheduled (Readonly) --}}
                                    <div class="px-2.5 bg-gray-50/50 text-gray-400 text-[10px] font-mono border-r border-gray-100 flex items-center justify-center min-w-[70px]" title="{{ tr('Scheduled Time') }}">
                                        {{ $period['scheduled_out'] ?? '--:--' }}
                                    </div>
                                    
                                    {{-- Actual Input --}}
                                    <input 
                                        type="time" 
                                        wire:model="editForm.periods.{{ $index }}.check_out_time"
                                        class="flex-1 w-full border-0 focus:ring-0 text-sm px-3 text-gray-900 placeholder-gray-400 h-full bg-transparent"
                                        @cannot('attendance.manage') disabled @endcannot
                                    >
                                </div>
                                @error("editForm.periods.$index.check_out_time") 
                                    <span class="text-xs text-red-500">{{ $message }}</span> 
                                @enderror
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.textarea
                    wire:model="editForm.reason"
                    :label="tr('Modification Reason')"
                    :hint="tr('Required: Why is this record being modified?')"
                    rows="3"
                    error="editForm.reason"
                    required
                    :disabled="!auth()->user()->can('attendance.manage')"
                />

                {{-- Attachment --}}
                <div>
                     <label class="block text-sm font-medium text-gray-700 mb-1">{{ tr('Attachment (Optional)') }}</label>
                     <input type="file" wire:model="editAttachment" class="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-full file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-50 file:text-blue-700
                        hover:file:bg-blue-100
                        disabled:opacity-50
                     " @cannot('attendance.manage') disabled @endcannot />
                     @error('editAttachment') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                     <div wire:loading wire:target="editAttachment" class="text-xs text-gray-500 mt-1">
                         <i class="fas fa-spinner fa-spin"></i> {{ tr('Uploading...') }}
                     </div>
                </div>

                {{-- Edit History Timeline --}}
                @if(count($editHistory) > 0)
                    <div class="mt-6 pt-6 border-t border-gray-100">
                        <div class="flex items-center gap-2 mb-4">
                            <i class="fas fa-history text-gray-400"></i>
                            <h4 class="text-sm font-bold text-gray-700">{{ tr('Modification History') }}</h4>
                        </div>
                        <div class="space-y-4">
                            @foreach($editHistory as $entry)
                                <div class="relative pl-6 pb-2 border-l-2 border-gray-100 last:border-0">
                                    {{-- Dot --}}
                                    <div class="absolute -left-[9px] top-0 w-4 h-4 rounded-full bg-white border-2 border-gray-200 flex items-center justify-center">
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
                                    </div>
                                    
                                    <div class="bg-gray-50/50 rounded-lg p-3 border border-gray-100 hover:border-gray-200 transition-colors">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xs font-bold text-gray-800">{{ $entry['actor_name'] }}</span>
                                            <span class="text-[10px] text-gray-400">{{ $entry['date'] }}</span>
                                        </div>
                                        <p class="text-xs text-gray-600 leading-relaxed italic">
                                            <i class="fas fa-quote-left text-[10px] text-gray-300 me-1"></i>
                                            {{ $entry['reason'] }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3 w-full">
                <x-ui.secondary-button wire:click="closeEditModal">
                    {{ tr('Cancel') }}
                </x-ui.secondary-button>
                @can('attendance.manage')
                <x-ui.primary-button wire:click="saveEdit" wire:loading.attr="disabled" class="gap-2">
                    <i class="fas fa-save" wire:loading.remove></i>
                    <i class="fas fa-spinner fa-spin" wire:loading></i>
                    <span>{{ tr('Save Changes') }}</span>
                </x-ui.primary-button>
                @endcan
            </div>
        </x-slot>
    </x-ui.modal>
