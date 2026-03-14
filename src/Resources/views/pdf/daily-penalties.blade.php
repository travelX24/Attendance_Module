!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ tr('Daily Penalties Report') }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; direction: rtl; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        th { bg-color: #f4f4f4; font-weight: bold; }
        .header { text-align: center; margin-bottom: 30px; }
        .stats { margin-bottom: 20px; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ tr('Daily Penalties Report') }}</h1>
        <p>{{ tr('Period') }}: {{ $date_from }} - {{ $date_to }}</p>
    </div>

    <div class="stats">
        <strong>{{ tr('Total Calculated') }}:</strong> {{ number_format($stats['total_calculated'], 2) }} SAR |
        <strong>{{ tr('Total Waived') }}:</strong> {{ number_format($stats['total_exempted'], 2) }} SAR |
        <strong>{{ tr('Net Total') }}:</strong> {{ number_format($stats['total_net'], 2) }} SAR
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ tr('Employee') }}</th>
                <th>{{ tr('No') }}</th>
                <th>{{ tr('Date') }}</th>
                <th>{{ tr('Violation') }}</th>
                <th>{{ tr('Min') }}</th>
                <th>{{ tr('Amount') }}</th>
                <th>{{ tr('Net') }}</th>
                <th>{{ tr('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($penalties as $p)
                <tr>
                    <td>{{ $p->employee->name_ar ?? $p->employee->name_en }}</td>
                    <td>{{ $p->employee->employee_no }}</td>
                    <td>{{ $p->attendance_date->format('Y-m-d') }}</td>
                    <td>{{ $p->violation_type }}</td>
                    <td>{{ $p->violation_minutes }}</td>
                    <td>{{ number_format($p->calculated_amount, 2) }}</td>
                    <td>{{ number_format($p->net_amount, 2) }}</td>
                    <td>{{ $p->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        {{ tr('Generated at') }}: {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
