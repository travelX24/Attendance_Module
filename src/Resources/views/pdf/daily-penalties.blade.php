<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(substr(app()->getLocale(), 0, 2), ['ar', 'fa', 'ur', 'he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $reshaper(tr('Daily Penalties Report')) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #2B2B2B; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #E8DED8; padding: 8px; text-align: center; vertical-align: middle; }
        th { background-color: #FDFBF7; color: #581845; font-weight: bold; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #903749; margin: 0 0 10px; }
        .stats { margin-bottom: 20px; text-align: center; }
        .stats strong { color: #903749; }
        .employee { text-align: {{ in_array(substr(app()->getLocale(), 0, 2), ['ar', 'fa', 'ur', 'he']) ? 'right' : 'left' }}; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 8px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $reshaper(tr('Daily Penalties Report')) }}</h1>
        <p>{{ $reshaper(tr('Period')) }}: {{ $date_from }} - {{ $date_to }}</p>
    </div>

    <div class="stats">
        <strong>{{ $reshaper(tr('Total Calculated')) }}:</strong> {{ number_format((float) ($stats['total_calculated'] ?? 0), 2) }} SAR |
        <strong>{{ $reshaper(tr('Total Waived')) }}:</strong> {{ number_format((float) ($stats['total_exempted'] ?? 0), 2) }} SAR |
        <strong>{{ $reshaper(tr('Net Total')) }}:</strong> {{ number_format((float) ($stats['total_net'] ?? 0), 2) }} SAR
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ $reshaper(tr('Employee')) }}</th>
                <th>{{ $reshaper(tr('No')) }}</th>
                <th>{{ $reshaper(tr('Date')) }}</th>
                <th>{{ $reshaper(tr('Violation')) }}</th>
                <th>{{ $reshaper(tr('Min')) }}</th>
                <th>{{ $reshaper(tr('Amount')) }}</th>
                <th>{{ $reshaper(tr('Net')) }}</th>
                <th>{{ $reshaper(tr('Status')) }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($penalties as $p)
                <tr>
                    <td class="employee">{{ $p->pdf_employee_name }}</td>
                    <td>{{ $p->employee?->employee_no ?? '-' }}</td>
                    <td>{{ $p->attendance_date instanceof \DateTimeInterface ? $p->attendance_date->format('Y-m-d') : substr((string) $p->attendance_date, 0, 10) }}</td>
                    <td>{{ $p->pdf_violation }}</td>
                    <td>{{ (int) $p->violation_minutes }}</td>
                    <td>{{ number_format((float) $p->calculated_amount, 2) }}</td>
                    <td>{{ number_format((float) $p->net_amount, 2) }}</td>
                    <td>{{ $p->pdf_status }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">{{ $reshaper(tr('No data available')) }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ $reshaper(tr('Generated at')) }}: {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>