@php
    $locale = app()->getLocale();

    $isRtl = in_array(
        substr($locale, 0, 2),
        ['ar', 'fa', 'ur', 'he']
    );

    $dir = $isRtl ? 'rtl' : 'ltr';

    $headers = [
        tr('Date'),
        $isRtl ? 'بداية الجلسة' : 'Session Start',
        $isRtl ? 'نهاية الجلسة' : 'Session End',
        $isRtl ? 'حالة الجلسة' : 'Session Status',
        $isRtl ? 'إجمالي المسافة' : 'Total Distance',
        $isRtl ? 'النقاط المقبولة' : 'Accepted Points',
        $isRtl ? 'النقاط المرفوضة' : 'Rejected Points',
        $isRtl ? 'أحداث النطاق' : 'Geofence Events',
        $isRtl ? 'التفاصيل' : 'Details',
    ];

    $headerAlign = [
        'start',
        'center',
        'center',
        'center',
        'center',
        'center',
        'center',
        'center',
        'center',
    ];

    $statusLabels = [
        'active' => $isRtl ? 'نشطة' : 'Active',
        'completed' => $isRtl ? 'مكتملة' : 'Completed',
        'cancelled' => $isRtl ? 'ملغاة' : 'Cancelled',
        'expired' => $isRtl ? 'منتهية' : 'Expired',
    ];

    $statusTypes = [
        'active' => 'success',
        'completed' => 'info',
        'cancelled' => 'danger',
        'expired' => 'default',
    ];
@endphp

@section('topbar-left-content')
    <x-ui.page-header
        :title="$isRtl ? 'متابعة الموظفين' : 'Employee Monitoring'"
        :subtitle="$isRtl
            ? 'عرض سجل جلسات العمل لكل موظف'
            : 'Review employee work session history'"
    />
@endsection


<div
    class="space-y-6"
    dir="{{ $dir }}"
>
    @once
        @vite('resources/js/saas-maps.js')
    @endonce
    <x-ui.loading-bar :fullPage="true" />

    {{-- Filters --}}
    <x-ui.card
        padding="false"
        class="!overflow-visible"
    >
        <div class="p-4 sm:p-6 space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-bold text-gray-900">
                        {{ $isRtl ? 'خيارات المتابعة' : 'Monitoring Filters' }}
                    </h2>

                    <p class="mt-1 text-xs text-gray-500">
                        {{
                            $isRtl
                                ? 'اختر الموظف والفترة الزمنية لعرض سجل جلسات العمل.'
                                : 'Select an employee and date range to view work sessions.'
                        }}
                    </p>
                </div>

                <x-ui.secondary-button
                    type="button"
                    wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    size="sm"
                    class="gap-2"
                >
                    <i
                        class="fas fa-rotate-left text-xs"
                        wire:loading.remove
                        wire:target="resetFilters"
                    ></i>

                    <i
                        class="fas fa-spinner fa-spin text-xs"
                        wire:loading
                        wire:target="resetFilters"
                    ></i>

                    <span>
                        {{ $isRtl ? 'إعادة ضبط' : 'Reset' }}
                    </span>
                </x-ui.secondary-button>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                <x-ui.select
                    wire:model.live="branch_id"
                    :label="tr('Branch')"
                    class="w-full"
                >
                    <option value="all">
                        {{ tr('All Branches') }}
                    </option>

                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">
                            {{ $branch->name ?? ('#' . $branch->id) }}
                            @if(filled($branch->code))
                                - {{ $branch->code }}
                            @endif
                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.select
                    wire:model.live="employee_id"
                    :label="tr('Employee')"
                    class="w-full"
                    searchable
                >
                    <option value="">
                        {{ tr('Select employee...') }}
                    </option>

                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">
                            {{
                                $isRtl
                                    ? (
                                        $employee->name_ar
                                        ?: $employee->name_en
                                        ?: ('#' . $employee->id)
                                    )
                                    : (
                                        $employee->name_en
                                        ?: $employee->name_ar
                                        ?: ('#' . $employee->id)
                                    )
                            }}


                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.company-date-picker
                    model="date_from"
                    :label="tr('From Date')"
                />

                <x-ui.company-date-picker
                    model="date_to"
                    :label="tr('To Date')"
                    :min-date="$date_from"
                />

                <x-ui.select
                    wire:model.live="session_status"
                    :label="$isRtl ? 'حالة الجلسة' : 'Session Status'"
                    class="w-full"
                >
                    <option value="all">
                        {{ tr('All') }}
                    </option>

                    <option value="active">
                        {{ $isRtl ? 'نشطة' : 'Active' }}
                    </option>

                    <option value="completed">
                        {{ $isRtl ? 'مكتملة' : 'Completed' }}
                    </option>

                    <option value="cancelled">
                        {{ $isRtl ? 'ملغاة' : 'Cancelled' }}
                    </option>

                    <option value="expired">
                        {{ $isRtl ? 'منتهية' : 'Expired' }}
                    </option>
                </x-ui.select>
            </div>
        </div>
    </x-ui.card>

    @if(! $selectedEmployee)
        <x-ui.card>
            <div class="flex min-h-[220px] flex-col items-center justify-center text-center">
                <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-[color:var(--app-soft-bg)] text-[color:var(--brand-via)]">
                    <i class="fas fa-route text-xl"></i>
                </div>

                <h3 class="text-sm font-bold text-gray-900">
                    {{
                        $isRtl
                            ? 'اختر موظفًا لعرض سجل المتابعة'
                            : 'Select an employee to view monitoring history'
                    }}
                </h3>

                <p class="mt-2 max-w-lg text-xs leading-6 text-gray-500">
                    {{
                        $isRtl
                            ? 'ستظهر هنا جلسات العمل السابقة والحالية للموظف.'
                            : 'Previous and current employee work sessions will appear here.'
                    }}
                </p>
            </div>
        </x-ui.card>
    @else
        <x-ui.card>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[color:var(--app-soft-bg)] text-sm font-black text-[color:var(--brand-via)] border border-[color:var(--border-soft)]">
                        {{
                            mb_substr(
                                $selectedEmployee->name_ar
                                ?: $selectedEmployee->name_en
                                ?: '#',
                                0,
                                1
                            )
                        }}
                    </div>

                    <div class="min-w-0">
                        <div class="font-bold text-gray-900 truncate">
                            {{
                                $isRtl
                                    ? (
                                        $selectedEmployee->name_ar
                                        ?: $selectedEmployee->name_en
                                    )
                                    : (
                                        $selectedEmployee->name_en
                                        ?: $selectedEmployee->name_ar
                                    )
                            }}
                        </div>

                        <div class="mt-1 text-xs text-gray-500">
                            #{{ $selectedEmployee->employee_no ?: $selectedEmployee->id }}
                        </div>
                    </div>
                </div>

                @if($sessions)
                    <x-ui.badge type="info">
                        {{
                            $isRtl
                                ? 'عدد الجلسات: ' . $sessions->total()
                                : 'Sessions: ' . $sessions->total()
                        }}
                    </x-ui.badge>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card padding="false">
            <div class="border-b border-gray-100 p-4 sm:p-6">
                <h2 class="text-sm font-bold text-gray-900">
                    {{ $isRtl ? 'سجل جلسات العمل' : 'Work Session History' }}
                </h2>

                <p class="mt-1 text-xs text-gray-500">
                    {{
                        $isRtl
                            ? 'كل سجل يمثل جلسة عمل مستقلة محفوظة في النظام.'
                            : 'Each row represents an independently stored work session.'
                    }}
                </p>
            </div>

            <div
                wire:loading
                wire:target="branch_id,employee_id,date_from,date_to,session_status"
                class="p-10 text-center"
            >
                <i class="fas fa-spinner fa-spin text-[color:var(--accent-orange)]"></i>

                <span class="ms-2 text-xs text-gray-500">
                    {{ tr('Loading data...') }}
                </span>
            </div>

            <div
                wire:loading.remove
                wire:target="branch_id,employee_id,date_from,date_to,session_status"
            >
                @if($sessions)
                    <x-ui.table
                        :headers="$headers"
                        :headerAlign="$headerAlign"
                        :enablePagination="false"
                    >
                        @forelse($sessions as $session)
                            @php
                                $status = (string) $session->status;

                                $statusLabel =
                                    $statusLabels[$status]
                                    ?? $status;

                                $statusType =
                                    $statusTypes[$status]
                                    ?? 'default';
                            @endphp

                            <tr
                                wire:key="tracking-session-{{ $session->id }}"
                                class="border-b border-gray-100 hover:bg-gray-50/70 transition-colors"
                            >
                                <td class="px-6 py-3 text-start text-xs font-semibold text-gray-800 whitespace-nowrap">
                                    {{
                                        $session->started_at
                                            ? company_date(
                                                $session->started_at->toDateString()
                                            )
                                            : '-'
                                    }}
                                </td>

                                <td class="px-6 py-3 text-center text-xs font-mono text-gray-700 whitespace-nowrap">
                                    {{
                                        $session->started_at
                                            ? company_time(
                                                $session->started_at->format('H:i:s')
                                            )
                                            : '-'
                                    }}
                                </td>

                                <td class="px-6 py-3 text-center text-xs font-mono text-gray-700 whitespace-nowrap">
                                    @if($session->ended_at)
                                        {{
                                            company_time(
                                                $session->ended_at->format('H:i:s')
                                            )
                                        }}
                                    @else
                                        <span class="font-bold text-[color:var(--success)]">
                                            {{ $isRtl ? 'مستمرة' : 'Ongoing' }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 text-center whitespace-nowrap">
                                    <x-ui.badge :type="$statusType">
                                        {{ $statusLabel }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-3 text-center text-xs font-bold text-gray-800 whitespace-nowrap">
                                    {{
                                        number_format(
                                            ((float) $session->total_distance_meters) / 1000,
                                            2
                                        )
                                    }}
                                    <span class="font-normal text-gray-400">
                                        {{ $isRtl ? 'كم' : 'km' }}
                                    </span>
                                </td>

                                <td class="px-6 py-3 text-center text-xs font-bold text-[color:var(--success)]">
                                    {{ (int) $session->accepted_points_count }}
                                </td>

                                <td class="px-6 py-3 text-center text-xs font-bold text-[color:var(--error)]">
                                    {{ (int) $session->rejected_points_count }}
                                </td>

                                <td class="px-6 py-3 text-center text-xs font-bold text-gray-700">
                                    {{ (int) ($session->geofence_events_count ?? 0) }}
                                </td>

                                <td class="px-6 py-3 text-center whitespace-nowrap">
                                    <x-ui.secondary-button
                                        type="button"
                                        wire:click="openSession({{ $session->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openSession({{ $session->id }})"
                                        size="sm"
                                        class="gap-2"
                                    >
                                        <i class="fas fa-eye text-xs"></i>
                                        <span>
                                            {{ $isRtl ? 'عرض التفاصيل' : 'View Details' }}
                                        </span>
                                    </x-ui.secondary-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td
                                    colspan="9"
                                    class="px-6 py-12 text-center text-sm text-gray-500"
                                >
                                    <i class="fas fa-folder-open text-2xl text-gray-300 mb-3"></i>

                                    <div>
                                        {{
                                            $isRtl
                                                ? 'لا توجد جلسات ضمن الفترة المحددة.'
                                                : 'No sessions found in the selected period.'
                                        }}
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </x-ui.table>

                    @if($sessions->hasPages())
                        <div class="border-t border-gray-100 p-4">
                            {{ $sessions->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </x-ui.card>
    @endif

        @if($selectedSession)
            @php
                $modalStatus = (string) $selectedSession->status;
                $modalStatusLabel = $statusLabels[$modalStatus] ?? $modalStatus;
                $modalStatusType = $statusTypes[$modalStatus] ?? 'default';

                $durationMinutes = 0;

                if ($selectedSession->started_at) {
                    $durationMinutes = (int) abs(
                        $selectedSession
                            ->started_at
                            ->diffInMinutes(
                                $selectedSession->ended_at
                                ?? now()
                            )
                    );
                }

                $durationHours = intdiv($durationMinutes, 60);
                $durationRemainingMinutes = $durationMinutes % 60;
            @endphp

                <style>
        /* ATHKA_TRACKING_MODAL_COMPACT_LAYOUT_V2 */
        .athka-tracking-modal-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }

        .athka-tracking-modal-timeline {
            max-height: 330px;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        @media (min-width: 1100px) {
            .athka-tracking-modal-grid {
                grid-template-columns:
                    minmax(0, 1.55fr)
                    minmax(300px, 0.85fr);
            }
        }
    </style>
<x-ui.modal
                wire:model="showSessionModal"
                :title="$isRtl ? 'تفاصيل جلسة العمل' : 'Work Session Details'"
                :subtitle="$isRtl
                    ? 'السجل الزمني المعتمد للجلسة وأحداث نطاق العمل'
                    : 'Authoritative session timeline and work-geofence events'"
                max-width="7xl"
            >
                <x-slot:content>
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :type="$modalStatusType">
                                {{ $modalStatusLabel }}
                            </x-ui.badge>

                            <x-ui.badge type="default">
                                {{
                                    $isRtl
                                        ? 'رقم الجلسة: ' . $selectedSession->id
                                        : 'Session #' . $selectedSession->id
                                }}
                            </x-ui.badge>
                        </div>

                        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                            <x-ui.card class="!p-4">
                                <div class="text-[10px] font-bold text-gray-400">
                                    {{ $isRtl ? 'البداية' : 'Start' }}
                                </div>
                                <div class="mt-2 text-xs font-bold text-gray-900">
                                    {{
                                        $selectedSession->started_at
                                            ? company_time($selectedSession->started_at->format('H:i:s'))
                                            : '-'
                                    }}
                                </div>
                            </x-ui.card>

                            <x-ui.card class="!p-4">
                                <div class="text-[10px] font-bold text-gray-400">
                                    {{ $isRtl ? 'النهاية' : 'End' }}
                                </div>
                                <div class="mt-2 text-xs font-bold text-gray-900">
                                    {{
                                        $selectedSession->ended_at
                                            ? company_time($selectedSession->ended_at->format('H:i:s'))
                                            : ($isRtl ? 'مستمرة' : 'Ongoing')
                                    }}
                                </div>
                            </x-ui.card>

                            <x-ui.card class="!p-4">
                                <div class="text-[10px] font-bold text-gray-400">
                                    {{ $isRtl ? 'المدة' : 'Duration' }}
                                </div>
                                <div class="mt-2 text-xs font-bold text-gray-900">
                                    {{ $durationHours }} {{ $isRtl ? 'س' : 'h' }}
                                    {{ $durationRemainingMinutes }} {{ $isRtl ? 'د' : 'm' }}
                                </div>
                            </x-ui.card>

                            <x-ui.card class="!p-4">
                                <div class="text-[10px] font-bold text-gray-400">
                                    {{ $isRtl ? 'إجمالي المسافة' : 'Distance' }}
                                </div>
                                <div class="mt-2 text-xs font-bold text-gray-900">
                                    {{
                                        number_format(
                                            ((float) $selectedSession->total_distance_meters) / 1000,
                                            2
                                        )
                                    }}
                                    {{ $isRtl ? 'كم' : 'km' }}
                                </div>
                            </x-ui.card>

                            <x-ui.card class="!p-4">
                                <div class="text-[10px] font-bold text-gray-400">
                                    {{ $isRtl ? 'النقاط المقبولة' : 'Accepted' }}
                                </div>
                                <div class="mt-2 text-xs font-bold text-[color:var(--success)]">
                                    {{ (int) $selectedSession->accepted_points_count }}
                                </div>
                            </x-ui.card>

                            <x-ui.card class="!p-4">
                                <div class="text-[10px] font-bold text-gray-400">
                                    {{ $isRtl ? 'أحداث النطاق' : 'Geofence Events' }}
                                </div>
                                <div class="mt-2 text-xs font-bold text-gray-900">
                                    {{ $selectedSession->geofenceEvents->count() }}
                                </div>
                            </x-ui.card>
                        </div>

                        <div class="athka-tracking-modal-grid">
                        <div class="min-w-0"><x-ui.card padding="false">
                            <div class="border-b border-gray-100 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-bold text-gray-900">
                                            {{ $isRtl ? 'مسار الجلسة على الخريطة' : 'Session Route Map' }}
                                        </h3>

                                        <p class="mt-1 text-xs text-gray-500">
                                            {{
                                                $isRtl
                                                    ? 'يعرض النقاط المقبولة ونطاقات العمل المرتبطة فعليًا بهذه الجلسة.'
                                                    : 'Shows accepted points and work geofences actually linked to this session.'
                                            }}
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-ui.badge type="success" size="sm">
                                            {{ count($selectedSessionMapData['points'] ?? []) }}
                                            {{ $isRtl ? 'نقطة مسار' : 'Route points' }}
                                        </x-ui.badge>

                                        <x-ui.badge type="info" size="sm">
                                            {{ count($selectedSessionMapData['geofences'] ?? []) }}
                                            {{ $isRtl ? 'نطاق عمل' : 'Geofences' }}
                                        </x-ui.badge>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="p-4"
                                wire:key="tracking-session-map-{{ $selectedSession->id }}"
                                x-data="athkaTrackingSessionMap(@js($selectedSessionMapData))"
                            >
                                <div
                                    x-ref="map"
                                    wire:ignore
                                    data-tracking-session-map
                                    class="w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-50"
                                    style="height: 255px; min-height: 255px;"
                                ></div>

                                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-gray-500">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>
                                        {{ $isRtl ? 'بداية الجلسة' : 'Session start' }}
                                    </span>

                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full bg-slate-700"></span>
                                        {{ $isRtl ? 'النهاية / آخر موقع' : 'End / last position' }}
                                    </span>

                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-0.5 w-5 bg-[color:var(--brand-via)]"></span>
                                        {{ $isRtl ? 'المسار المقبول' : 'Accepted route' }}
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full border border-white bg-[color:var(--brand-via)] shadow-sm"></span>
                                        {{ $isRtl ? 'نقطة GPS مقبولة' : 'Accepted GPS point' }}
                                    </span>

                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full border-2 border-[color:var(--accent-orange)]"></span>
                                        {{ $isRtl ? 'نطاق العمل' : 'Work geofence' }}
                                    </span>

                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>
                                        {{ $isRtl ? 'خروج مؤكد' : 'Confirmed exit' }}
                                    </span>

                                <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-[11px] leading-5 text-gray-500">
                                    {{
                                        $isRtl
                                            ? 'ملاحظة: قد تتداخل نقاط GPS المتقاربة بصريًا إذا كانت حركة الموظف محدودة داخل مساحة صغيرة. مرّر المؤشر فوق أي نقطة لعرض رقمها ووقتها وحالة النطاق.'
                                            : 'Note: Closely spaced GPS points may visually overlap when movement is limited to a small area. Hover over a point to see its number, time, and geofence state.'
                                    }}
                                </div>                                </div>
                            </div>
                        </x-ui.card>
                        </div>
                        <div class="athka-tracking-modal-timeline min-w-0"><x-ui.card padding="false">
                            <div class="border-b border-gray-100 p-4">
                                <h3 class="text-sm font-bold text-gray-900">
                                    {{ $isRtl ? 'السجل الزمني للجلسة' : 'Session Timeline' }}
                                </h3>

                                <p class="mt-1 text-xs text-gray-500">
                                    {{
                                        $isRtl
                                            ? 'يعرض الأحداث المؤكدة والمحفوظة على السيرفر فقط.'
                                            : 'Only confirmed events stored by the server are shown.'
                                    }}
                                </p>
                            </div>

                            <div class="p-4 sm:p-5">
                                <div class="space-y-5">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600 border border-green-100">
                                            <i class="fas fa-play text-[10px]"></i>
                                        </div>

                                        <div class="flex min-w-0 flex-1 items-center justify-between gap-3 pt-1">
                                            <div class="text-xs font-bold text-gray-900">
                                                {{ $isRtl ? 'بدأت جلسة العمل' : 'Work session started' }}
                                            </div>

                                            <div class="text-[11px] font-mono text-gray-500">
                                                {{
                                                    $selectedSession->started_at
                                                        ? company_time($selectedSession->started_at->format('H:i:s'))
                                                        : '-'
                                                }}
                                            </div>
                                        </div>
                                    </div>

                                    @forelse($selectedSession->geofenceEvents as $event)
                                        <div class="flex items-start gap-3">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 border border-red-100">
                                                <i class="fas fa-arrow-right-from-bracket text-[10px]"></i>
                                            </div>

                                            <div class="min-w-0 flex-1 pt-1">
                                                <div class="flex flex-wrap items-center justify-between gap-3">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <div class="text-xs font-bold text-gray-900">
                                                            {{ $isRtl ? 'خرج من نطاق العمل' : 'Exited work geofence' }}
                                                        </div>

                                                        @if($event->is_counted)
                                                            <x-ui.badge type="danger" size="sm">
                                                                {{ $isRtl ? 'محسوب' : 'Counted' }}
                                                            </x-ui.badge>
                                                        @else
                                                            <x-ui.badge type="warning" size="sm">
                                                                {{ $isRtl ? 'مستبعد' : 'Excluded' }}
                                                            </x-ui.badge>
                                                        @endif
                                                    </div>

                                                    <div class="text-[11px] font-mono text-gray-500">
                                                        {{
                                                            $event->exited_at
                                                                ? company_time($event->exited_at->format('H:i:s'))
                                                                : '-'
                                                        }}
                                                    </div>
                                                </div>

                                                <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
                                                    <div>
                                                        <div class="text-[10px] text-gray-400">
                                                            {{ $isRtl ? 'مدة البقاء خارج النطاق' : 'Outside duration' }}
                                                        </div>
                                                        <div class="mt-1 text-xs font-bold text-gray-700">
                                                            {{ (int) $event->outside_seconds }}
                                                            {{ $isRtl ? 'ث' : 'sec' }}
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="text-[10px] text-gray-400">
                                                            {{ $isRtl ? 'المدة المحتسبة' : 'Counted duration' }}
                                                        </div>
                                                        <div class="mt-1 text-xs font-bold text-gray-700">
                                                            {{ (int) $event->counted_outside_seconds }}
                                                            {{ $isRtl ? 'ث' : 'sec' }}
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="text-[10px] text-gray-400">
                                                            {{ $isRtl ? 'أقصى ابتعاد' : 'Maximum distance' }}
                                                        </div>
                                                        <div class="mt-1 text-xs font-bold text-gray-700">
                                                            {{
                                                                number_format(
                                                                    (float) $event->maximum_distance_to_boundary_meters,
                                                                    1
                                                                )
                                                            }}
                                                            {{ $isRtl ? 'م' : 'm' }}
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="text-[10px] text-gray-400">
                                                            {{ $isRtl ? 'مسار خارج النطاق' : 'Outside route' }}
                                                        </div>
                                                        <div class="mt-1 text-xs font-bold text-gray-700">
                                                            {{
                                                                number_format(
                                                                    (float) $event->outside_route_distance_meters,
                                                                    1
                                                                )
                                                            }}
                                                            {{ $isRtl ? 'م' : 'm' }}
                                                        </div>
                                                    </div>
                                                </div>

                                                @if(
                                                    ! $event->is_counted
                                                    && filled($event->exclusion_reason)
                                                )
                                                    <div class="mt-3 rounded-lg bg-yellow-50 px-3 py-2 text-[11px] text-yellow-800">
                                                        <span class="font-bold">
                                                            {{ $isRtl ? 'سبب الاستبعاد:' : 'Exclusion reason:' }}
                                                        </span>

                                                        {{ $event->exclusion_reason }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        @if($event->returned_at)
                                            <div class="flex items-start gap-3">
                                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600 border border-green-100">
                                                    <i class="fas fa-arrow-right-to-bracket text-[10px]"></i>
                                                </div>

                                                <div class="flex min-w-0 flex-1 items-center justify-between gap-3 pt-1">
                                                    <div class="text-xs font-bold text-gray-900">
                                                        {{ $isRtl ? 'عاد إلى نطاق العمل' : 'Returned to work geofence' }}
                                                    </div>

                                                    <div class="text-[11px] font-mono text-gray-500">
                                                        {{ company_time($event->returned_at->format('H:i:s')) }}
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @empty
                                        <div class="rounded-xl bg-green-50/50 p-4 text-center">
                                            <div class="text-xs font-bold text-gray-700">
                                                {{
                                                    $isRtl
                                                        ? 'لا توجد أحداث خروج مؤكدة في هذه الجلسة'
                                                        : 'No confirmed exit events in this session'
                                                }}
                                            </div>
                                        </div>
                                    @endforelse

                                    @if($selectedSession->ended_at)
                                        <div class="flex items-start gap-3">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 border border-gray-200">
                                                <i class="fas fa-stop text-[10px]"></i>
                                            </div>

                                            <div class="flex min-w-0 flex-1 items-center justify-between gap-3 pt-1">
                                                <div class="text-xs font-bold text-gray-900">
                                                    {{ $isRtl ? 'انتهت جلسة العمل' : 'Work session ended' }}
                                                </div>

                                                <div class="text-[11px] font-mono text-gray-500">
                                                    {{ company_time($selectedSession->ended_at->format('H:i:s')) }}
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </x-ui.card>
                        </div>
                    </div>
                    </div>
                </x-slot:content>

                <x-slot:footer>
                    <x-ui.secondary-button
                        type="button"
                        wire:click="closeSessionModal"
                    >
                        {{ $isRtl ? 'إغلاق' : 'Close' }}
                    </x-ui.secondary-button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
</div>
<script>
    window.athkaTrackingSessionMap = window.athkaTrackingSessionMap || function (payload) {
        return {
            payload: payload || {},
            map: null,
            initialized: false,
            baseLayer: null,
            labelsLayer: null,
            renderedLayers: [],
            _setupTimers: [],
            _waitingForMapAssets: false,

            init() {
                const setup = () => {
                    this.setupMap();
                };

                /*
                 * Match the proven Attendance Map Picker modal lifecycle.
                 * The modal needs a short time to settle before Leaflet
                 * calculates panes, tiles and vector positions correctly.
                 */
                this.$nextTick(() => {
                    this._setupTimers.push(setTimeout(setup, 100));
                    this._setupTimers.push(setTimeout(setup, 400));
                    this._setupTimers.push(setTimeout(setup, 800));
                });
            },

            setupMap() {
                const container = this.$refs.map;

                if (!container) {
                    return;
                }

                if (!window.L) {
                    if (!this._waitingForMapAssets) {
                        this._waitingForMapAssets = true;

                        window.addEventListener(
                            'athka:map-assets-ready',
                            () => {
                                this._waitingForMapAssets = false;

                                this._setupTimers.push(
                                    setTimeout(
                                        () => this.setupMap(),
                                        100
                                    )
                                );
                            },
                            { once: true }
                        );
                    }

                    return;
                }

                if (
                    container.offsetWidth <= 0
                    || container.offsetHeight <= 0
                ) {
                    this._setupTimers.push(
                        setTimeout(
                            () => this.setupMap(),
                            150
                        )
                    );

                    return;
                }

                if (!this.map) {
                    this.createMap();
                }

                if (!this.map) {
                    return;
                }

                this.map.invalidateSize({
                    pan: false,
                    animate: false,
                });

                this.fitTrackingBounds();
            },

            initialTrackingCenter() {
                const validCoordinate = (value) => {
                    return value
                        && Number.isFinite(Number(value.lat))
                        && Number.isFinite(Number(value.lng));
                };

                const points = Array.isArray(this.payload.points)
                    ? this.payload.points
                    : [];

                const geofences = Array.isArray(this.payload.geofences)
                    ? this.payload.geofences
                    : [];

                const candidates = [
                    this.payload.start,
                    points.find(validCoordinate) || null,
                    this.payload.last,
                    this.payload.end,
                    geofences.find(validCoordinate) || null,
                ];

                const coordinate = candidates.find(validCoordinate);

                if (coordinate) {
                    return [
                        Number(coordinate.lat),
                        Number(coordinate.lng),
                    ];
                }

                return [15.3694, 44.1910];
            },
            createMap() {
                const L = window.L;
                const container = this.$refs.map;

                if (!L || !container) {
                    return;
                }

                if (this.map) {
                    this.map.remove();
                    this.map = null;
                }

                if (container._leaflet_id) {
                    container._leaflet_id = null;
                }

                /*
                 * The working Attendance Map Picker creates Leaflet with
                 * an initial center/zoom before adding tile/vector layers.
                 *
                 * Without an initial view Leaflet remains not-loaded and
                 * GridLayer/Path onAdd handlers wait for the first map load.
                 * That produced the grey map with empty custom panes.
                 */
                this.map = L.map(
                    container,
                    {
                        zoomControl: true,
                        attributionControl: false,
                        preferCanvas: true,
                        zoomSnap: 0.5,
                        zoomDelta: 0.5,
                    }
                ).setView(
                    this.initialTrackingCenter(),
                    15
                );

                this.createPanes();
                this.addBaseLayers();
                this.renderTrackingData();

                this.initialized = true;

                this._setupTimers.push(
                    setTimeout(
                        () => {
                            if (!this.map) {
                                return;
                            }

                            this.map.invalidateSize({
                                pan: false,
                                animate: false,
                            });

                            this.fitTrackingBounds();
                        },
                        180
                    )
                );
            },

            createPanes() {
                const panes = [
                    ['athkaBasePane', 200],
                    ['athkaGeofencePane', 430],
                    ['athkaRoutePane', 500],
                    ['athkaMarkerPane', 610],
                    ['athkaLabelsPane', 650],
                ];

                panes.forEach(([name, zIndex]) => {
                    if (!this.map.getPane(name)) {
                        this.map.createPane(name);
                    }

                    this.map.getPane(name).style.zIndex = zIndex;
                });

                this.map.getPane(
                    'athkaLabelsPane'
                ).style.pointerEvents = 'none';
            },

            addBaseLayers() {
                const L = window.L;

                const cartoOptions = {
                    maxZoom: 20,
                    minZoom: 2,
                    subdomains: 'abcd',
                    detectRetina: true,
                    pane: 'athkaBasePane',
                    attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
                    crossOrigin: true,
                };

                const labelOptions = {
                    ...cartoOptions,
                    pane: 'athkaLabelsPane',
                    attribution: '',
                };

                this.baseLayer = L.tileLayer(
                    'https://{s}.basemaps.cartocdn.com/rastertiles/voyager_nolabels/{z}/{x}/{y}{r}.png',
                    cartoOptions
                ).addTo(this.map);

                this.labelsLayer = L.tileLayer(
                    'https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png',
                    labelOptions
                ).addTo(this.map);
            },

            renderTrackingData() {
                const L = window.L;

                const rootStyle = getComputedStyle(
                    document.documentElement
                );

                const brand = (
                    rootStyle
                        .getPropertyValue('--brand-via')
                        .trim()
                    || '#903749'
                );

                const points = Array.isArray(this.payload.points)
                    ? this.payload.points
                    : [];

                const geofences = Array.isArray(this.payload.geofences)
                    ? this.payload.geofences
                    : [];

                const events = Array.isArray(this.payload.events)
                    ? this.payload.events
                    : [];

                const validCoordinate = (value) => {
                    return value
                        && Number.isFinite(Number(value.lat))
                        && Number.isFinite(Number(value.lng));
                };

                const trackLayer = (layer) => {
                    if (layer) {
                        this.renderedLayers.push(layer);
                    }

                    return layer;
                };

                geofences.forEach((location) => {
                    const type = String(
                        location.geofence_type || 'circle'
                    ).toLowerCase();

                    if (
                        type === 'polygon'
                        && location.boundary_geojson
                    ) {
                        try {
                            const polygon = L.geoJSON(
                                location.boundary_geojson,
                                {
                                    pane: 'athkaGeofencePane',
                                    style: {
                                        color: '#8f2945',
                                        weight: 3,
                                        opacity: 0.96,
                                        fillColor: '#b83d5d',
                                        fillOpacity: 0.18,
                                        className: 'athka-geofence-shape',
                                    },
                                }
                            ).addTo(this.map);

                            if (location.name) {
                                polygon.bindTooltip(
                                    String(location.name)
                                );
                            }

                            trackLayer(polygon);
                        } catch (error) {
                            console.error(
                                'Unable to render tracking polygon geofence.',
                                error
                            );
                        }

                        return;
                    }

                    if (
                        type === 'circle'
                        && validCoordinate(location)
                        && Number(location.radius_meters) > 0
                    ) {
                        const circle = L.circle(
                            [
                                Number(location.lat),
                                Number(location.lng),
                            ],
                            {
                                radius: Number(location.radius_meters),
                                color: '#8f2945',
                                weight: 3,
                                opacity: 0.96,
                                fillColor: '#b83d5d',
                                fillOpacity: 0.18,
                                className: 'athka-geofence-shape',
                                pane: 'athkaGeofencePane',
                            }
                        ).addTo(this.map);

                        if (location.name) {
                            circle.bindTooltip(
                                String(location.name)
                            );
                        }

                        trackLayer(circle);
                    }
                });

                const routeCoordinates = points
                    .filter(validCoordinate)
                    .map((point) => [
                        Number(point.lat),
                        Number(point.lng),
                    ]);

                if (routeCoordinates.length >= 2) {
                    /*
                     * Route halo improves readability when the employee
                     * movement is short and sits almost entirely inside
                     * the work geofence.
                     */
                    trackLayer(
                        L.polyline(
                            routeCoordinates,
                            {
                                color: '#ffffff',
                                weight: 8,
                                opacity: 0.88,
                                pane: 'athkaRoutePane',
                                lineCap: 'round',
                                lineJoin: 'round',
                            }
                        ).addTo(this.map)
                    );

                    trackLayer(
                        L.polyline(
                            routeCoordinates,
                            {
                                color: brand,
                                weight: 4,
                                opacity: 1,
                                pane: 'athkaRoutePane',
                                lineCap: 'round',
                                lineJoin: 'round',
                            }
                        ).addTo(this.map)
                    );

                    /*
                     * Show each accepted tracking sample as a very small
                     * read-only point. This does not invent or alter any
                     * location; it only makes the stored route legible.
                     */
                    routeCoordinates.forEach((coordinate, index) => {
                        const athkaRoutePoint = L.circleMarker(
                            coordinate,
                            {
                                radius: 2.75,
                                color: '#ffffff',
                                weight: 1.5,
                                fillColor: brand,
                                fillOpacity: 1,
                                pane: 'athkaRoutePane',
                                interactive: true,
                            }
                        ).addTo(this.map);

                        const sourcePoint = points[index] || {};
                        const recordedAt = sourcePoint.recorded_at
                            ? new Date(sourcePoint.recorded_at)
                            : null;

                        const timeText = (
                            recordedAt
                            && !Number.isNaN(recordedAt.getTime())
                        )
                            ? recordedAt.toLocaleTimeString(
                                @js($isRtl ? 'ar-YE' : 'en-US'),
                                {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    second: '2-digit',
                                }
                            )
                            : '-';

                        let geofenceText = @js(
                            $isRtl
                                ? 'حالة النطاق غير محددة'
                                : 'Geofence state unavailable'
                        );

                        if (sourcePoint.inside_allowed_geofence === true) {
                            geofenceText = @js(
                                $isRtl
                                    ? 'داخل نطاق العمل'
                                    : 'Inside work geofence'
                            );
                        } else if (
                            sourcePoint.inside_allowed_geofence === false
                        ) {
                            geofenceText = @js(
                                $isRtl
                                    ? 'خارج نطاق العمل'
                                    : 'Outside work geofence'
                            );
                        }

                        athkaRoutePoint.bindTooltip(
                            [
                                @js($isRtl ? 'نقطة المسار' : 'Route point')
                                    + ' '
                                    + (index + 1),
                                timeText,
                                geofenceText,
                            ].join('<br>'),
                            {
                                direction: 'top',
                                opacity: 0.96,
                            }
                        );

                        trackLayer(athkaRoutePoint);
                    });
                }

                const firstRoutePoint =
                    points.find(validCoordinate)
                    || null;

                const lastRoutePoint =
                    [...points]
                        .reverse()
                        .find(validCoordinate)
                    || null;

                const startCoordinate =
                    validCoordinate(this.payload.start)
                        ? this.payload.start
                        : firstRoutePoint;

                const completed =
                    String(this.payload.status) === 'completed';

                const endCoordinate = completed
                    ? (
                        validCoordinate(this.payload.end)
                            ? this.payload.end
                            : (
                                validCoordinate(this.payload.last)
                                    ? this.payload.last
                                    : lastRoutePoint
                            )
                    )
                    : (
                        validCoordinate(this.payload.last)
                            ? this.payload.last
                            : lastRoutePoint
                    );

                const addCircleMarker = (
                    coordinate,
                    options,
                    tooltip
                ) => {
                    if (!validCoordinate(coordinate)) {
                        return;
                    }

                    const marker = L.circleMarker(
                        [
                            Number(coordinate.lat),
                            Number(coordinate.lng),
                        ],
                        {
                            ...options,
                            pane: 'athkaMarkerPane',
                        }
                    ).addTo(this.map);

                    if (tooltip) {
                        marker.bindTooltip(
                            tooltip,
                            {
                                direction: 'top',
                            }
                        );
                    }

                    trackLayer(marker);
                };

                addCircleMarker(
                    startCoordinate,
                    {
                        radius: 8,
                        color: '#14532d',
                        weight: 2.5,
                        fillColor: '#22c55e',
                        fillOpacity: 1,
                    },
                    @js($isRtl ? 'بداية الجلسة' : 'Session start')
                );

                addCircleMarker(
                    endCoordinate,
                    {
                        radius: 8,
                        color: '#0f172a',
                        weight: 2.5,
                        fillColor: '#475569',
                        fillOpacity: 1,
                    },
                    completed
                        ? @js($isRtl ? 'نهاية الجلسة' : 'Session end')
                        : @js($isRtl ? 'آخر موقع' : 'Last position')
                );

                events.forEach((event) => {
                    addCircleMarker(
                        event.exit,
                        {
                            radius: 6,
                            color: '#b91c1c',
                            weight: 2,
                            fillColor: '#ef4444',
                            fillOpacity: 1,
                        },
                        @js($isRtl ? 'خروج مؤكد من النطاق' : 'Confirmed geofence exit')
                    );

                    addCircleMarker(
                        event.return,
                        {
                            radius: 6,
                            color: '#047857',
                            weight: 2,
                            fillColor: '#10b981',
                            fillOpacity: 1,
                        },
                        @js($isRtl ? 'عودة مؤكدة إلى النطاق' : 'Confirmed geofence return')
                    );
                });

                if (this.renderedLayers.length === 0) {
                    this.map.setView(
                        [15.3694, 44.1910],
                        13
                    );
                }
            },

            fitTrackingBounds() {
                if (
                    !this.map
                    || this.renderedLayers.length === 0
                ) {
                    return;
                }

                const group = window.L.featureGroup(
                    this.renderedLayers
                );

                const bounds = group.getBounds();

                if (!bounds.isValid()) {
                    return;
                }

                this.map.fitBounds(
                    bounds,
                    {
                        padding: [30, 30],
                        maxZoom: 18,
                        animate: false,
                    }
                );
            },

            destroy() {
                this._setupTimers.forEach((timer) => {
                    clearTimeout(timer);
                });

                this._setupTimers = [];

                if (this.map) {
                    this.map.remove();
                    this.map = null;
                }

                this.renderedLayers = [];
                this.baseLayer = null;
                this.labelsLayer = null;
                this.initialized = false;
            },
        };
    };
</script>
