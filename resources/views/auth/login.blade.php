<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kirish · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center px-4">
    <form method="POST" action="{{ route('login') }}" class="w-full max-w-sm bg-white rounded-xl shadow p-6 space-y-4">
        @csrf
        <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
        @error('email')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror
        <div>
            <label class="block text-sm mb-1" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="w-full border border-slate-300 rounded-md px-3 py-2">
        </div>
        <div>
            <label class="block text-sm mb-1" for="password">Parol</label>
            <input id="password" name="password" type="password" required class="w-full border border-slate-300 rounded-md px-3 py-2">
        </div>
        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md py-2">Kirish</button>
    </form>
</body>
</html>
