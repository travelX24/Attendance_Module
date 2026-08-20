@php
    $isRtl = $isRtl ?? in_array(substr(app()->getLocale(), 0, 2), ['ar', 'fa', 'ur', 'he'], true);
    $dir = $isRtl ? 'rtl' : 'ltr';
    $startAlign = $isRtl ? 'right' : 'left';
    $currencyLabel = $currency['label'] ?? ($isRtl ? "\u{0631}.\u{064A}" : 'YER');
    $columnCount = count($headers ?? []);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $reshaper(tr('Daily Penalties Report')) }}</title>
    <style>
        body {
            direction: {{ $dir }};
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #2B2B2B;
            text-align: {{ $startAlign }};
        }

        table {
            direction: {{ $dir }};
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }

        th,
        td {
            border: 1px solid #E8DED8;
            padding: 7px 5px;
            text-align: center;
            vertical-align: middle;
            unicode-bidi: plaintext;
        }

        th {
            background-color: #FDFBF7;
            color: #581845;
            font-weight: bold;
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .header h1 {
            color: #903749;
            margin: 0 0 10px;
        }

        .stats {
            margin-bottom: 18px;
            text-align: center;
        }

        .stats strong {
            color: #903749;
        }

        .text-start {
            text-align: {{ $startAlign }};
        }

        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $reshaper(tr('Daily Penalties Report')) }}</h1>
        <p>{{ $reshaper(tr('Period')) }}: {{ $date_from }} - {{ $date_to }}</p>
    </div>

    <div class="stats">
        <strong>{{ $reshaper(tr('Total Calculated')) }}:</strong>
        {{ number_format((float) ($stats['total_calculated'] ?? 0), 2) }} {{ $reshaper($currencyLabel) }}
        |
        <strong>{{ $reshaper(tr('Total Waived')) }}:</strong>
        {{ number_format((float) ($stats['total_exempted'] ?? 0), 2) }} {{ $reshaper($currencyLabel) }}
        |
        <strong>{{ $reshaper(tr('Net Total')) }}:</strong>
        {{ number_format((float) ($stats['total_net'] ?? 0), 2) }} {{ $reshaper($currencyLabel) }}
    </div>

    <table>
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $reshaper($header) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $index => $cell)
                        <td class="{{ $index < 2 ? 'text-start' : '' }}">{{ $reshaper($cell) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max($columnCount, 1) }}">{{ $reshaper(tr('No data available')) }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ $reshaper(tr('Generated at')) }}: {{ now()->format('Y-m-d H:i') }}
    </div>
</body>
</html>
