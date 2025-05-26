<!DOCTYPE html>
<html>
<head>
    <title>Carica Rosa Ufficiale</title>
</head>
<body>
    <h1>Carica File Rosa Ufficiale (XLSX)</h1>

    @if (session('success'))
        <div style="color: green;">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div style="color: red;">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('roster.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="roster_file" required>
        <button type="submit">Carica File</button>
    </form>
</body>
</html>