@extends('layouts.app')

@section('title', 'Dashboard | '.config('app.name'))
@section('page-title', 'Dashboard')

@section('content')
<div class="stats-grid">
    <div class="stat-card">
        <span>Active Provinces</span>
        <strong>{{ $provinceCount }}</strong>
        <small>Available for individual import</small>
    </div>
    <div class="stat-card">
        <span>Mapped Provinces</span>
        <strong>{{ $configuredProvinceCount }}</strong>
        <small>With Miro board configuration</small>
    </div>
    <div class="stat-card">
        <span>Completed Imports</span>
        <strong>{{ $completedImportCount }}</strong>
        <small>Successful Miro synchronizations</small>
    </div>
    <div class="stat-card">
        <span>Miro Connection</span>
        <strong class="status-text {{ $miroConnected ? 'good' : 'bad' }}">{{ $miroConnected ? 'Connected' : 'Not connected' }}</strong>
        <small><a href="{{ route('settings.index') }}">Open connection settings</a></small>
    </div>
</div>

<div class="panel-grid">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">WORKFLOW</p>
                <h2>2026 provincial mapping workbook</h2>
            </div>
        </div>
        <ol class="workflow">
            <li><span>1</span><div><strong>Select one province</strong><small>The website reads only that raw provincial worksheet from the workbook.</small></div></li>
            <li><span>2</span><div><strong>Upload the Excel workbook</strong><small>Merged municipality cells, multi-row headers, undertaking columns and totals are handled automatically.</small></div></li>
            <li><span>3</span><div><strong>Preview barangay undertaking data</strong><small>Each positive activity cell becomes “Undertaking - beneficiary count”.</small></div></li>
            <li><span>4</span><div><strong>Synchronize green boxes to Miro</strong><small>Rounded green panels and red connectors are created; later imports update them without duplication.</small></div></li>
        </ol>
        <a class="button button-primary" href="{{ route('imports.create') }}">Start Provincial Import</a>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">LAYOUT BEHAVIOR</p>
                <h2>Designed for your map format</h2>
            </div>
        </div>
        <div class="green-box-sample dashboard-green-sample">
            <strong>Malosbolos</strong>
            <span>Street Food Vending - 1</span>
            <br>
            <strong>Marayag</strong>
            <span>Food Vending (Kakanin) - 1</span>
            <span>Nail Care Services - 1</span>
            <br>
            <strong>San Vicente</strong>
            <span>Food Vending (Kakanin) - 1</span>
            <span>Street Food Vending - 1</span>
        </div>
        <p class="small-text top-gap">One municipality can automatically continue into multiple green boxes when its barangay content is too long. Boxes are linked with red elbowed arrow connectors.</p>
    </section>
</div>

<section class="panel top-gap">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">RECENT ACTIVITY</p>
            <h2>Latest imports</h2>
        </div>
        <a href="{{ route('imports.index') }}">View all</a>
    </div>

    @if ($recentImports->isEmpty())
        <div class="empty-state">No imports yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Province</th><th>Worksheet</th><th>File</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                @foreach ($recentImports as $import)
                    <tr>
                        <td>{{ $import->province->name }}</td>
                        <td>{{ $import->sheet_name }}</td>
                        <td class="truncate">{{ $import->original_filename }}</td>
                        <td><span class="badge badge-{{ $import->status }}">{{ ucfirst($import->status) }}</span></td>
                        <td>{{ $import->created_at->format('M d, Y g:i A') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
