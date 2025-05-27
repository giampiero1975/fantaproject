@extends('layouts.app')

@section('title', 'Carica File Rosa Ufficiale')

@section('content')
    {{-- Rimosso il blocco @if (session('success')) e @if ($errors->any()) perché gestito nel layout --}}
    {{-- Il titolo H1 è ora gestito dalla sezione @yield('header') nel layout o dal @section('title') --}}

    <form action="{{ route('roster.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <p>
            <label for="roster_file">Seleziona il file XLSX del roster ufficiale:</label><br>
            <input type="file" name="roster_file" id="roster_file" required>
        </p>
        <button type="submit">Carica File Roster</button>
    </form>
@endsection