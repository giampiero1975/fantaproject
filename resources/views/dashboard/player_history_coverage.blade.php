@extends('layouts.app')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-header">
            <h1>Copertura Statistiche Storiche per Squadra (Serie A)</h1>
        </div>
        <div class="card-body">
            @if (empty($targetSeasons))
                <div class="alert alert-warning">
                    Nessuna stagione definita nel file di configurazione `projection_settings.php`.
                </div>
            @else
                <table class="table table-striped table-hover">
                    <thead class="thead-dark">
                        <tr>
                            <th>Squadra</th>
                            {{-- Usa la variabile $targetSeasons per creare le intestazioni --}}
                            @foreach ($targetSeasons as $season)
                                <th class="text-center">{{ $season }}-{{ $season + 1 }}</th>
                            @endforeach
                            <th class="text-center">Stato Copertura</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Usa la variabile $coverageData per il corpo della tabella --}}
                        @forelse ($coverageData as $teamData)
                            <tr>
                                <td>{{ $teamData['team_name'] }}</td>
                                
                                {{-- Itera sulle stagioni nell'ordine corretto --}}
                                @foreach ($targetSeasons as $season)
                                    <td class="text-center">
                                        {{-- Controlla la copertura per la stagione specifica --}}
                                        @if (isset($teamData['coverage'][$season]) && $teamData['coverage'][$season])
                                            <i class="fas fa-check-circle text-success" title="Presente"></i>
                                        @else
                                            <i class="fas fa-times-circle text-danger" title="Mancante"></i>
                                        @endif
                                    </td>
                                @endforeach

                                <td class="text-center">
                                    @if ($teamData['is_fully_covered'])
                                        <span class="badge bg-success">Completa</span>
                                    @else
                                        <span class="badge bg-warning text-dark">Incompleta</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($targetSeasons) + 2 }}" class="text-center">Nessuna squadra di Serie A trovata.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
@endsection