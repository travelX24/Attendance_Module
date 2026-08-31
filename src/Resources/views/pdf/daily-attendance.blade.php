<!doctype html>
@php
    $locale = substr(app()->getLocale(), 0, 2);
    $isRtl = in_array($locale, ['ar', 'fa', 'ur', 'he'], true);
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ tr('Daily Attendance Report') }}</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            direction: {{ $isRtl ? 'rtl' : 'ltr' }};
        }

        h2 {
            text-align: center;
            margin: 0 0 6px 0;
        }

        .meta {
            text-align: center;
            margin-bottom: 10px;
            font-size: 9px;
            color: #555;
        }

        /*
         * Keep the physical table layout LTR because DomPDF does not
         * reliably reverse table columns from the HTML dir attribute.
         * Arabic columns are rendered in reverse HTML order below so
         * the first logical column appears on the far right.
         */
        table {
            width: 100%;
            border-collapse: collapse;
            direction: ltr;
        }

        th,
        td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .employee-cell {
            text-align: {{ $isRtl ? 'right' : 'left' }};
            direction: {{ $isRtl ? 'rtl' : 'ltr' }};
        }

        .rtl-text {
            direction: rtl;
        }
    </style>
</head>

<body>
    <h2>{{ $reshaper(tr('Daily Attendance Log')) }}</h2>

    <div class="meta">
        {{ $reshaper(tr('Generated')) }}: {{ now()->format('Y-m-d H:i') }}

        @if(!empty($filters['date_from']) || !empty($filters['date_to']))
            &mdash; {{ $reshaper(tr('Date Range')) }}:
            {{ $filters['date_from'] ?: '-' }}
            @if($isRtl)&larr;@else&rarr;@endif
            {{ $filters['date_to'] ?: '-' }}
        @endif
    </div>

    <table>
        <thead>
            <tr>
                @if($isRtl)
                    {{-- Reverse physical order for reliable RTL PDF rendering --}}
                    <th>{{ $reshaper(tr('Compliance')) }}</th>
                    <th>{{ $reshaper(tr('Approval')) }}</th>
                    <th>{{ $reshaper(tr('Status')) }}</th>
                    <th>{{ $reshaper(tr('Check Out')) }}</th>
                    <th>{{ $reshaper(tr('Check In')) }}</th>
                    <th>{{ $reshaper(tr('Actual Hours')) }}</th>
                    <th>{{ $reshaper(tr('Scheduled Hours')) }}</th>
                    <th>{{ $reshaper(tr('Employee Status')) }}</th>
                    <th>{{ $reshaper(tr('Employee')) }}</th>
                @else
                    <th>{{ $reshaper(tr('Employee')) }}</th>
                    <th>{{ $reshaper(tr('Employee Status')) }}</th>
                    <th>{{ $reshaper(tr('Scheduled Hours')) }}</th>
                    <th>{{ $reshaper(tr('Actual Hours')) }}</th>
                    <th>{{ $reshaper(tr('Check In')) }}</th>
                    <th>{{ $reshaper(tr('Check Out')) }}</th>
                    <th>{{ $reshaper(tr('Status')) }}</th>
                    <th>{{ $reshaper(tr('Approval')) }}</th>
                    <th>{{ $reshaper(tr('Compliance')) }}</th>
                @endif
            </tr>
        </thead>

        <tbody>
        @foreach($logs as $log)
            @php
                $emp = $log->employee;

                $employeeStatus = collect(
                    \Athka\Employees\Support\EmployeeStatus::filterOptions(true)
                )->firstWhere('value', (string) ($emp?->status ?? ''));

                $employeeStatusLabel = $employeeStatus['label']
                    ?? ($emp?->status ? tr((string) $emp->status) : '-');
            @endphp

            <tr>
                @if($isRtl)
                    <td>{{ number_format((float)($log->compliance_percentage ?? 0), 0) }}%</td>
                    <td class="rtl-text">{{ $log->pdf_approval }}</td>
                    <td class="rtl-text">{{ $log->pdf_status }}</td>
                    <td>{{ $log->check_out_hm ?? '-' }}</td>
                    <td>{{ $log->check_in_hm ?? '-' }}</td>
                    <td>{{ number_format((float)($log->actual_hours ?? 0), 2) }}</td>
                    <td>{{ number_format((float)($log->scheduled_hours ?? 0), 2) }}</td>
                    <td class="rtl-text">{{ $reshaper($employeeStatusLabel) }}</td>
                    <td class="employee-cell">{{ $log->pdf_name }}</td>
                @else
                    <td class="employee-cell">{{ $log->pdf_name }}</td>
                    <td>{{ $reshaper($employeeStatusLabel) }}</td>
                    <td>{{ number_format((float)($log->scheduled_hours ?? 0), 2) }}</td>
                    <td>{{ number_format((float)($log->actual_hours ?? 0), 2) }}</td>
                    <td>{{ $log->check_in_hm ?? '-' }}</td>
                    <td>{{ $log->check_out_hm ?? '-' }}</td>
                    <td>{{ $log->pdf_status }}</td>
                    <td>{{ $log->pdf_approval }}</td>
                    <td>{{ number_format((float)($log->compliance_percentage ?? 0), 0) }}%</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
