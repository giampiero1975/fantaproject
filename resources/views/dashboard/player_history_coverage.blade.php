@extends('layouts.app')

@section('title', 'Copertura Storico Squadre')

@section('content')
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="display-6">Copertura Storico Squadre</h1>
            <p class="lead">
                Verifica della presenza dei dati storici per le squadre di Serie A. Il sistema richiede le ultime <strong>{{ count($requiredSeasons) }}</strong> stagioni.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Torna alla Dashboard
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start ps-3">Squadra</th>
                            {{-- Le colonne delle stagioni vengono generate dinamicamente --}}
                            @foreach($requiredSeasons as $season)
                                <th class="text-center">{{ $season }}-{{ substr($season + 1, -2) }}</th>
                            @endforeach
                            <th class="text-center">Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($coverageData as $data)
                            <tr>
                                <td class="fw-bold ps-3">{{ $data['team_name'] }}</td>
                                {{-- Per ogni stagione richiesta, mettiamo la spunta verde o la croce rossa --}}
                                @foreach($requiredSeasons as $season)
                                    <td class="text-center">
                                        @if(in_array($season, $data['available_seasons']))
                                            <i class="fas fa-check-circle text-success fs-5" title="Dato Presente"></i>
                                        @else
                                            <i class="fas fa-times-circle text-danger fs-5" title="Dato Mancante"></i>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-center">
                                    @if($data['is_complete'])
                                        <span class="badge bg-success">Completo</span>
                                    @else
                                        <span class="badge bg-warning text-dark">Incompleto</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($requiredSeasons) + 2 }}" class="text-center text-muted py-4">
                                    Nessuna squadra di Serie A attiva trovata.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection