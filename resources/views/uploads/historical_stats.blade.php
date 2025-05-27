@extends('layouts.app')

@section('title', 'Carica File Statistiche Storiche')

@section('content')
    {{-- Rimosso il blocco @if (session('success')), @if (session('import_errors')) e @if ($errors->any()) perché gestito nel layout --}}
    {{-- Il titolo H1 è ora gestito dalla sezione @yield('header') nel layout o dal @section('title') --}}

    <form action="{{ route('historical_stats.handle_upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <p>
            <label for="historical_stats_file">Seleziona il file XLSX delle statistiche storiche:</label><br>
            <input type="file" name="historical_stats_file" id="historical_stats_file" required>
        </p>
        <button type="submit">Carica File Statistiche</button>
    </form>
@endsection