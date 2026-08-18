@extends('layouts.app')

@section('title', $province->name.' Municipality Mapping | '.config('app.name'))
@section('page-title', $province->name.' Municipality Mapping')

@section('content')
<div class="alert alert-info">
    <strong>This scan is read-only.</strong>
    It reads the configured Miro board, finds municipality labels that exist as Miro text/shape items, detects existing green data boxes, and stores their coordinates locally. The scan itself does not delete or change Miro board items.
</div>

@if (!$province->miro_board_id)
    <div class="alert alert-error">
        Configure the Miro board for {{ $province->name }} in Province Mapping before scanning.
    </div>
@endif

<section class="panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">MIRO DISCOVERY</p>
            <h2>Scan existing provincial map</h2>
            <p>
                Existing green boxes are used as preferred placement. If a municipality has no green box, a new box position is calculated for the later spreadsheet import.
            </p>
        </div>
    </div>

    <div class="form-actions split-actions">
        <a class="button button-secondary" href="{{ route('provinces.index') }}">
            Back to Province Mapping
        </a>

        <form action="{{ route('provinces.municipalities.scan', $province) }}" method="POST">
            @csrf
            <button class="button button-primary" type="submit" @disabled(!$province->miro_board_id)>
                Scan Miro Board
            </button>
        </form>
    </div>
</section>

@if ($mappings->isEmpty())
    <section class="panel">
        <div class="empty-state">
            No municipality list is configured yet for {{ $province->name }}.
        </div>
    </section>
@else
    <form action="{{ route('provinces.municipalities.update', $province) }}" method="POST">
        @csrf
        @method('PUT')

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">MUNICIPALITY ANCHORS</p>
                    <h2>Detected map labels and green-box positions</h2>
                    <p>
                        You can manually correct coordinates and continuation direction after scanning. The importer preserves the base provincial map.
                    </p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="mapping-table">
                    <thead>
                    <tr>
                        <th>Municipality</th>
                        <th>Map Label</th>
                        <th>Existing Green Boxes</th>
                        <th>Anchor X</th>
                        <th>Anchor Y</th>
                        <th>First Box X</th>
                        <th>First Box Y</th>
                        <th>More Boxes</th>
                        <th>Configured</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($mappings as $index => $mapping)
                        @php
                            $status = $scanStatuses->get($mapping->municipality_key);
                            $panelCount = (int) data_get($status?->meta, 'legacy_panel_count', 0);
                        @endphp

                        <tr>
                            <td>
                                <strong>{{ $mapping->municipality }}</strong>
                                <input type="hidden" name="mappings[{{ $index }}][id]" value="{{ $mapping->id }}">
                            </td>

                            <td>
                                @if ($status)
                                    <span class="badge badge-completed">Found</span>
                                @else
                                    <span class="badge badge-failed">Missing</span>
                                @endif
                            </td>

                            <td>
                                @if ($status && $panelCount > 0)
                                    <span class="badge badge-completed">{{ $panelCount }} found</span>
                                @elseif ($status)
                                    <span class="badge">Create new</span>
                                @else
                                    <span>—</span>
                                @endif
                            </td>

                            <td>
                                <input class="coordinate-input" type="number" name="mappings[{{ $index }}][anchor_x]" value="{{ $mapping->anchor_x }}" required>
                            </td>

                            <td>
                                <input class="coordinate-input" type="number" name="mappings[{{ $index }}][anchor_y]" value="{{ $mapping->anchor_y }}" required>
                            </td>

                            <td>
                                <input class="coordinate-input" type="number" name="mappings[{{ $index }}][panel_x]" value="{{ $mapping->panel_x }}" required>
                            </td>

                            <td>
                                <input class="coordinate-input" type="number" name="mappings[{{ $index }}][panel_y]" value="{{ $mapping->panel_y }}" required>
                            </td>

                            <td>
                                <select name="mappings[{{ $index }}][flow_direction]">
                                    @foreach (['right' => '→ Right', 'left' => '← Left', 'down' => '↓ Down', 'up' => '↑ Up'] as $value => $label)
                                        <option value="{{ $value }}" @selected($mapping->flow_direction === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>

                            <td>
                                <label class="inline-check">
                                    <input type="hidden" name="mappings[{{ $index }}][configured]" value="0">
                                    <input type="checkbox" name="mappings[{{ $index }}][configured]" value="1" @checked($mapping->configured)>
                                    <span>{{ $mapping->configured ? 'Yes' : 'No' }}</span>
                                </label>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="sticky-actions">
            <a class="button button-secondary" href="{{ route('provinces.index') }}">
                Back to Provinces
            </a>
            <button class="button button-primary" type="submit">
                Save Manual Corrections
            </button>
        </div>
    </form>
@endif
@endsection
