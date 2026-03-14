{-- resources/views/livewire/work-schedules/modals/schedule-preview-modal.blade.php --}}

<x-ui.modal
    wire:model="showSchedulePreviewModal"
    maxWidth="5xl"
>
    <x-slot name="content">
        <div dir="{{ $dir ?? 'rtl' }}" class="space-y-4">

            {{-- Header --}}
            <x-ui.card padding="false" class="border border-gray-100">
                <div class="px-5 py-4 border-b flex items-center justify-between">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-bold text-gray-900">{{ tr('Schedule Preview') }}</p>

                            @if(!empty($previewMeta))
                                <x-ui.badge type="info" size="xs">
                                    {{ tr('Days') }}: {{ (int) ($previewMeta['days'] ?? 0) }}
                                </x-ui.badge>

                                <x-ui.badge type="warning" size="xs">
                                    {{ tr('Assignments') }}: {{ (int) ($previewMeta['assignments_count'] ?? 0) }}
                                </x-ui.badge>

                                <x-ui.badge type="orange" size="xs">
                                    {{ tr('Rotations') }}: {{ (int) ($previewMeta['rotations_count'] ?? 0) }}
                                </x-ui.badge>
                            @endif
                        </div>

                        @if(!empty($previewEmployee))
                            <p class="text-xs text-gray-500">
                                {{ app()->isLocale('ar')
                                    ? ($previewEmployee['name_ar'] ?? $previewEmployee['name_en'] ?? '-')
                                    : ($previewEmployee['name_en'] ?? $previewEmployee['name_ar'] ?? '-') }}
                                <span class="font-mono text-gray-400">#{{ $previewEmployee['employee_no'] ?? '' }}</span>
                            </p>
                        @endif
                    </div>

                    <x-ui.secondary-button type="button" wire:click="closeSchedulePreviewModal" class="gap-2">
                        <i class="fas fa-times"></i>
                        <span>{{ tr('Close') }}</span>
                    </x-ui.secondary-button>
                </div>

                {{-- Filters --}}
                <div class="p-5 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                        <div class="sm:col-span-1">
                            <x-ui.input
                                type="date"
                                model="previewForm.from"
                                :label="tr('From')"
                                :defer="true"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                            @error('previewForm.from')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="sm:col-span-1">
                            <x-ui.input
                                type="date"
                                model="previewForm.to"
                                :label="tr('To')"
                                :defer="true"
                                :disabled="!auth()->user()->can('attendance.manage')"
                            />
                            @error('previewForm.to')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        @can('attendance.manage')
                        <div class="sm:col-span-2 flex justify-end gap-2">
                            <x-ui.primary-button type="button" wire:click="generateSchedulePreview" class="gap-2">
                                <i class="fas fa-sync"></i>
                                <span>{{ tr('Refresh') }}</span>
                            </x-ui.primary-button>
                        </div>
                        @endcan
                    </div>

                    @if(!empty($previewMeta))
                        <div class="text-xs text-gray-500">
                            <span class="font-mono">{{ $previewMeta['from'] ?? '' }}</span>
                            <span class="mx-1">→</span>
                            <span class="font-mono">{{ $previewMeta['to'] ?? '' }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Table --}}
            @php
                $headers = [
                    tr('Date'),
                    tr('Day'),
                    tr('Type'),
                    tr('Schedule'),
                    tr('Periods'),
                ];
                $headerAlign = ['start','start','start','start','start'];
            @endphp

            <x-ui.card padding="false" class="border border-gray-100 !overflow-hidden">
                <div class="max-h-[60vh] overflow-auto">
                    <x-ui.table :headers="$headers" :headerAlign="$headerAlign" :enablePagination="false">
                        @forelse(($previewRows ?? []) as $row)
                            @php
                                $t   = $row['type'] ?? 'none';
                                $src = $row['source'] ?? null;
                                $periods = $row['periods'] ?? [];
                            @endphp

                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-4 font-mono text-sm text-gray-700">
                                    {{ $row['date'] ?? '-' }}
                                </td>

                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ $row['day'] ?? '-' }}
                                </td>

                                <td class="px-6 py-4">
                                    @if($t === 'rotation')
                                        <x-ui.badge type="info" size="xs">
                                            {{ tr('Rotation') }}{{ $src ? ' (' . $src . ')' : '' }}
                                        </x-ui.badge>
                                    @elseif($t === 'none')
                                        <x-ui.badge type="danger" size="xs">
                                            {{ tr('No schedule') }}
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge type="warning" size="xs">
                                            {{ tr('Single') }}
                                        </x-ui.badge>
                                    @endif
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

                <div class="px-5 py-3 border-t text-xs text-gray-400">
                    {{ tr('Note: Preview is limited to 31 days to avoid heavy queries.') }}
                </div>
            </x-ui.card>

        </div>
    </x-slot>
</x-ui.modal>
