{-- Athka\Attendance\Resources\views\livewire\work-schedules\modals\bulk-assignment-modal.blade.php --}}
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
            <div class="flex items-center gap-3 p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                <i class="fas fa-info-circle text-[color:var(--brand-via)]"></i>
                <p class="text-xs text-indigo-900 font-medium">
                    {{ tr('Changes will apply to') }}:
                    <span class="font-bold underline">{{ count($selectedEmployees) }}</span>
                    {{ tr('selected employees') }}
                </p>
            </div>

            <div class="space-y-4">

                @if($isRotationMode)
                    <input type="hidden" wire:model="bulkFormData.is_rotation" value="1">
                @else
                    <input type="hidden" wire:model="bulkFormData.is_rotation" value="0">
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <x-ui.company-date-picker
                        model="bulkFormData.start_date"
                        :label="tr('Effective Date')"
                        :disabled="!auth()->user()->can('attendance.manage')"
                    />

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
                </div>

                @if($isRotationMode)
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

                {{-- âœ… End Date ÙŠØ¸Ù‡Ø± Ø¥Ø°Ø§ Ù…Ø¤Ù‚Øª (Single Ø£Ùˆ Rotation) --}}
                @if((empty($bulkFormData['is_permanent']) || $bulkFormData['is_permanent'] === '0'))
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.company-date-picker model="bulkFormData.end_date" :label="tr('End Date')" :disabled="!auth()->user()->can('attendance.manage')" />
                        <div class="hidden md:block"></div>
                    </div>
                @endif


                @if(!$isRotationMode && (empty($bulkFormData['is_permanent']) || $bulkFormData['is_permanent'] === '0'))
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

                    <div class="mt-2 p-3 bg-gray-50 rounded-xl border border-gray-100">
                        <div class="flex items-center justify-between">
                            <div class="text-xs text-gray-500 font-semibold">{{ tr('Work Period') }}</div>
                            <div class="text-[11px] text-gray-500">
                                {{ tr('Select one or more periods') }}
                            </div>
                        </div>

                        <div class="mt-2 space-y-2">
                            @php
                                $periodModel = $isRotationMode ? 'bulkFormData.work_periods_a' : 'bulkFormData.work_periods';
                            @endphp

                            @forelse($periodsA as $pid => $label)
                                <label class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-white transition">
                                    <input
                                        type="checkbox"
                                        class="w-4 h-4 text-[color:var(--brand-via)] border-gray-300 rounded focus:ring-[color:var(--brand-via)]"
                                        value="{{ $pid }}"
                                        wire:model.live="{{ $periodModel }}"
                                        @cannot('attendance.manage') disabled @endcannot
                                    >
                                    <span class="text-sm font-bold text-gray-900 font-mono" dir="ltr">{{ $label }}</span>
                                </label>
                            @empty
                                <p class="text-xs text-gray-400 italic">
                                    {{ tr('No work periods found for this schedule') }}
                                </p>
                            @endforelse

                            @if(!$isRotationMode)
                                @error('bulkFormData.work_periods')
                                    <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            @else
                                @error('bulkFormData.work_periods_a')
                                    <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            @endif
                        </div>
                    </div>
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

                        <div class="mt-2 p-3 bg-gray-50 rounded-xl border border-gray-100">
                            <div class="flex items-center justify-between">
                                <div class="text-xs text-gray-500 font-semibold">{{ tr('Work Period') }}</div>
                                <div class="text-[11px] text-gray-500">
                                    {{ tr('Select one or more periods') }}
                                </div>
                            </div>

                            <div class="mt-2 space-y-2">
                                @forelse($periodsB as $pid => $label)
                                    <label class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-white transition">
                                        <input
                                            type="checkbox"
                                            class="w-4 h-4 text-[color:var(--brand-via)] border-gray-300 rounded focus:ring-[color:var(--brand-via)]"
                                            value="{{ $pid }}"
                                            wire:model.live="bulkFormData.work_periods_b"
                                            @cannot('attendance.manage') disabled @endcannot
                                        >
                                        <span class="text-sm font-bold text-gray-900 font-mono" dir="ltr">{{ $label }}</span>
                                    </label>
                                @empty
                                    <p class="text-xs text-gray-400 italic">
                                        {{ tr('No work periods found for this schedule') }}
                                    </p>
                                @endforelse

                                @error('bulkFormData.work_periods_b')
                                    <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
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


