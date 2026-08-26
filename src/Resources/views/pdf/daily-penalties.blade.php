@php
    $isRtl = $isRtl ?? in_array(substr(app()->getLocale(), 0, 2), ['ar', 'fa', 'ur', 'he'], true);
    $dir = $isRtl ? 'rtl' : 'ltr';
    $textAlign = $isRtl ? 'right' : 'left';
    $currencyLabel = $currency['code'] ?? ($currency['label'] ?? 'YER');
    
    // In DomPDF, table columns are rendered Left-to-Right.
    // To match the RTL UI layout (Employee on the right, Status on the left), reverse columns for RTL.
    $displayHeaders = $isRtl ? array_reverse($headers ?? []) : ($headers ?? []);
    $displayRows = $isRtl ? array_map(fn($row) => array_reverse($row), $rows ?? []) : ($rows ?? []);
    $columnCount = count($displayHeaders);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $reshaper(tr('Daily Penalties Report')) }}</title>
    <style>
        @page {
            margin: 15px;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #fff;
            color: #1e293b;
            font-size: 8px;
            line-height: 1.2;
            direction: {{ $dir }};
            text-align: {{ $textAlign }};
        }
        .header {
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .system-logo {
            width: 28px;
            height: 28px;
            background: #903749;
            border-radius: 6px;
            text-align: center;
            line-height: 28px;
            color: white;
            font-size: 14px;
            font-weight: bold;
            display: inline-block;
        }
        .system-name {
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            margin-right: 6px;
            margin-left: 6px;
            display: inline-block;
            vertical-align: middle;
        }
        .company-name {
            font-size: 13px;
            font-weight: 700;
            color: #903749;
        }
        .company-details {
            font-size: 8px;
            color: #64748b;
            margin-top: 2px;
        }
        .report-info {
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
            text-align: {{ $textAlign }};
        }
        .report-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px 0;
        }
        .meta-container {
            font-size: 8px;
            color: #64748b;
        }
        .meta-item {
            display: inline-block;
            margin-right: 12px;
            margin-left: 12px;
        }
        .stats-cards {
            margin: 10px 0;
            width: 100%;
            border-collapse: collapse;
        }
        .stat-card {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 6px;
            padding: 6px 10px;
            text-align: center;
        }
        .stat-card strong {
            color: #903749;
            font-size: 8px;
            display: block;
            margin-bottom: 2px;
        }
        .stat-card span {
            font-size: 9px;
            font-weight: bold;
            color: #1e293b;
        }
        .main-content {
            padding-top: 10px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .data-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 8px;
            font-weight: 800;
            padding: 6px 4px;
            text-align: center;
            border: 1px solid #cbd5e1;
        }
        .data-table td {
            padding: 5px 4px;
            font-size: 7.5px;
            color: #334155;
            border: 1px solid #e2e8f0;
            text-align: center;
            vertical-align: middle;
            word-wrap: break-word;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .text-start {
            text-align: {{ $textAlign }} !important;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            padding: 6px 0;
            border-top: 1px solid #f1f5f9;
            font-size: 7px;
            color: #94a3b8;
        }
        .page-number:after {
            content: " - " counter(page);
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table" style="width: 100%; border-collapse: collapse; border: 0;">
            <tr>
                @if($isRtl)
                    {{-- Arabic Header: Company Info on Right, ATHKA HR System Title on Left --}}
                    <td style="width: 65%; text-align: right; vertical-align: middle; border: 0;">
                        <table style="border-collapse: collapse; border: 0;">
                            <tr>
                                @if(!empty($companyLogo))
                                    <td style="vertical-align: middle; border: 0; padding-left: 10px;">
                                        <img src="{{ $companyLogo }}" alt="Logo" style="max-height: 46px; max-width: 120px; object-fit: contain;" />
                                    </td>
                                @endif
                                <td style="vertical-align: middle; border: 0; text-align: right;">
                                    <div class="company-name" style="font-size: 13px; font-weight: 700; color: #903749;">{{ $reshaper($company->legal_name_ar ?? $company->legal_name_en ?? $company->name ?? tr('Athka Company')) }}</div>
                                    <div class="company-details" style="font-size: 8px; color: #64748b; margin-top: 2px;">
                                        @if(!empty($company?->address_line))
                                            {{ $reshaper($company->address_line) }}<br>
                                        @endif
                                        {{ $company->official_email ?? '' }} {{ (!empty($company->official_email) && !empty($company->phone_1)) ? '|' : '' }} {{ $company->phone_1 ?? '' }}
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td style="width: 35%; text-align: left; vertical-align: middle; border: 0; padding: 0;">
                        <div style="font-size: 14px; font-weight: 800; color: #903749; letter-spacing: 0.5px;">ATHKA HR</div>
                        <div style="font-size: 7.5px; color: #64748b; margin-top: 1px;">{{ $reshaper(tr('Athka HR Management System')) }}</div>
                    </td>
                @else
                    {{-- English Header: Company Info on Left, ATHKA HR System Title on Right --}}
                    <td style="width: 65%; text-align: left; vertical-align: middle; border: 0;">
                        <table style="border-collapse: collapse; border: 0;">
                            <tr>
                                @if(!empty($companyLogo))
                                    <td style="vertical-align: middle; border: 0; padding-right: 10px;">
                                        <img src="{{ $companyLogo }}" alt="Logo" style="max-height: 46px; max-width: 120px; object-fit: contain;" />
                                    </td>
                                @endif
                                <td style="vertical-align: middle; border: 0; text-align: left;">
                                    <div class="company-name" style="font-size: 13px; font-weight: 700; color: #903749;">{{ $company->legal_name_en ?? $company->legal_name_ar ?? $company->name ?? 'Athka Company' }}</div>
                                    <div class="company-details" style="font-size: 8px; color: #64748b; margin-top: 2px;">
                                        @if(!empty($company?->address_line))
                                            {{ $company->address_line }}<br>
                                        @endif
                                        {{ $company->official_email ?? '' }} {{ (!empty($company->official_email) && !empty($company->phone_1)) ? '|' : '' }} {{ $company->phone_1 ?? '' }}
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td style="width: 35%; text-align: right; vertical-align: middle; border: 0; padding: 0;">
                        <div style="font-size: 14px; font-weight: 800; color: #903749; letter-spacing: 0.5px;">ATHKA HR</div>
                        <div style="font-size: 7.5px; color: #64748b; margin-top: 1px;">Athka HR Management System</div>
                    </td>
                @endif
            </tr>
        </table>
    </div>

    <div class="report-info" style="padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
        <h1 class="report-title" style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0; text-align: center;">{{ $reshaper(tr('Daily Penalties Report')) }}</h1>
        <table style="width: 100%; border-collapse: collapse; border: 0; margin-top: 4px;">
            <tr>
                @php
                    $metaItems = [
                        [
                            'label' => $reshaper(tr('Generated at')),
                            'value' => now()->format('Y/m/d H:i'),
                        ],
                        [
                            'label' => $reshaper(tr('Generated By')),
                            'value' => $reshaper(auth()->user()->name ?? 'Admin'),
                        ],
                        [
                            'label' => $reshaper(tr('Record Count')),
                            'value' => count($rows ?? []),
                        ],
                        [
                            'label' => $reshaper(tr('Period')),
                            'value' => "{$date_from} - {$date_to}",
                        ],
                    ];
                    if ($isRtl) {
                        $metaItems = array_reverse($metaItems);
                    }
                @endphp
                @foreach($metaItems as $item)
                    <td style="text-align: center; vertical-align: top; border: 0; padding: 0 6px;">
                        <div style="font-size: 8px; font-weight: 700; color: #334155; margin-bottom: 2px;">{{ $item['label'] }}:</div>
                        <div style="font-size: 8px; font-weight: 500; color: #64748b;">{{ $item['value'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>

    <table class="stats-cards">
        <tr>
            @php
                $statsCells = [
                    [
                        'label' => tr('Total Calculated'),
                        'value' => number_format((float) ($stats['total_calculated'] ?? 0), 2) . ' ' . $currencyLabel,
                    ],
                    [
                        'label' => tr('Total Waived'),
                        'value' => number_format((float) ($stats['total_exempted'] ?? 0), 2) . ' ' . $currencyLabel,
                    ],
                    [
                        'label' => tr('Net Total'),
                        'value' => number_format((float) ($stats['total_net'] ?? 0), 2) . ' ' . $currencyLabel,
                    ],
                ];
                if ($isRtl) $statsCells = array_reverse($statsCells);
            @endphp
            @foreach($statsCells as $sc)
                <td style="width: 33.33%; padding: 0 4px;">
                    <div class="stat-card">
                        <strong>{{ $reshaper($sc['label']) }}</strong>
                        <span dir="ltr">{{ $sc['value'] }}</span>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="main-content">
        <table class="data-table">
            <thead>
                <tr>
                    @foreach($displayHeaders as $header)
                        <th>{{ $reshaper($header) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($displayRows as $row)
                    <tr>
                        @foreach($row as $index => $cell)
                            @php
                                // When RTL: Employee is at index (count - 1), Dept/Job at index (count - 2)
                                // When LTR: Employee is at index 0, Dept/Job at index 1
                                $isTextCol = $isRtl ? ($index >= $columnCount - 2) : ($index <= 1);
                            @endphp
                            <td class="{{ $isTextCol ? 'text-start' : '' }}">
                                {{ $reshaper($cell) }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max($columnCount, 1) }}">{{ $reshaper(tr('No data available')) }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="footer">
        <table style="width: 100%; border: 0;">
            <tr>
                @if($isRtl)
                    <td style="width: 33%; text-align: right; border: 0;"><span class="page-number"></span></td>
                    <td style="width: 33%; text-align: center; border: 0;">&copy; {{ date('Y') }}</td>
                    <td style="width: 33%; text-align: left; border: 0;">{{ $reshaper(tr('Athka HR Management System')) }}</td>
                @else
                    <td style="width: 33%; text-align: left; border: 0;">{{ tr('Athka HR Management System') }}</td>
                    <td style="width: 33%; text-align: center; border: 0;">&copy; {{ date('Y') }}</td>
                    <td style="width: 33%; text-align: right; border: 0;"><span class="page-number"></span></td>
                @endif
            </tr>
        </table>
    </div>
</body>
</html>
