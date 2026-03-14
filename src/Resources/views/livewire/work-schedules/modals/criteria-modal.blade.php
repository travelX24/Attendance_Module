{{-- Bulk by Criteria + Preview Modal --}}
<x-ui.modal
    wire:model="showCriteriaModal"
    max-width="5xl"
>
    <x-slot name="title">{{ tr('Select Employees by Criteria') }}</x-slot>
    <x-slot name="content">
        <div class="space-y-5">

            <x-ui.card>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    {{-- Department --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Department') }}</label>
                        <x-ui.select wire:model.live="criteriaForm.department_id" class="w-full" align="down" :search="true" :disabled="!auth()->user()->can('attendance.manage')">
                            <option value="all">{{ tr('All Departments') }}</option>
                            @foreach($departments as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Job Title --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Job Title') }}</label>
                        <x-ui.select wire:model.live="criteriaForm.job_title_id" class="w-full" align="down" :disabled="empty($jobTitles) || !auth()->user()->can('attendance.manage')">
                            <option value="all">{{ tr('All Job Titles') }}</option>
                            @foreach(($jobTitles ?? []) as $jt)
                                @php
    $label = app()->isLocale('ar')
        ? ($jt->title_ar ?: $jt->title_en ?: ($jt->title ?? '-'))
        : ($jt->title_en ?: $jt->title_ar ?: ($jt->title ?? '-'));
@endphp
<option value="{{ $jt->id }}">{{ $label }}</option>

                            @endforeach
                        </x-ui.select>
                        @if(empty($jobTitles))
                            <div class="text-xs text-gray-400 mt-1">{{ tr('Job titles list is not available') }}</div>
                        @endif
                    </div>

                    {{-- Location --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Location') }}</label>
                        <x-ui.select wire:model.live="criteriaForm.location_id" class="w-full" align="down" :disabled="empty($locations) || !auth()->user()->can('attendance.manage')">
                            <option value="all">{{ tr('All Locations') }}</option>
                            @foreach(($locations ?? []) as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->name ?? '-' }}</option>
                            @endforeach
                        </x-ui.select>
                        @if(empty($locations))
                            <div class="text-xs text-gray-400 mt-1">{{ tr('Locations list is not available') }}</div>
                        @endif
                    </div>

                    {{-- Contract Type --}}
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Contract') }}</label>
                        <x-ui.select wire:model.live="criteriaForm.contract_type" class="w-full" align="down" :disabled="empty($contractTypes) || !auth()->user()->can('attendance.manage')">
                            <option value="all">{{ tr('All Contracts') }}</option>
                            @foreach(($contractTypes ?? []) as $ct)
                                <option value="{{ $ct }}">{{ $ct }}</option>
                            @endforeach
                        </x-ui.select>
                        @if(empty($contractTypes))
                            <div class="text-xs text-gray-400 mt-1">{{ tr('Contract types are not available') }}</div>
                        @endif
                    </div>

                    {{-- Mode --}}
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ tr('Selection Mode') }}</label>
                        <div class="flex flex-wrap items-center gap-4">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="radio" wire:model.live="criteriaForm.mode" value="replace" @cannot('attendance.manage') disabled @endcannot>
                                <span>{{ tr('Replace current selection') }}</span>
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="radio" wire:model.live="criteriaForm.mode" value="add" @cannot('attendance.manage') disabled @endcannot>
                                <span>{{ tr('Add to current selection') }}</span>
                            </label>
                        </div>
                    </div>

                </div>

                @can('attendance.manage')
                <div class="flex items-center justify-end gap-3 mt-4">
                    <x-ui.secondary-button type="button" wire:click="previewCriteriaSelection" class="gap-2">
                        <i class="fas fa-eye"></i>
                        <span>{{ tr('Preview') }}</span>
                    </x-ui.secondary-button>
                </div>
                @endcan
            </x-ui.card>

            {{-- Preview --}}
            <x-ui.card padding="false" class="overflow-hidden">
                <div class="p-4 border-b border-gray-100 bg-gray-50/30 flex items-center justify-between gap-3">
                    <div class="text-sm font-bold text-gray-900">
                        {{ tr('Preview Result') }}
                        <span class="text-xs text-gray-500 ms-2">
                            {{ tr('Total') }}: {{ (int) $criteriaPreviewTotal }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        @can('attendance.manage')
                        <x-ui.secondary-button type="button" wire:click="selectAllCriteriaPreview" class="!text-xs">
                            {{ tr('Select All') }}
                        </x-ui.secondary-button>
                        <x-ui.secondary-button type="button" wire:click="clearCriteriaPreviewSelection" class="!text-xs">
                            {{ tr('Clear') }}
                        </x-ui.secondary-button>
                        @endcan
                    </div>
                </div>

                <div class="p-4">
                    @if(empty($criteriaPreviewEmployees))
                        <div class="text-sm text-gray-500 italic">
                            {{ tr('Click Preview to load employees matching your criteria.') }}
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-start divide-y divide-gray-100">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 w-4"></th>
                                        <th class="px-4 py-3 text-xs font-bold text-gray-500 uppercase">{{ tr('Employee') }}</th>
                                        <th class="px-4 py-3 text-xs font-bold text-gray-500 uppercase">{{ tr('Department') }}</th>
                                        <th class="px-4 py-3 text-xs font-bold text-gray-500 uppercase">{{ tr('Job Title') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    @foreach($criteriaPreviewEmployees as $row)
                                        <tr class="hover:bg-gray-50/50">
                                            <td class="px-4 py-3">
                                                <input
                                                    type="checkbox"
                                                    wire:model.live="criteriaPreviewSelected"
                                                    value="{{ $row['id'] }}"
                                                    class="w-4 h-4 text-[color:var(--brand-via)] border-gray-300 rounded"
                                                    @cannot('attendance.manage') disabled @endcannot
                                                >
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="text-sm font-semibold text-gray-900">{{ $row['name'] }}</div>
                                                <div class="text-xs text-gray-500">#{{ $row['employee_no'] }}</div>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700">{{ $row['department'] }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-700">{{ $row['job_title'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="text-xs text-gray-500 mt-3">
                            {{ tr('Preview is limited to first 200 employees for performance.') }}
                        </div>
                    @endif
                </div>
            </x-ui.card>

        </div>
    </x-slot>

    <x-slot name="footer">
        <div class="flex items-center justify-end gap-3 w-full">
            <x-ui.secondary-button wire:click="$set('showCriteriaModal', false)">
                {{ tr('Close') }}
            </x-ui.secondary-button>

            @can('attendance.manage')
            <x-ui.primary-button
                wire:click="applyCriteriaSelectionToBulk"
                wire:loading.attr="disabled"
                class="gap-2"
            >
                <i class="fas fa-check" wire:loading.remove wire:target="applyCriteriaSelectionToBulk"></i>
                <i class="fas fa-spinner fa-spin" wire:loading wire:target="applyCriteriaSelectionToBulk"></i>
                <span>{{ tr('Apply to Bulk') }} ({{ is_array($criteriaPreviewSelected) ? count($criteriaPreviewSelected) : 0 }})</span>
            </x-ui.primary-button>
            @endcan
        </div>
    </x-slot>
</x-ui.modal>
