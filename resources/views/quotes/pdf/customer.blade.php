<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document['quote_number'] ?? 'Quote' }}</title>
    <style>
        @page { margin: 48px 42px; }
        body {
            font-family: '{{ $fontFamily }}', DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            line-height: 1.45;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 4px;
            font-weight: 700;
        }
        h2 {
            font-size: 13px;
            margin: 18px 0 6px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
        }
        .muted { color: #555; }
        .header {
            width: 100%;
            margin-bottom: 18px;
        }
        .header td { vertical-align: top; }
        .brand { font-size: 16px; font-weight: 700; }
        .meta { text-align: right; }
        .parties {
            width: 100%;
            margin-bottom: 14px;
        }
        .parties td {
            width: 50%;
            vertical-align: top;
            padding-right: 12px;
        }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.lines th,
        table.lines td {
            border-bottom: 1px solid #ddd;
            padding: 6px 4px;
            text-align: left;
            vertical-align: top;
        }
        table.lines th {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #444;
        }
        table.lines .num { text-align: right; white-space: nowrap; }
        table.totals {
            width: 280px;
            margin-left: auto;
            margin-top: 12px;
            border-collapse: collapse;
        }
        table.totals td {
            padding: 4px 0;
        }
        table.totals .label { color: #444; }
        table.totals .amount { text-align: right; white-space: nowrap; }
        table.totals .grand {
            font-weight: 700;
            font-size: 13px;
            border-top: 1px solid #222;
            padding-top: 6px;
        }
        .terms {
            margin-top: 22px;
            page-break-inside: avoid;
            white-space: pre-wrap;
        }
        .section-note {
            margin: 8px 0;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
@php
    $party = $document['party'] ?? [];
    $totals = $document['totals'] ?? [];
    $lines = $totals['lines'] ?? [];
    $adjustments = $totals['adjustments'] ?? [];
    $formatMoney = static function (?int $cents): string {
        if ($cents === null) {
            return '—';
        }
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.'$'.number_format($abs / 100, 2);
    };
    $formatQty = static function (int $scaled): string {
        return rtrim(rtrim(number_format($scaled / 10000, 4, '.', ''), '0'), '.') ?: '0';
    };
@endphp

<table class="header">
    <tr>
        <td>
            <div class="brand">{{ $party['selling_organization_name'] ?? '' }}</div>
            <div class="muted">Customer quote</div>
        </td>
        <td class="meta">
            <h1>{{ $document['quote_number'] ?? '' }}</h1>
            <div>Revision {{ $document['revision_number'] ?? '' }}</div>
            <div class="muted">Issued {{ $document['issue_date'] ?? '—' }}</div>
            <div class="muted">Expires {{ $document['expiration_date'] ?? '—' }}</div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <strong>Bill to</strong><br>
            {{ $party['customer_company_name'] ?? '' }}<br>
            @if (! empty($party['contact_name']))
                {{ $party['contact_name'] }}<br>
            @endif
            @if (! empty($party['contact_email']))
                {{ $party['contact_email'] }}<br>
            @endif
            @if (! empty($party['billing_address_display']))
                <div class="muted">{{ $party['billing_address_display'] }}</div>
            @endif
        </td>
        <td>
            <strong>Service / delivery</strong><br>
            @if (! empty($party['service_address_display']))
                <div>{{ $party['service_address_display'] }}</div>
            @else
                <div class="muted">Same as billing</div>
            @endif
            @if (! empty($party['salesperson_name']))
                <div style="margin-top:8px"><strong>Salesperson</strong><br>{{ $party['salesperson_name'] }}</div>
            @endif
            @if (! empty($party['preparer_name']))
                <div style="margin-top:8px"><strong>Prepared by</strong><br>{{ $party['preparer_name'] }}</div>
            @endif
            @if (! empty($party['customer_po_reference']))
                <div style="margin-top:8px"><strong>Customer PO</strong><br>{{ $party['customer_po_reference'] }}</div>
            @endif
        </td>
    </tr>
</table>

@if (! empty($document['introduction']))
    <h2>Introduction</h2>
    <div class="section-note">{{ $document['introduction'] }}</div>
@endif

@if (! empty($document['customer_notes']))
    <h2>Notes</h2>
    <div class="section-note">{{ $document['customer_notes'] }}</div>
@endif

<h2>Line items</h2>
<table class="lines">
    <thead>
        <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>UOM</th>
            <th class="num">Unit</th>
            <th class="num">Discount</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td>
                <strong>{{ $line['name'] ?? '' }}</strong>
                @if (! empty($line['sku']))
                    <div class="muted">SKU {{ $line['sku'] }}</div>
                @endif
                @if (! empty($line['customer_description']))
                    <div class="muted">{{ $line['customer_description'] }}</div>
                @endif
            </td>
            <td>{{ $formatQty((int) ($line['quantity_scaled'] ?? 0)) }}</td>
            <td>{{ $line['uom'] ?? '' }}</td>
            <td class="num">{{ $formatMoney(isset($line['unit_price_cents']) ? (int) $line['unit_price_cents'] : null) }}</td>
            <td class="num">{{ $formatMoney((int) ($line['line_discount_amount_cents'] ?? 0)) }}</td>
            <td class="num">{{ $formatMoney((int) ($line['net_line_total_cents'] ?? 0)) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@if ($adjustments !== [])
    <h2>Adjustments</h2>
    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th>Type</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($adjustments as $adjustment)
            <tr>
                <td>{{ $adjustment['description'] ?? '' }}</td>
                <td>{{ $adjustment['adjustment_type'] ?? '' }}</td>
                <td class="num">{{ $formatMoney((int) ($adjustment['amount_cents'] ?? 0)) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<table class="totals">
    <tr>
        <td class="label">Subtotal</td>
        <td class="amount">{{ $formatMoney((int) ($totals['net_line_subtotal_cents'] ?? 0)) }}</td>
    </tr>
    @if (((int) ($totals['quote_discount_total_cents'] ?? 0)) > 0)
        <tr>
            <td class="label">Quote discount</td>
            <td class="amount">-{{ $formatMoney((int) $totals['quote_discount_total_cents']) }}</td>
        </tr>
    @endif
    @if (((int) ($totals['positive_adjustment_total_cents'] ?? 0)) > 0)
        <tr>
            <td class="label">Fees &amp; charges</td>
            <td class="amount">{{ $formatMoney((int) $totals['positive_adjustment_total_cents']) }}</td>
        </tr>
    @endif
    <tr>
        <td class="label">Pre-tax total</td>
        <td class="amount">{{ $formatMoney((int) ($totals['final_pretax_amount_cents'] ?? 0)) }}</td>
    </tr>
    <tr>
        <td class="label">Tax</td>
        <td class="amount">{{ $formatMoney(isset($totals['tax_cents']) ? (int) $totals['tax_cents'] : null) }}</td>
    </tr>
    <tr class="grand">
        <td class="label">Grand total</td>
        <td class="amount">{{ $formatMoney((int) ($totals['customer_grand_total_cents'] ?? $totals['final_pretax_amount_cents'] ?? 0)) }}</td>
    </tr>
    @if (! empty($document['requested_deposit_cents']))
        <tr>
            <td class="label">Requested deposit</td>
            <td class="amount">{{ $formatMoney((int) $document['requested_deposit_cents']) }}</td>
        </tr>
    @endif
</table>

@if (! empty($document['terms_text']))
    <h2>Terms</h2>
    <div class="terms">{{ $document['terms_text'] }}</div>
@endif
</body>
</html>
