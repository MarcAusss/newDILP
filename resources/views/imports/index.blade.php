@extends('layouts.app')

@section('title', 'Import History | '.config('app.name'))
@section('page-title', 'Import History')

@section('content')
<section class="panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">AUDIT TRAIL</p>
            <h2>Provincial imports</h2>
        </div>
        <a class="button button-primary" href="{{ route('imports.create') }}">New Import</a>
    </div>

    @if ($imports->isEmpty())
        <div class="empty-state">No import records yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Province</th><th>Worksheet</th><th>File</th><th>Municipalities</th><th>Barangays</th><th>Beneficiaries</th><th>Entries</th><th>Status</th><th>Created</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($imports as $import)
                    <tr>
                        <td><strong>{{ $import->province->name }}</strong></td>
                        <td>{{ $import->sheet_name }}</td>
                        <td class="truncate">{{ $import->original_filename }}</td>
                        <td>{{ number_format($import->municipality_count) }}</td>
                        <td>{{ number_format($import->barangay_count) }}</td>
                        <td>{{ number_format((float) $import->beneficiary_total, 0) }}</td>
                        <td>{{ number_format($import->undertaking_total) }}</td>
                        <td><span class="badge badge-{{ $import->status }}">{{ ucfirst($import->status) }}</span></td>
                        <td>{{ $import->created_at->format('M d, Y g:i A') }}</td>
                        <td><a href="{{ $import->status === 'preview' || $import->status === 'failed' ? route('imports.preview', $import) : route('imports.show', $import) }}">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">
            @if ($imports->hasPages())
                <div class="pagination">
                    @if ($imports->onFirstPage())
                        <span class="button button-secondary disabled">Previous</span>
                    @else
                        <a class="button button-secondary" href="{{ $imports->previousPageUrl() }}">Previous</a>
                    @endif
                    <span>Page {{ $imports->currentPage() }} of {{ $imports->lastPage() }}</span>
                    @if ($imports->hasMorePages())
                        <a class="button button-secondary" href="{{ $imports->nextPageUrl() }}">Next</a>
                    @else
                        <span class="button button-secondary disabled">Next</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
</section>
@endsection
