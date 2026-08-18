@extends('layouts.app')

@section('title', 'Preview Import | '.config('app.name'))
@section('page-title', 'Import Preview')

@section('content')
<div class="summary-banner">
    <div>
        <p class="eyebrow">SELECTED PROVINCE</p>
        <h2>{{ $import->province->name }}</h2>
        <span>{{ $import->original_filename }} · worksheet: {{ $import->sheet_name }}</span>
    </div>
    <div class="summary-numbers summary-numbers-four">
        <div><strong>{{ number_format($import->municipality_count) }}</strong><span>Municipalities</span></div>
        <div><strong>{{ number_format($import->barangay_count) }}</strong><span>Barangays</span></div>
        <div><strong>{{ number_format((float) $import->beneficiary_total, 0) }}</strong><span>Regular beneficiaries</span></div>
        <div><strong>{{ number_format($import->undertaking_total) }}</strong><span>Regular undertaking entries</span></div>
    </div>
</div>

@if ($import->group_project_count > 0)
    <section class="group-project-summary">
        <div>
            <p class="eyebrow">#EA9999 GROUP PROJECTS</p>
            <h3>{{ number_format($import->group_project_count) }} separate Group Project{{ $import->group_project_count === 1 ? '' : 's' }}</h3>
            <p>These records are excluded from the municipality, barangay, regular beneficiary, and regular undertaking totals above.</p>
        </div>
        <div class="group-project-summary-numbers">
            <div><strong>{{ number_format((float) $import->group_beneficiary_total, 0) }}</strong><span>Group beneficiaries</span></div>
            <div><strong>{{ number_format($import->group_project_count) }}</strong><span>Yellow Group Project boxes</span></div>
        </div>
    </section>
@endif

@if (!$import->province->miro_board_id)
    <div class="alert alert-warning">
        <strong>Miro board required.</strong> Configure the board ID for {{ $import->province->name }} before synchronizing.
        <a href="{{ route('provinces.index') }}">Open Province Mapping</a>
    </div>
@endif

<div class="alert alert-info">
    <strong>Reference-layout mode is enabled.</strong>
    Normal barangay data is written to green municipality boxes with red arrows to the map pins. <strong>#EA9999</strong> Group Projects are separate yellow boxes showing only the project code and project-level beneficiaries. A provincial summary panel is generated automatically after the map and Group Project section.
</div>

@if ($unconfiguredMappings->isNotEmpty())
    <div class="alert alert-info">
        <strong>{{ $unconfiguredMappings->count() }} municipality placements have not been manually confirmed.</strong>
        During synchronization the system will first try to locate matching municipality labels already on the Miro provincial map and use those as permanent arrow anchors. If a label cannot be detected, the saved fallback coordinates are used.
        <a href="{{ route('provinces.municipalities', $import->province) }}">Review municipality placement</a>
    </div>
@endif

@if ($previousCompleted)
    <div class="alert alert-info">
        This province was previously synchronized on {{ $previousCompleted->completed_at?->format('M d, Y g:i A') }}. Existing generated green boxes, yellow Group Project boxes, connectors, and summary boxes on the same board are updated in place; obsolete generated items are removed.
    </div>
@endif

@if (!empty($import->warnings))
    <div class="alert alert-warning">
        <strong>Workbook checks:</strong>
        <ul>
            @foreach ($import->warnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">REGULAR MAPPING DATA</p>
            <h2>Review green-box content</h2>
            <p>Only non-#EA9999 rows are included here and in municipality/barangay totals.</p>
        </div>
        <span class="badge badge-preview">Preview only — Miro unchanged</span>
    </div>

    @forelse ($groupedRows as $municipality => $rows)
        <div class="municipality-block">
            <div class="municipality-heading">
                <strong>{{ $municipality }}</strong>
                <span>{{ $rows->count() }} barangays · {{ number_format((float) $rows->sum('beneficiary_total'), 0) }} beneficiaries</span>
            </div>
            <div class="barangay-grid barangay-grid-preview">
                @foreach ($rows as $row)
                    <details class="barangay-card undertaking-card">
                        <summary>
                            <strong>{{ $row->barangay }}</strong>
                            <span>{{ $row->undertaking_count }} undertaking types · {{ number_format((float) $row->beneficiary_total, 0) }} beneficiaries</span>
                        </summary>
                        <div class="undertaking-list">
                            @forelse ($row->undertakings as $undertaking)
                                <div><span>{{ $undertaking['name'] }}</span><strong>{{ number_format((float) $undertaking['count'], 0) }}</strong></div>
                            @empty
                                <small>No undertaking values were detected.</small>
                            @endforelse
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    @empty
        <div class="empty-state">No regular barangay rows were detected. All detected data may be marked as Group Projects.</div>
    @endforelse
</section>

@if ($groupProjectRows->isNotEmpty())
    <section class="panel group-project-panel-section">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">GROUP PROJECTS</p>
                <h2>One yellow box per highlighted project block</h2>
                <p>Each #EA9999 project block becomes exactly one yellow Miro box. The importer reads the merged Project Code and project-level No. of Beneficiaries once, then ignores all municipality, barangay, and undertaking detail rows inside that highlighted block.</p>
            </div>
            <span class="group-project-badge">#EA9999</span>
        </div>

        <div class="group-project-grid">
            @foreach ($groupProjectRows as $groupKey => $rows)
                @php
                    $projectCode = $rows->first()->group_project_label;
                    $groupBeneficiaries = (float) $rows->sum('beneficiary_total');
                @endphp

                <div class="group-project-card">
                    <div class="group-project-card-heading">
                        <span>PROJECT CODE</span>
                        <strong>{{ $projectCode }}</strong>
                    </div>

                    <div class="group-project-location">
                        <strong>Beneficiaries: {{ number_format($groupBeneficiaries, 0) }}</strong>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

<div class="sticky-actions">
    <a class="button button-secondary" href="{{ route('imports.create') }}">Cancel / Upload Another</a>
    <form action="{{ route('imports.commit', $import) }}" method="POST" data-confirm="Replace {{ $import->province->name }} generated map boxes, Group Project boxes, arrows, and summary boxes on the configured Miro board with this spreadsheet data now?">
        @csrf
        <button class="button button-primary" type="submit" @disabled(!$import->province->miro_board_id)>Synchronize to Miro</button>
    </form>
</div>
@endsection
