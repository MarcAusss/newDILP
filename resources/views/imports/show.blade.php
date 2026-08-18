@extends('layouts.app')

@section('title', 'Import Details | '.config('app.name'))
@section('page-title', 'Import Details')

@section('content')
<div class="summary-banner">
    <div>
        <p class="eyebrow">{{ strtoupper($import->status) }}</p>
        <h2>{{ $import->province->name }}</h2>
        <span>{{ $import->original_filename }} · {{ $import->sheet_name }}</span>
    </div>
    <div class="summary-numbers summary-numbers-four">
        <div><strong>{{ number_format($import->municipality_count) }}</strong><span>Municipalities</span></div>
        <div><strong>{{ number_format($import->barangay_count) }}</strong><span>Barangays</span></div>
        <div><strong>{{ number_format((float) $import->beneficiary_total, 0) }}</strong><span>Regular beneficiaries</span></div>
        <div><strong>{{ number_format($import->undertaking_total) }}</strong><span>Regular entries</span></div>
    </div>
</div>

@if ($import->group_project_count > 0)
    <section class="group-project-summary">
        <div>
            <p class="eyebrow">GROUP PROJECTS — EXCLUDED FROM REGULAR TOTALS</p>
            <h3>{{ number_format($import->group_project_count) }} Group Project{{ $import->group_project_count === 1 ? '' : 's' }}</h3>
        </div>
        <div class="group-project-summary-numbers">
            <div><strong>{{ number_format((float) $import->group_beneficiary_total, 0) }}</strong><span>Group beneficiaries</span></div>
            <div><strong>{{ number_format($import->group_project_count) }}</strong><span>Yellow Group Project boxes</span></div>
        </div>
    </section>
@endif

@if ($import->error_message)
    <div class="alert alert-error"><strong>Last synchronization error:</strong> {{ $import->error_message }}</div>
@endif

<section class="panel">
    <div class="panel-heading">
        <div><p class="eyebrow">REGULAR IMPORTED DATA</p><h2>Municipality → barangay → undertaking</h2></div>
        <span class="badge badge-{{ $import->status }}">{{ ucfirst($import->status) }}</span>
    </div>

    @forelse ($groupedRows as $municipality => $rows)
        <div class="municipality-block">
            <div class="municipality-heading"><strong>{{ $municipality }}</strong><span>{{ $rows->count() }} barangays</span></div>
            <div class="barangay-grid barangay-grid-preview">
                @foreach ($rows as $row)
                    <details class="barangay-card undertaking-card">
                        <summary><strong>{{ $row->barangay }}</strong><span>{{ $row->undertaking_count }} types · {{ number_format((float) $row->beneficiary_total, 0) }} beneficiaries</span></summary>
                        <div class="undertaking-list">
                            @foreach ($row->undertakings as $undertaking)
                                <div><span>{{ $undertaking['name'] }}</span><strong>{{ number_format((float) $undertaking['count'], 0) }}</strong></div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    @empty
        <div class="empty-state">No regular barangay records were included in this import.</div>
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
    <a class="button button-secondary" href="{{ route('imports.index') }}">Back to History</a>
    <form action="{{ route('imports.destroy', $import) }}" method="POST" data-confirm="Delete this local import record? This does not remove already-synced Miro items.">
        @csrf
        @method('DELETE')
        <button class="button button-danger" type="submit">Delete Local Record</button>
    </form>
</div>
@endsection
