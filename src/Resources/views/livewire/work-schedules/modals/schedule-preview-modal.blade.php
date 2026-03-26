{{-- resources/views/livewire/work-schedules/modals/schedule-preview-modal.blade.php --}}

<x-ui.modal
    wire:model="showSchedulePreviewModal"
    maxWidth="5xl"
>
    <x-slot name="title">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[color:var(--brand-via)]/10 text-[color:var(--brand-via)] rounded-xl flex items-center justify-center text-lg border border-[color:var(--brand-via)]/20 shadow-sm">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div>
                <h3 class="font-bold text-gray-900 text-lg leading-tight">{{ tr('Schedule Preview') }}</h3>
                @if(!empty($previewEmployee))
                    <p class="text-xs text-gray-500">
                        {{ app()->isLocale('ar')
                            ? ($previewEmployee['name_ar'] ?? $previewEmployee['name_en'] ?? '-')
                            : ($previewEmployee['name_en'] ?? $previewEmployee['name_ar'] ?? '-') }}
                        <span class="font-mono text-gray-400">#{{ $previewEmployee['employee_no'] ?? '' }}</span>
                    </p>
                @endif
            </div>
        </div>
    </x-slot>

    <x-slot name="content">
        <div dir="{{ $dir ?? 'rtl' }}" class="space-y-4">

            {{-- Summary Badges Card --}}
            @if(!empty($previewMeta))
                <div class="flex flex-wrap gap-2 mb-2">
                    <x-ui.badge type="info" size="xs">
                        {{ tr('Days') }}: {{ (int) ($previewMeta['days'] ?? 0) }}
                    </x-ui.badge>

                    <x-ui.badge type="warning" size="xs">
                        {{ tr('Assignments') }}: {{ (int) ($previewMeta['assignments_count'] ?? 0) }}
                    </x-ui.badge>

                    <x-ui.badge type="orange" size="xs">
                        {{ tr('Rotations') }}: {{ (int) ($previewMeta['rotations_count'] ?? 0) }}
                    </x-ui.badge>

                    <div class="text-[11px] text-gray-400 ms-auto font-mono">
                        {{ $previewMeta['from'] ?? '' }} → {{ $previewMeta['to'] ?? '' }}
                    </div>
                </div>
            @endif

            {{-- Filters Card --}}
            <x-ui.card class="bg-gray-50/50 border-gray-100">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div class="sm:col-span-1">
                        <x-ui.company-date-picker
                            model="previewForm.from"
                            :label="tr('From')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                        @error('previewForm.from')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="sm:col-span-1">
                        <x-ui.company-date-picker
                            model="previewForm.to"
                            :label="tr('To')"
                            :disabled="!auth()->user()->can('attendance.manage')"
                        />
                        @error('previewForm.to')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    @can('attendance.manage')
                    <div class="sm:col-span-2 flex justify-end">
                        <x-ui.primary-button
                            type="button"
                            wire:click="generateSchedulePreview"
                            loading="generateSchedulePreview"
                            :fullWidth="false"
                            class="!px-10"
                        >
                            <span>{{ tr('Update Preview') }}</span>
                        </x-ui.primary-button>
                    </div>
                    @endcan
                </div>
            </x-ui.card>

            {{-- Table --}}
            @php
                $headers = [
                    tr('Date'),
                    tr('Day'),
                    tr('Schedule'),
                    tr('Periods'),
                ];
                $headerAlign = ['start','start','start','start'];
            @endphp

            <x-ui.card padding="false" class="border border-gray-100 !overflow-hidden">
                <div class="max-h-[50vh] overflow-auto">
                    <x-ui.table :headers="$headers" :headerAlign="$headerAlign" :enablePagination="false">
                        @forelse(($previewRows ?? []) as $row)
                            @php
                                $t   = $row['type'] ?? 'none';
                                $src = $row['source'] ?? null;
                                $periods = $row['periods'] ?? [];
                            @endphp

                            <tr class="hover:bg-gray-50/50 transition-colors border-b border-gray-50 last:border-0">
                                <td class="px-6 py-4 font-mono text-sm text-gray-700">
                                    {{ $this->formatCompanyDate($row['date'] ?? null, $this->getCompanyId()) }}
                                </td>

                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ $row['day'] ?? '-' }}
                                </td>

                                <td class="px-6 py-4">
                                    @if(!empty($row['schedule']))
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-gray-900">{{ $row['schedule'] }}</span>

                                            @if(!empty($row['disabled']))
                                                <x-ui.badge type="danger" size="xs">
                                                    {{ tr('Disabled') }}
                                                </x-ui.badge>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400 italic">-</span>
                                    @endif
                                </td>

                                <td class="px-6 py-4">
                                    @if(!empty($periods))
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($periods as $p)
                                                <x-ui.badge type="info" size="xs">
                                                    <span class="font-mono">{{ $p }}</span>
                                                </x-ui.badge>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400 italic">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500 italic">
                                    {{ tr('No data') }}
                                </td>
                            </tr>
                        @endforelse
                    </x-ui.table>
                </div>

                <div class="px-5 py-3 border-t bg-gray-50/50 text-[11px] text-gray-500 flex items-center justify-between">
                    <span><i class="fas fa-info-circle me-1 text-gray-400"></i> {{ tr('Note: Preview is limited to 31 days to avoid heavy queries.') }}</span>
                </div>
            </x-ui.card>

        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end w-full">
            <x-ui.secondary-button type="button" wire:click="closeSchedulePreviewModal" class="gap-2">
                <i class="fas fa-times"></i>
                <span>{{ tr('Close') }}</span>
            </x-ui.secondary-button>
        </div>
    </x-slot>
</x-ui.modal>
