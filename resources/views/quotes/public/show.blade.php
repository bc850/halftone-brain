@extends('quotes.public.layout')

@php
    use App\Support\Money;

    $quoteNumber = $payload['quote_number'] ?? $quote->quote_number;
    $party = $payload['party'] ?? [];
    $totals = $payload['totals'] ?? [];
    $lines = $totals['lines'] ?? [];
    $adjustments = $totals['adjustments'] ?? [];
    $terms = $payload['terms_text'] ?? ($revision->terms_text ?? '');

    $money = static function (mixed $cents): string {
        if (! is_int($cents) && ! is_numeric($cents)) {
            return '—';
        }

        $value = (int) $cents;

        return '$'.($value < 0
            ? '-'.Money::centsToDollars(abs($value))
            : Money::centsToDollars($value));
    };

    $quantity = static function (array $line): string {
        if (! isset($line['quantity_scaled']) || ! is_numeric($line['quantity_scaled'])) {
            return '—';
        }

        $scaled = ((int) $line['quantity_scaled']) / 1000;
        $formatted = rtrim(rtrim(number_format($scaled, 3, '.', ''), '0'), '.');

        return $formatted.(! empty($line['uom']) ? ' '.$line['uom'] : '');
    };
@endphp

@section('title', 'Quote '.$quoteNumber)

@section('content')
    <article class="card">
        <p class="muted">{{ $party['selling_organization_name'] ?? config('app.name') }}</p>
        <h1>Quote {{ $quoteNumber }}</h1>
        <p class="muted">Revision {{ $payload['revision_number'] ?? $revision->revision_number }} · Status: {{ str_replace('_', ' ', $status) }}</p>

        <div class="toolbar">
            <a href="{{ route('public.quotes.pdf', ['token' => $token]) }}">Download PDF</a>
        </div>

        @if ($errors->any())
            <ul class="errors">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <div class="meta">
            <div>
                <span class="muted">Customer</span>
                <span>{{ $party['customer_company_name'] ?? '—' }}</span>
            </div>
            <div>
                <span class="muted">Contact</span>
                <span>{{ $party['contact_name'] ?? '—' }}</span>
            </div>
            <div>
                <span class="muted">Issue date</span>
                <span>{{ $payload['issue_date'] ?? '—' }}</span>
            </div>
            <div>
                <span class="muted">Expires</span>
                <span>{{ $payload['expiration_date'] ?? '—' }}</span>
            </div>
        </div>

        @if (! empty($payload['introduction']))
            <h2>Introduction</h2>
            <p>{{ $payload['introduction'] }}</p>
        @endif

        <h2>Line items</h2>
        <table class="lines">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td>
                            <strong>{{ $line['name'] ?? 'Item' }}</strong>
                            @if (! empty($line['customer_description']))
                                <div class="muted">{{ $line['customer_description'] }}</div>
                            @endif
                        </td>
                        <td class="num">{{ $quantity($line) }}</td>
                        <td class="num">{{ $money($line['net_line_total_cents'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="muted">No line items.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (is_array($adjustments) && count($adjustments) > 0)
            <h2>Adjustments</h2>
            <table class="lines">
                <tbody>
                    @foreach ($adjustments as $adjustment)
                        <tr>
                            <td>{{ $adjustment['description'] ?? 'Adjustment' }}</td>
                            <td class="num">{{ $money($adjustment['amount_cents'] ?? null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="totals">
            <div>
                <span class="muted">Subtotal</span>
                <span>{{ $money($totals['final_pretax_amount_cents'] ?? $totals['net_line_subtotal_cents'] ?? null) }}</span>
            </div>
            <div>
                <span class="muted">Tax</span>
                <span>{{ $money($totals['tax_cents'] ?? null) }}</span>
            </div>
            <div class="grand">
                <span>Total</span>
                <span>{{ $money($totals['customer_grand_total_cents'] ?? null) }}</span>
            </div>
        </div>

        @if (! empty($payload['customer_notes']))
            <h2>Notes</h2>
            <p>{{ $payload['customer_notes'] }}</p>
        @endif

        <h2>Terms</h2>
        <div class="terms">{{ $terms }}</div>

        @if ($canRespond)
            <div class="actions">
                <form method="POST" action="{{ route('public.quotes.accept', ['token' => $token]) }}">
                    @csrf
                    <h2 style="margin-top:0;border:0;padding:0;">Accept quote</h2>
                    <label>
                        Type your full name
                        <input type="text" name="typed_name" value="{{ old('typed_name') }}" required maxlength="255" autocomplete="name">
                    </label>
                    <label class="check">
                        <input type="checkbox" name="terms_accepted" value="1" @checked(old('terms_accepted'))>
                        <span>I accept the terms above.</span>
                    </label>
                    <button type="submit">Accept quote</button>
                </form>

                <form method="POST" action="{{ route('public.quotes.reject', ['token' => $token]) }}">
                    @csrf
                    <h2 style="margin-top:0;border:0;padding:0;">Decline quote</h2>
                    <label>
                        Name (optional)
                        <input type="text" name="typed_name" value="{{ old('typed_name') }}" maxlength="255" autocomplete="name">
                    </label>
                    <label>
                        Reason (optional)
                        <textarea name="rejection_reason" rows="3" maxlength="2000">{{ old('rejection_reason') }}</textarea>
                    </label>
                    <button type="submit" class="secondary">Decline quote</button>
                </form>
            </div>
        @else
            <div class="banner muted">
                This quote is no longer open for a response.
            </div>
        @endif
    </article>
@endsection
