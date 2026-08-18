@extends('layouts.app')

@section('title', 'Province Mapping | '.config('app.name'))
@section('page-title', 'Province Mapping')

@section('content')
<div class="alert alert-info">
    The provincial map itself stays in Miro. This website creates and updates the green data boxes, red municipality anchor dots, and red arrow connectors. You may use the same Miro board ID for all provinces if all provincial maps are on one board.
</div>

<div class="province-list">
@foreach ($provinces as $province)
    <section class="panel province-panel">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">PROVINCE</p>
                <h2>{{ $province->name }}</h2>
                <p>{{ $province->municipality_mappings_count }} municipality placements discovered</p>
            </div>
            <span class="badge {{ $province->miro_board_id ? 'badge-completed' : 'badge-failed' }}">{{ $province->miro_board_id ? 'Board configured' : 'Board not configured' }}</span>
        </div>

        <form action="{{ route('provinces.update', $province) }}" method="POST" class="form-grid">
            @csrf
            @method('PUT')

            <label class="field field-span-2">
                <span>Workbook worksheet name</span>
                <input type="text" name="sheet_name" value="{{ old('sheet_name', $province->sheet_name) }}" required>
                <small>This is the exact raw-data worksheet selected from the uploaded workbook.</small>
            </label>

            <label class="field field-span-2">
                <span>Miro Board ID or Board URL</span>
                <input type="text" name="miro_board_id" value="{{ old('miro_board_id', $province->miro_board_id) }}" placeholder="Paste uXjVGT09eQ4= or the full https://miro.com/app/board/... URL">
                <small>You can paste the full Miro board URL; the website extracts the board ID automatically.</small>
            </label>

            <label class="field field-span-2">
                <span>Miro Frame ID <em>optional</em></span>
                <input type="text" name="miro_frame_id" value="{{ old('miro_frame_id', $province->miro_frame_id) }}" placeholder="Leave blank if the map is directly on the board canvas">
            </label>

            <label class="field">
                <span>Staging Base X</span>
                <input type="number" name="base_x" value="{{ old('base_x', $province->base_x) }}" required>
            </label>

            <label class="field">
                <span>Staging Base Y</span>
                <input type="number" name="base_y" value="{{ old('base_y', $province->base_y) }}" required>
            </label>

            <label class="check-field field-span-2">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" @checked($province->active)>
                <span>Allow this province to appear in the import screen</span>
            </label>

            <div class="form-actions field-span-2 split-actions">
                <a class="button button-secondary" href="{{ route('provinces.municipalities', $province) }}">Municipality Placement</a>
                <button class="button button-primary" type="submit">Save {{ $province->name }}</button>
            </div>
        </form>
    </section>
@endforeach
</div>
@endsection
