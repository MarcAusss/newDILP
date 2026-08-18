<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">MI</div>
            <div>
                <strong>Miro Importer</strong>
                <span>Provincial Data Mapping</span>
            </div>
        </div>

        <nav class="nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('imports.create') }}" class="{{ request()->routeIs('imports.create', 'imports.preview') ? 'active' : '' }}">Import Province</a>
            <a href="{{ route('imports.index') }}" class="{{ request()->routeIs('imports.index', 'imports.show') ? 'active' : '' }}">Import History</a>
            <a href="{{ route('provinces.index') }}" class="{{ request()->routeIs('provinces.*') ? 'active' : '' }}">Province Mapping</a>
            <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*', 'miro.*') ? 'active' : '' }}">Miro Settings</a>
        </nav>

        <div class="sidebar-note">
            <strong>2026 workbook mode</strong>
            <span>Choose one province. Only its matching worksheet is read and synchronized.</span>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <p class="eyebrow">DATA MAPPING TOOL</p>
                <h1>@yield('page-title', 'Dashboard')</h1>
            </div>
            <div class="topbar-actions">
                <a class="button button-secondary" href="{{ route('provinces.index') }}">Mapping Setup</a>
                <a class="button button-primary" href="{{ route('imports.create') }}">New Import</a>
            </div>
        </header>

        <section class="content">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error">
                    <strong>Please correct the following:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </section>
    </main>
</div>
<script src="{{ asset('assets/app.js') }}" defer></script>
</body>
</html>
