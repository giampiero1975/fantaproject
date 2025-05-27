@extends('layouts.app')

@section('title', 'Profilo Lega Fantacalcio')

@section('content')
    <form action="{{ route('league.profile.update') }}" method="POST">
        @csrf

        <div>
            <label for="league_name">Nome Lega:</label><br>
            <input type="text" id="league_name" name="league_name" value="{{ old('league_name', $profile->league_name ?? 'La Mia Lega') }}" required>
            @error('league_name')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <div>
            <label for="total_budget">Budget Totale (crediti):</label><br>
            <input type="number" id="total_budget" name="total_budget" value="{{ old('total_budget', $profile->total_budget ?? 500) }}" required min="1">
            @error('total_budget')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <h3>Numero Giocatori per Ruolo:</h3>
        <div>
            <label for="num_goalkeepers">Portieri:</label><br>
            <input type="number" id="num_goalkeepers" name="num_goalkeepers" value="{{ old('num_goalkeepers', $profile->num_goalkeepers ?? 3) }}" required min="1" max="5">
            @error('num_goalkeepers')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <div>
            <label for="num_defenders">Difensori:</label><br>
            <input type="number" id="num_defenders" name="num_defenders" value="{{ old('num_defenders', $profile->num_defenders ?? 8) }}" required min="1" max="15">
            @error('num_defenders')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <div>
            <label for="num_midfielders">Centrocampisti:</label><br>
            <input type="number" id="num_midfielders" name="num_midfielders" value="{{ old('num_midfielders', $profile->num_midfielders ?? 8) }}" required min="1" max="15">
            @error('num_midfielders')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <div>
            <label for="num_attackers">Attaccanti:</label><br>
            <input type="number" id="num_attackers" name="num_attackers" value="{{ old('num_attackers', $profile->num_attackers ?? 6) }}" required min="1" max="10">
            @error('num_attackers')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <div>
            <label for="num_participants">Numero Partecipanti Lega:</label><br>
            <input type="number" id="num_participants" name="num_participants" value="{{ old('num_participants', $profile->num_participants ?? 10) }}" required min="2" max="20">
            @error('num_participants')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <div>
            <label for="scoring_rules">Regole di Punteggio (formato JSON):</label><br>
            <textarea id="scoring_rules" name="scoring_rules" rows="10" cols="80" placeholder='Esempio: {"gol_attaccante": 3, "assist": 1, ...}'>{{ old('scoring_rules', is_array($profile->scoring_rules) ? json_encode($profile->scoring_rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ($profile->scoring_rules ?? '')) }}</textarea>
            <p><small>Lasciare vuoto per usare le regole standard (da definire). Inserire un JSON valido.</small></p>
            @error('scoring_rules')<div style="color:red">{{ $message }}</div>@enderror
        </div>
        <br>
        <button type="submit">Salva Profilo Lega</button>
    </form>

    <hr style="margin-top: 20px; margin-bottom: 20px;">
    <h3>Dati Attuali del Profilo:</h3>
    @if($profile)
    <p><strong>Nome Lega:</strong> {{ $profile->league_name }}</p>
    <p><strong>Budget Totale:</strong> {{ $profile->total_budget }}</p>
    <p><strong>Portieri:</strong> {{ $profile->num_goalkeepers }}</p>
    <p><strong>Difensori:</strong> {{ $profile->num_defenders }}</p>
    <p><strong>Centrocampisti:</strong> {{ $profile->num_midfielders }}</p>
    <p><strong>Attaccanti:</strong> {{ $profile->num_attackers }}</p>
    <p><strong>Totale Giocatori:</strong> {{ $profile->totalPlayersInRoster }}</p>
    <p><strong>Partecipanti:</strong> {{ $profile->num_participants }}</p>
    <p><strong>Regole Punteggio (JSON):</strong></p>
    <pre><code>{{ is_array($profile->scoring_rules) ? json_encode($profile->scoring_rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ($profile->scoring_rules ?? 'Non definite') }}</code></pre>
    @else
    <p>Nessun profilo lega trovato.</p>
    @endif

@endsection
