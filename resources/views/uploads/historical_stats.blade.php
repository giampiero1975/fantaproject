<!DOCTYPE html>
<html>
<head>
    <title>Carica Statistiche Storiche Giocatori</title>
</head>
<body>
    <h1>Carica File Statistiche Storiche (XLSX)</h1>

    @if (session('success'))
        <div style="color: green;">{{ session('success') }}</div>
    @endif

    @if (session('import_errors')) <div style="color: red;">
            <strong>Si sono verificati i seguenti errori:</strong>
            <ul>
                @foreach (session('import_errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($errors->any() && !$errors->has('import_error') && !session('import_errors')) <div style="color: red;">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('historical_stats.handle_upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="historical_stats_file" required>
        <button type="submit">Carica File Statistiche</button>
    </form>
</body>
</html>