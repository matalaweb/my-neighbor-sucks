<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 54px 40px 54px 40px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111827; line-height: 1.35; }
    h1 { font-size: 15pt; margin: 0 0 4px 0; }
    h2 { font-size: 11pt; margin: 16px 0 6px 0; padding-bottom: 2px; border-bottom: 1px solid #d1d5db; }
    h3 { font-size: 9.5pt; margin: 10px 0 4px 0; }
    p { margin: 3px 0; }
    .muted { color: #4b5563; }
    .small { font-size: 7.5pt; }
    .notice { border: 1px solid #f59e0b; background: #fffbeb; padding: 6px 8px; margin: 8px 0; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 4px 0 8px 0; }
    th, td { border: 1px solid #d1d5db; padding: 3px 4px; vertical-align: top; text-align: left; word-wrap: break-word; overflow-wrap: break-word; }
    th { background: #f3f4f6; font-weight: bold; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .keep-with-next { page-break-after: avoid; }
    table.kv th { width: 30%; }
    .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 7pt; }
    .chart { margin: 6px 0 10px 0; page-break-inside: avoid; }
    .chart img { width: 100%; }
    .note { border-left: 3px solid #9ca3af; padding: 2px 6px; margin: 4px 0; page-break-inside: avoid; white-space: pre-wrap; }
    footer { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 7pt; color: #6b7280; }
    .pagenum:before { content: counter(page); }
</style>
</head>
<body>
<footer>Noise Monitor · export {{ $export->uuid }} · generated {{ $generatedUtc }} · page <span class="pagenum"></span> · DIY monitoring system — not a certified instrument</footer>

<h1>{{ $title }}</h1>
<p class="muted">{{ $property->name }} · timezone {{ $timezone }}</p>

<div class="notice">{{ $disclaimer }}</div>

<h2>Scope</h2>
<table class="kv">
    <tr><th>Selection</th><td>{{ $scopeType === 'range' ? 'Date range' : 'Selected events ('.count($events).')' }}</td></tr>
    <tr><th>Requested range (local)</th><td>{{ $range['local_start'] }} → {{ $range['local_end'] }}</td></tr>
    <tr><th>Requested range (UTC)</th><td class="mono">{{ $range['utc_start'] }} → {{ $range['utc_end'] }}</td></tr>
    <tr><th>Selection frozen at (UTC)</th><td class="mono">{{ $frozenUtc }}</td></tr>
    <tr><th>Generated</th><td>{{ $generatedLocal }} <span class="mono">({{ $generatedUtc }})</span></td></tr>
    @if ($property->address)
        <tr><th>Location</th><td>{{ $property->address }}</td></tr>
    @endif
</table>

<h2>Actual coverage</h2>
@if ($scopeType === 'range')
    <table>
        <tr><th style="width:40%">Stream</th><th>Measured</th><th>Equivalent level</th><th>Maximum LAFmax</th></tr>
        @forelse ($coverage as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td>{{ $row['metric'] }} over {{ number_format($row['valid_ms'] / 60000, 1) }} measured minutes of {{ number_format($row['expected_ms'] / 60000, 0) }}
                    @if ($row['excluded_ms'] > 0)<br><span class="small muted">{{ number_format($row['excluded_ms'] / 60000, 1) }} min excluded by quality policy</span>@endif
                    @if ($row['ambiguous_ms'] > 0)<br><span class="small muted">{{ number_format($row['ambiguous_ms'] / 60000, 1) }} min ambiguous (overlapping boots)</span>@endif
                </td>
                <td>{{ $row['leq'] === null ? 'No valid data' : number_format($row['leq'], 1).' '.$row['unit'] }}</td>
                <td>{{ $row['lafmax_max'] === null ? '—' : number_format($row['lafmax_max'], 1).' dBA' }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No measurement streams in this range.</td></tr>
        @endforelse
    </table>
    <p class="small muted">Missing time is unknown, not silence; it contributes neither energy nor duration. Equivalent levels are energy averages, never arithmetic means of decibels.</p>
@else
    <p>Coverage is reported per event from its one-second measurement snapshot (see the event table and limitations below).</p>
@endif

<h2>Equipment and placement</h2>
@foreach ($equipment as $item)
    <table class="kv">
        <tr><th>Stream</th><td>{{ $item['label'] }}</td></tr>
        <tr><th>Device</th><td>{{ $item['device'] }}</td></tr>
        <tr><th>Microphone</th><td>{{ $item['microphone'] ?: '—' }} @if($item['interface']) · {{ $item['interface'] }} @endif · {{ $item['sample_rate'] }} Hz · gain {{ $item['gain'] }}</td></tr>
        <tr><th>Placement</th><td>{{ $item['placement'] }}@if($item['mounting'])<br><span class="small">{{ $item['mounting'] }}</span>@endif</td></tr>
        <tr><th>Calibration status</th><td><strong>{{ $item['calibration_state']->getLabel() }}</strong> — {{ $item['calibration_state']->getDescription() }}</td></tr>
        <tr><th>Calibration method</th><td>{{ $item['calibration'] }} · {{ $item['field_checks'] }} field check(s) recorded</td></tr>
        @if ($item['lf_band'])<tr><th>Low-frequency band</th><td>{{ $item['lf_band'] }} (unweighted, dB re 20 µPa)</td></tr>@endif
        <tr><th>Processing versions</th><td class="small">{{ $item['versions'] }}</td></tr>
    </table>
@endforeach

<h2 style="page-break-before: always;">Events ({{ count($events) }})</h2>
@if (count($events) === 0)
    <p>No detected events in this selection.</p>
@else
    <table>
        <thead>
        <tr>
            <th style="width:15%">Event / start (local)</th>
            <th style="width:9%">Length</th>
            <th style="width:15%">Trigger · baseline</th>
            <th style="width:12%">Agent summary</th>
            <th style="width:12%">Server summary</th>
            <th style="width:15%">Review · source</th>
            <th style="width:12%">Data</th>
            <th style="width:10%">Flags</th>
        </tr>
        </thead>
        @foreach ($events as $event)
            <tr>
                <td><span class="mono">{{ $event['short'] }}</span><br>{{ $event['local_start'] }}</td>
                <td>{{ $event['duration'] }}</td>
                <td>{{ $event['trigger'] }}<br><span class="small">{{ $event['baseline'] }}</span></td>
                <td>LAeq {{ $event['agent_laeq'] }}<br>LAFmax {{ $event['agent_lafmax'] }}</td>
                <td>LAeq {{ $event['server_laeq'] }}<br>LAFmax {{ $event['server_lafmax'] }}</td>
                <td>{{ $event['review'] }}<br><span class="small">{{ $event['source'] }}</span></td>
                <td>{{ $event['completeness'] }}<br><span class="small">{{ $event['recordings'] }}</span></td>
                <td class="small">{{ $event['flags'] ?: '—' }}</td>
            </tr>
        @endforeach
    </table>
    <p class="small muted">Agent summaries are reported by the device; server summaries are recomputed from retained one-second readings under quality policy {{ $policy['version'] }}. Differences are shown, not reconciled. "dB above baseline" is a simple level difference, not source isolation or background correction. "Confirmed disturbance" means a reviewer confirmed a disturbance, not ownership or a legal violation. Full event identifiers appear in the evidence bundle.</p>
@endif

@if (count($charts) > 0)
    <h2>Charts</h2>
    @foreach ($charts as $chart)
        <div class="chart">
            <h3>{{ $chart['title'] }}</h3>
            <img src="{{ $chart['uri'] }}" alt="{{ $chart['title'] }}">
        </div>
    @endforeach
@endif

<h2>Review notes</h2>
@forelse ($notes as $note)
    <div class="note"><strong>{{ $note['event'] }}</strong> · {{ $note['author'] }} · {{ $note['at'] }} · {{ $note['kind'] }}<br>{{ $note['text'] }}</div>
@empty
    <p>No review notes.</p>
@endforelse

<h2>Quality limitations</h2>
<ul>
    <li>Quality policy {{ $policy['version'] }} excludes values flagged {{ $policy['excluded_for_all_metrics'] }} from summaries; {{ $policy['excluded_for_absolute_metrics'] }} excludes absolute SPL values only. Ambiguous overlapping intervals are excluded. Raw values remain preserved.</li>
    @foreach ($flags as $flag => $count)
        <li>{{ $count }} event(s) reported the quality flag “{{ $flag }}”.</li>
    @endforeach
    @foreach ($missing as $line)
        <li>{{ $line }}</li>
    @endforeach
    <li>Device clock times are as reported by the device; the server cannot verify clock correctness from request arrival.</li>
</ul>

<div style="page-break-inside: avoid;">
    <h2>Integrity statement</h2>
    <p>{{ $hashStatement }}</p>
    <p class="small muted">This application never sends reports to third parties automatically.</p>
</div>
</body>
</html>
