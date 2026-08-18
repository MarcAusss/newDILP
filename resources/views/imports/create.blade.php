@extends('layouts.app')

@section('title', 'Import Province | '.config('app.name'))
@section('page-title', 'Import Province')

@section('content')
<div class="two-column">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">STEP 1</p>
                <h2>Upload the regional mapping workbook</h2>
                <p>You can upload the same workbook every time. The selected province decides which worksheet is read.</p>
            </div>
        </div>

        <form action="{{ route('imports.analyze') }}" method="POST" enctype="multipart/form-data" class="form-stack">
            @csrf

            <label class="field">
                <span>Province to import</span>
                <select name="province_id" required>
                    <option value="">Select province</option>
                    @foreach ($provinces as $province)
                        <option value="{{ $province->id }}" @selected(old('province_id') == $province->id)>
                            {{ $province->name }} — worksheet: {{ $province->sheet_name }}{{ $province->miro_board_id ? '' : ' — Miro board not configured' }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="field file-field">
                <span>Excel workbook</span>
                <input type="file" name="spreadsheet" accept=".xlsx,.xls" required data-file-input>
                <div class="file-box">
                    <strong data-file-name>Choose XLSX or XLS</strong>
                    <small>Maximum file size: {{ $maxFileMb }} MB</small>
                </div>
            </label>

            <div class="form-actions">
                <button class="button button-primary" type="submit">Analyze Selected Province</button>
            </div>
        </form>
    </section>

    <aside class="panel panel-muted">
        <p class="eyebrow">SUPPORTED WORKBOOK</p>
        <h2>EXTRACTED DATA for Mapping format</h2>
        <p>The parser is designed for the workbook structure you supplied.</p>

        <div class="mini-table">
            <div><strong>Worksheet</strong><span>Albay, Camarines Norte, Camarines Sur, Catanduanes, Masbate or Sorsogon</span></div>
            <div><strong>Municipality</strong><span>Blank merged rows inherit the last municipality automatically.</span></div>
            <div><strong>Barangay/s</strong><span>Each barangay becomes a bold heading in a green Miro box.</span></div>
            <div><strong>Undertaking columns</strong><span>Every positive value becomes “Undertaking - beneficiary count”.</span></div>
            <div><strong>SUMMARY sheets</strong><span>Ignored. Only the selected raw provincial worksheet is read.</span></div>
        </div>

        <div class="green-box-sample">
            <strong>San Vicente</strong>
            <span>Food Vending (Kakanin) - 1</span>
            <span>Street Food Vending - 1</span>
            <br>
            <strong>Sta. Cruz</strong>
            <span>Fish Vending - 2</span>
            <span>Fishing - 3</span>
            <span>Food Vending (Cooked Viand) - 6</span>
        </div>
    </aside>
</div>
@endsection
