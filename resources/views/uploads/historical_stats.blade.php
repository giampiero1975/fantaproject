@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Importa Statistiche Storiche</div>

                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="alert alert-danger" role="alert">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('historical_stats.show_upload_form') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="form-group mb-3">
                            <label for="historical_stats_file">Seleziona file Statistiche Storiche (XLSX, XLS, CSV)</label>
                            <input type="file" class="form-control-file" id="historical_stats_file" name="historical_stats_file" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="season_start_year">Anno Inizio Stagione (es. 2023 per 2023-24)</label>
                            <input type="number" class="form-control" id="season_start_year" name="season_start_year" required min="2000" max="2099">
                        </div>
                        <div class="form-group mb-3">
                            <label for="league_name">Nome Lega (es. Serie A, Serie B, Liga)</label>
                            <input type="text" class="form-control" id="league_name" name="league_name" required value="Serie A"> {{-- Valore di default "Serie A" --}}
                        </div>
                        <button type="submit" class="btn btn-primary">Importa Statistiche Storiche</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection