@extends('layouts.app')

@section('title', 'Benvenuto in FantaProject')

@section('content')
    <div style="text-align: center;">
        <h2>Il tuo assistente per l'asta del Fantacalcio!</h2>
        <p>Usa il menu in alto per iniziare a caricare i dati.</p>

        {{-- Se vuoi mantenere la struttura originale della welcome page di Laravel, 
             puoi includerla qui o spostare il suo contenuto dentro questo @section('content') --}}

        {{-- Esempio di link alle sezioni di upload --}}
        <div style="margin-top: 30px;">
            <p><a href="{{ route('roster.show') }}">Vai a Caricamento Roster</a></p>
            <p><a href="{{ route('historical_stats.show_upload_form') }}">Vai a Caricamento Statistiche Storiche</a></p>
        </div>
    </div>
@endsection