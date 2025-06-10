{{-- resources/views/dashboard/historical_coverage.blade.php --}}

@extends('layouts.app')

@section('title', 'Analisi Copertura Storico')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Analisi Copertura Storico Classifiche</h1>
            <p class="text-lg text-gray-600">
                Verifica la disponibilità dei dati storici per le 20 squadre di Serie A attive.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i> Torna alla Dashboard
        </a>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md">
        <div class="overflow-x-auto">
            <table class="table table-striped table-hover w-full">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left">Squadra</th>
                        @foreach($requiredSeasons as $season)
                            <th class="px-4 py-2 text-center">{{ $season }}-{{ substr($season + 1, -2) }}</th>
                        @endforeach
                        <th class="px-4 py-2 text-center">Stato</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($coverageData as $data)
                        <tr>
                            <td class="px-4 py-2 font-semibold">{{ $data['team_name'] }}</td>
                            @foreach($requiredSeasons as $season)
                                <td class="px-4 py-2 text-center">
                                    @if(in_array($season, $data['available_seasons']))
                                        <i class="fas fa-check-circle text-success" title="Dato Presente"></i>
                                    @else
                                        <i class="fas fa-times-circle text-danger" title="Dato Mancante"></i>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-4 py-2 text-center">
                                @if($data['is_complete'])
                                    <span class="badge bg-success">Completo</span>
                                @else
                                    <span class="badge bg-warning text-dark">Mancano {{ count($data['missing_seasons']) }} stagioni</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection