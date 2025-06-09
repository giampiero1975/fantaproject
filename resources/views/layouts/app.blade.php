<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FantaProject')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">

    {{-- Eventuali altri stili globali dell'applicazione potrebbero rimanere qui o essere in un altro file CSS --}}
    <style>
        body { font-family: sans-serif; margin: 0; background-color: #f4f6f9; }
        .navbar { background-color: #333; overflow: hidden; }
        .navbar a { float: left; display: block; color: white; text-align: center; padding: 14px 16px; text-decoration: none; }
        .navbar a:hover { background-color: #ddd; color: black; }
         .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
         .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
         .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
         .alert-danger ul { margin-top: 0; margin-bottom: 0; padding-left: 20px; }
    </style>
</head>

<body>
	{{-- In resources/views/layouts/app.blade.php, dentro la navbar --}}
    <nav class="navbar">
        <a href="{{ route('dashboard') }}">Home</a>
        <a href="{{ route('roster.show') }}">Carica Roster</a>
        <a href="{{ route('historical_stats.show_upload_form') }}">Carica Statistiche</a>
        <a href="{{ route('league.profile.edit') }}">Profilo Lega</a>
        <a href="{{ route('projections.index') }}">Proiezioni</a>
    </nav>

    <div class="container">
        <header>
            <h1>@yield('header', View::hasSection('title') ? View::getSection('title') : 'FantaProject')</h1>
        </header>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Attenzione! Si sono verificati degli errori:</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        
        {{-- Gestione specifica per 'import_errors' che è un array --}}
        @if (session('import_errors'))
            <div class="alert alert-danger">
                <strong>Si sono verificati i seguenti errori durante l'importazione:</strong>
                <ul>
                    @foreach (session('import_errors') as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif


        <main class="content">
            @yield('content')
        </main>

        <footer style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccc; text-align: center;">
            <p>&copy; {{ date('Y') }} FantaProject</p>
        </footer>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Inizializza tutti i tooltip nella pagina
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>
    </body>
</html>