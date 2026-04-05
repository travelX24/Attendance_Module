{{-- Athka\Attendance\Resources\views\livewire\work-schedules\modals\bulk-assignment-modal.blade.php --}}
{{-- Bulk Assignment Modal --}}
<x-ui.modal
    wire:model="showBulkModal"
    max-width="lg"
>
    <x-slot name="title">
        @php
            $isRotationMode = ($bulkModalMode ?? 'single') === 'rotation';
        @endphp

        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-calendar-alt"></i>
            </div>

            <h3 class="font-bold text-gray-900 text-lg leading-tight">
                {{ $isRotationMode ? tr('Add Rotation Schedule') : tr('Add Schedule') }}
            </h3>
        </div>
    </x-slot>

    <x-slot name="content">
        @php
            $isRotationMode = ($bulkModalMode ?? 'single') === 'rotation';

            $formatTime = function ($value) {
                if ($value === null || $value === '') return null;
                $str = (string) $value;
                if (preg_match('/^\d{2}:\d{2}/', $str)) return substr($str, 0, 5);

                try {
                    return \Carbon\Carbon::parse($str)->format('H:i');
                } catch (\Throwable $e) {
                    return null;
                }
            };

            $scheduleIds = collect($workSchedules ?? [])->pluck('id')->map(fn($v) => (int) $v)->filter()->values()->all();

            $periodRowsAll = [];
            $periodsBySchedule = [];

            if (!empty($scheduleIds)) {
                $periodRowsAll = \Illuminate\Support\Facades\DB::table('work_schedule_periods')
                    ->whereIn('work_schedule_id', $scheduleIds)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'work_schedule_id', 'start_time', 'end_time', 'is_night_shift', 'sort_order']);

                foreach ($periodRowsAll as $r) {
                    $s = $formatTime($r->start_time);
                    $e = $formatTime($r->end_time);
                    if (!$s || !$e) continue;

                    $periodsBySchedule[(int) $r->work_schedule_id][] = [
                        'id' => (string) $r->id,
                        'label' => $s . ' - ' . $e,
                        'is_night' => (int) ($r->is_night_shift ?? 0),
                    ];
                }
            }

            $findSchedule = function ($id) use ($workSchedules) {
                $id = (int) $id;
                if ($id <= 0) return null;
                return $workSchedules->firstWhere('id', $id);
            };

            $getSchedulePeriods = function ($scheduleId) use ($periodsBySchedule) {
                $scheduleId = (int) $scheduleId;
                $out = [];
                foreach (($periodsBySchedule[$scheduleId] ?? []) as $p) {
                    $out[$p['id']] = $p['label'];
                }
                return $out;
            };

            $getScheduleSummary = function ($scheduleId) use ($periodsBySchedule) {
                $scheduleId = (int) $scheduleId;
                $labels = array_map(fn($p) => $p['label'], ($periodsBySchedule[$scheduleId] ?? []));
                return $labels ? implode(' | ', $labels) : null;
            };

            $scheduleA = $findSchedule($bulkFormData['work_schedule_id'] ?? null);
            $scheduleB = $findSchedule($bulkFormData['rotation_work_schedule_id'] ?? null);

            $periodsA = $scheduleA ? $getSchedulePeriods($scheduleA->id) : [];
            $periodsB = $scheduleB ? $getSchedulePeriods($scheduleB->id) : [];
        @endphp

        <div class="space-y-5">
            @if($contractMessage)
                <div class="flex items-start gap-3 p-3 bg-blue-50 rounded-xl border border-blue-100 shadow-sm">
                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-file-contract text-sm"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-xs text-blue-900 font-bold leading-relaxed">
                            {{ tr('Contract Insight') }}:
                        </p>
                        <p class="text-[11px] text-blue-700 mt-0.5">
                            {{ $contractMessage }}
                        </p>
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-between gap-3 p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                <div class="flex items-center gap-3">
                    <i class="fas fa-info-circle text-[color:var(--brand-via)]"></i>
                    <p class="text-xs text-indigo-900 font-medium">
                        {{ tr('Changes will apply to') }}:
                        <span class="font-bold underline">{{ count($selectedEmployees) }}</span>
                        {{ tr('selected employees') }}
                    </p>
                </div>
                
                {{-- Toggle Override --}}
                <div class="flex items-center gap-2 px-2 py-1 bg-white rounded-lg border border-indigo-200 shadow-sm">
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-tight">{{ tr('Manual Override') }}</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model.live="overrideContractDates" class="sr-only peer">
                        <div class="w-7 h-4 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>

            <div class="space-y-4">

                @if($isRotationMode)
                    <input type="hidden" wire:model="bulkFormData.is_rotation" value="1">
                @else
                    <input type="hidden" wire:model="bulkFormData.is_rotation" value="0">
                @endif

                <div class="grid @if($overrideContractDates) grid-cols-2 @else grid-cols-1 @endif gap-4">
                    <x-ui.company-date-picker
                        model="bulkFormData.start_date"
                        :label="tr('Start Date')"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />

                    @if($overrideContractDates)
                        @if($isRotationMode)
                            <x-ui.input
                                type="number"
                                min="1"
                                step="1"
                                wire:model="bulkFormData.rotation_days"
                                :label="tr('Rotation Days')"
                                error="bulkFormData.rotation_days"
                                required
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                        @else
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Duration') }}</label>
                                <x-ui.select wire:model.live="bulkFormData.is_permanent" class="w-full" align="up" :disabled="!auth()->user()->can('attendance.manage')">
                                    <option value="1">{{ tr('Permanent') }}</option>
                                    <option value="0">{{ tr('Temporary') }}</option>
                                </x-ui.select>
                            </div>
                        @endif
                    @endif
                </div>

                @if($isRotationMode && $overrideContractDates)
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Duration') }}</label>
                            <x-ui.select wire:model.live="bulkFormData.is_permanent" class="w-full" align="up" :disabled="!auth()->user()->can('attendance.manage')">
                                <option value="1">{{ tr('Permanent') }}</option>
                                <option value="0">{{ tr('Temporary') }}</option>
                            </x-ui.select>
                        </div>

                        <div class="hidden md:block"></div>
                    </div>
                @endif

                {{-- End Date --}}
                @if($overrideContractDates && (empty($bulkFormData['is_permanent']) || $bulkFormData['is_permanent'] === '0'))
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.company-date-picker model="bulkFormData.end_date" :label="tr('End Date')" :disabled="!auth()->user()->can('attendance.manage')" />
                        <div class="hidden md:block"></div>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                        {{ $isRotationMode ? tr('Schedule (A)') : tr('Selected Schedule') }}
                    </label>

                    {{-- Ù…Ù‡Ù…: live Ø¹Ø´Ø§Ù† Ø¨Ù…Ø¬Ø±Ø¯ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ø¬Ø¯ÙˆÙ„ ØªØ¸Ù‡Ø± Ø§Ù„ÙØªØ±Ø§Øª ÙÙˆØ±Ø§Ù‹ --}}
                    <x-ui.select wire:model.live="bulkFormData.work_schedule_id" class="w-full" align="down" :disabled="!auth()->user()->can('attendance.manage')">
                        <option value="">{{ tr('Select a schedule...') }}</option>
                        @foreach($workSchedules as $sch)
                            @php $optSummary = $getScheduleSummary($sch->id); @endphp
                            <option value="{{ $sch->id }}">
                                {{ $sch->name }}@if($optSummary) ({{ $optSummary }})@endif
                            </option>
                        @endforeach
                    </x-ui.select>

                    @error('bulkFormData.work_schedule_id')
                        <span class="text-red-500 text-xs mt-1">{{ $message }}</span>
                    @enderror

                </div>

                @if($isRotationMode)
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Second Schedule (B)') }}</label>

                        <x-ui.select wire:model.live="bulkFormData.rotation_work_schedule_id" class="w-full" align="down" :disabled="!auth()->user()->can('attendance.manage')">
                            <option value="">{{ tr('Select schedule B...') }}</option>
                            @foreach($workSchedules as $sch)
                                @php $optSummary = $getScheduleSummary($sch->id); @endphp
                                <option value="{{ $sch->id }}">
                                    {{ $sch->name }}@if($optSummary) ({{ $optSummary }})@endif
                                </option>
                            @endforeach
                        </x-ui.select>

                        @error('bulkFormData.rotation_work_schedule_id')
                            <span class="text-red-500 text-xs mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                @endif

            </div>
        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end gap-3 w-full">
            <x-ui.secondary-button wire:click="$set('showBulkModal', false)">
                {{ tr('Cancel') }}
            </x-ui.secondary-button>

            @can('attendance.manage')
            <x-ui.primary-button wire:click="applyBulkAssignment" wire:loading.attr="disabled" class="gap-2">
                <i class="fas fa-check" wire:loading.remove></i>
                <i class="fas fa-spinner fa-spin" wire:loading></i>
                <span>{{ tr('Confirm Application') }}</span>
            </x-ui.primary-button>
            @endcan
        </div>
    </x-slot>
</x-ui.modal>


