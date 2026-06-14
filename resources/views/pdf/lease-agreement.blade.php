<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 120px 64px 90px 64px; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Serif', Georgia, serif;
            color: #0A1512;
            font-size: 11px;
            line-height: 1.55;
            margin: 0;
        }
        .header {
            position: fixed;
            top: -90px; left: 0; right: 0;
            border-bottom: 1.5px solid #0A1512;
            padding-bottom: 8px;
        }
        .header .wordmark {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: .04em;
        }
        .header .sub {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #4a5440;
            margin-top: 2px;
        }
        .footer {
            position: fixed;
            bottom: -60px; left: 0; right: 0;
            border-top: 1px solid #a89874;
            padding-top: 6px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 7px;
            letter-spacing: .08em;
            color: #4a5440;
            text-transform: uppercase;
        }
        h1 {
            font-size: 17px;
            letter-spacing: .02em;
            margin: 0 0 4px 0;
        }
        .eyebrow {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #C84C21;
            margin-bottom: 18px;
        }
        h2 {
            font-size: 11px;
            font-family: 'DejaVu Sans Mono', monospace;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #4a5440;
            border-bottom: 1px solid #e5ddd0;
            padding-bottom: 4px;
            margin: 22px 0 10px 0;
        }
        table.terms { width: 100%; border-collapse: collapse; }
        table.terms td { padding: 4px 0; vertical-align: top; }
        table.terms td.label {
            width: 38%;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #4a5440;
        }
        table.terms td.value { font-weight: bold; }
        .consent {
            background: #F8F4EB;
            border: 1px solid #e5ddd0;
            padding: 12px 14px;
            margin-top: 6px;
        }
        .sig-block {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .sig-block td {
            width: 50%;
            border: 1px solid #e5ddd0;
            padding: 12px 14px;
            vertical-align: top;
        }
        .sig-name {
            font-size: 15px;
            font-family: 'DejaVu Serif', Georgia, serif;
            font-style: italic;
            border-bottom: 1px solid #0A1512;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }
        .sig-meta {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            line-height: 1.6;
            color: #4a5440;
        }
        .sig-role {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #C84C21;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="wordmark">American Headhunter</div>
        <div class="sub">Executed Hunting Lease Agreement</div>
    </div>

    <div class="footer">
        Electronically executed under the ESIGN Act (15 U.S.C. &sect; 7001) &middot; Document ID {{ $documentRef }}
    </div>

    <div class="eyebrow">Field Record &middot; Lease {{ $leaseRef }}</div>
    <h1>Hunting Lease Agreement</h1>

    <h2>Property &amp; Term</h2>
    <table class="terms">
        <tr><td class="label">Property</td><td class="value">{{ $property['title'] }}</td></tr>
        @if($property['location'])
        <tr><td class="label">Location</td><td class="value">{{ $property['location'] }}</td></tr>
        @endif
        @if($property['acres'])
        <tr><td class="label">Acreage</td><td class="value">{{ $property['acres'] }} acres</td></tr>
        @endif
        <tr><td class="label">Lease Term</td><td class="value">{{ $startDate }} &mdash; {{ $endDate }}</td></tr>
        <tr><td class="label">Total Consideration</td><td class="value">${{ $totalPrice }}</td></tr>
    </table>

    <h2>Agreement &amp; Electronic Consent</h2>
    <div class="consent">
        This Hunting Lease Agreement is entered into between the Lessor and the Lessee
        named below, granting the Lessee the right to hunt the property identified above
        for the lease term stated, in exchange for the total consideration of
        ${{ $totalPrice }}. Each party, by affixing their electronic signature below,
        agrees to the terms of this hunting lease and acknowledges that they have
        read and understood it.
        <br><br>
        Each signer affirms: &ldquo;I agree to the terms of this hunting lease agreement
        for the period {{ $startDate }} through {{ $endDate }}, for the total amount of
        ${{ $totalPrice }}. I understand this constitutes a legally binding electronic
        signature under the ESIGN Act.&rdquo; Each signature is recorded with the
        signer&rsquo;s account ID, a timestamp, and the originating IP address.
    </div>

    <h2>Signatures</h2>
    <table class="sig-block">
        <tr>
            @foreach($signers as $signer)
            <td>
                <div class="sig-role">{{ $signer['role'] }}</div>
                <div class="sig-name">{{ $signer['name'] }}</div>
                <div class="sig-meta">
                    Signed: {{ $signer['signed_at'] ?? '—' }}<br>
                    IP: {{ $signer['ip'] ?? '—' }}<br>
                    Account: {{ $signer['user_id'] }}
                </div>
            </td>
            @endforeach
        </tr>
    </table>
</body>
</html>
