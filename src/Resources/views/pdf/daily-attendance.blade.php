!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(substr(app()->getLocale(),0,2), ['ar','fa','ur','he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ tr('Daily Attendance Report') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h2 { text-align: center; margin: 0 0 6px 0; }
        .meta { text-align: center; margin-bottom: 10px; font-size: 9px; color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 6px; text-align: center; vertical-align: middle; }
        th { background: #f3f4f6; font-weight: 700; }
        .left { text-align: left; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h2>{{ $reshaper(tr('Daily Attendance Log')) }}</h2>
    <div class="meta">
        {{ $reshaper(tr('Generated')) }}: {{ now()->format('Y-m-d H:i') }}
        @if(!empty($filters['date_from']) || !empty($filters['date_to']))
            — {{ $reshaper(tr('Date Range')) }}: {{ $filters['date_from'] ?: '-' }} → {{ $filters['date_to'] ?: '-' }}
        @endif
    </div>

    <table>
        <thead>
        <tr>
            <th>{{ $reshaper(tr('Employee #')) }}</th>
            <th class="left">{{ $reshaper(tr('Employee')) }}</th>
            <th>{{ $reshaper(tr('Date')) }}</th>
            <th class="left">{{ $reshaper(tr('Schedule')) }}</th>
            <th>{{ $reshaper(tr('Scheduled')) }}</th>
            <th>{{ $reshaper(tr('Actual')) }}</th>
            <th>{{ $reshaper(tr('Sch In')) }}</th>
            <th>{{ $reshaper(tr('Sch Out')) }}</th>
            <th>{{ $reshaper(tr('In')) }}</th>
            <th>{{ $reshaper(tr('Out')) }}</th>
            <th>{{ $reshaper(tr('Status')) }}</th>
            <th>{{ $reshaper(tr('Approval')) }}</th>
            <th>{{ $reshaper(tr('Compliance')) }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($logs as $log)
            @php
                $emp = $log->employee;
                $no = $emp?->employee_no ?? '-';
            @endphp
            <tr>
                <td>{{ $no }}</td>
                <td class="left">{{ $log->pdf_name }}</td>
                <td>{{ $log->attendance_date instanceof \DateTimeInterface ? $log->attendance_date->format('Y-m-d') : substr((string)$log->attendance_date,0,10) }}</td>
                <td class="left">{{ $log->pdf_schedule }}</td>
                <td>{{ number_format((float)($log->scheduled_hours ?? 0), 2) }}</td>
                <td>{{ number_format((float)($log->actual_hours ?? 0), 2) }}</td>
                <td>{{ $log->scheduled_check_in_hm ?? '-' }}</td>
                <td>{{ $log->scheduled_check_out_hm ?? '-' }}</td>
                <td>{{ $log->check_in_hm ?? '-' }}</td>
                <td>{{ $log->check_out_hm ?? '-' }}</td>
                <td>{{ $log->pdf_status }}</td>
                <td>{{ $log->pdf_approval }}</td>
                <td>{{ number_format((float)($log->compliance_percentage ?? 0), 0) }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
