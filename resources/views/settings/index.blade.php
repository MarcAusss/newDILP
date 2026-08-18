@extends('layouts.app')

@section('title', 'Miro Settings | '.config('app.name'))
@section('page-title', 'Miro Settings')

@section('content')
<div class="two-column">
    <section class="panel">
        <div class="panel-heading">
            <div><p class="eyebrow">OAUTH 2.0</p><h2>Miro connection</h2></div>
            <span class="badge {{ $connection ? 'badge-completed' : 'badge-failed' }}">{{ $connection ? 'Connected' : 'Disconnected' }}</span>
        </div>

        @if ($connection)
            <div class="connection-details">
                <div><span>Team ID</span><strong>{{ $connection->team_id ?: 'Not supplied' }}</strong></div>
                <div><span>User ID</span><strong>{{ $connection->user_id ?: 'Not supplied' }}</strong></div>
                <div><span>Scopes</span><strong>{{ $connection->scopes ?: 'Unknown' }}</strong></div>
                <div><span>Token expires</span><strong>{{ $connection->expires_at?->format('M d, Y g:i A') ?: 'Not specified' }}</strong></div>
            </div>
            <form action="{{ route('miro.disconnect') }}" method="POST" data-confirm="Disconnect Miro from this website?">
                @csrf
                <button type="submit" class="button button-danger">Disconnect Miro</button>
            </form>
        @else
            <p>Connect the website to your Miro account. Miro will ask you to select a team and approve the requested board permissions.</p>
            <a class="button button-primary" href="{{ route('miro.connect') }}">Connect Miro</a>
        @endif
    </section>

    <aside class="panel panel-muted">
        <p class="eyebrow">APP CONFIGURATION</p>
        <h2>Required .env values</h2>
        <pre class="code-block">MIRO_CLIENT_ID=
MIRO_CLIENT_SECRET=
MIRO_REDIRECT_URI={{ url('/miro/callback') }}</pre>
        <p class="small-text">The exact redirect URI above must also be added to your Miro developer app settings.</p>
    </aside>
</div>

@if ($connection)
<section class="panel top-gap">
    <div class="panel-heading">
        <div><p class="eyebrow">AVAILABLE BOARDS</p><h2>Boards visible to this connection</h2></div>
    </div>

    @if ($boardError)
        <div class="alert alert-error">{{ $boardError }}</div>
    @elseif (empty($boards))
        <div class="empty-state">No boards were returned by Miro.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>Board name</th><th>Board ID</th></tr></thead>
                <tbody>
                @foreach ($boards as $board)
                    <tr>
                        <td><strong>{{ data_get($board, 'name', 'Unnamed board') }}</strong></td>
                        <td><code>{{ data_get($board, 'id') }}</code></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endif
@endsection
