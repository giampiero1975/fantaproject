@extends('layouts.app')

@section('title', 'Proiezioni Fantacalcio')

@section('content')
<div class="container">
    <h2>Proiezioni Giocatori</h2>

    {{-- Form Filtri --}}
    <form action="{{ route('projections.index') }}" method="GET" class="mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="season" class="form-label">Stagione</label>
                <select class="form-select" id="season" name="season">
                    <option value="">Tutte</option>
                    @foreach($availableSeasons as $s)
                        <option value="{{ $s }}" {{ ($activeFilters['season'] ?? '') == $s ? 'selected' : '' }}>{{ $s }}-{{ substr($s+1, -2) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label">Ruolo</label>
                <select class="form-select" id="role" name="role">
                    <option value="">Tutti</option>
                    @foreach($availableRoles as $r)
                        <option value="{{ $r }}" {{ ($activeFilters['role'] ?? '') == $r ? 'selected' : '' }}>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="player_name" class="form-label">Nome Giocatore</label>
                <input type="text" class="form-control" id="player_name" name="player_name" value="{{ $activeFilters['player_name'] ?? '' }}" placeholder="Cerca per nome...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Applica Filtri</button>
            </div>
        </div>
    </form>

    {{-- Tabella Proiezioni --}}
    @if($projections->isEmpty())
        <div class="alert alert-info">Nessuna proiezione trovata con i filtri specificati.</div>
    @else
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Giocatore</th>
                        <th>Squadra</th>
                        <th>Ruolo</th>
                        <th>Stagione</th>
                        <th>MV Proj</th>
                        <th>FM Proj</th>
                        <th>PG Proj</th>
                        <th>Punti Tot Proj</th>
                        <th>Gol Proj</th>
                        <th>Assist Proj</th>
                        {{-- Aggiungi altre colonne di proiezione se vuoi visualizzarle --}}
                    </tr>
                </thead>
                <tbody>
                    @foreach($projections as $projection)
                        <tr>
                            <td>{{ $projection->player->name ?? 'N/D' }}</td>
                            <td>{{ $projection->player->team->name ?? 'N/D' }}</td>
                            <td>{{ $projection->player->role ?? 'N/D' }}</td>
                            <td>{{ $projection->season_start_year }}-{{ substr($projection->season_start_year + 1, -2) }}</td>
                            <td>{{ number_format($projection->avg_rating_proj, 2) }}</td>
                            <td>{{ number_format($projection->fanta_mv_proj, 2) }}</td>
                            <td>{{ $projection->games_played_proj }}</td>
                            <td>{{ number_format($projection->total_fanta_points_proj, 2) }}</td>
                            <td>{{ number_format($projection->goals_scored_proj, 2) }}</td>
                            <td>{{ number_format($projection->assists_proj, 2) }}</td>
                            {{-- Aggiungi celle per altre proiezioni qui --}}
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Paginazione --}}
        <div class="d-flex justify-content-center">
            {{ $projections->links() }}
        </div>
    @endif
</div>
@endsection