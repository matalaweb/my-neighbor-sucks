<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept invitation · Noise Monitor</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-md px-4 py-16">
        <h1 class="text-2xl font-semibold">Noise Monitor</h1>
        @if ($invitation === null)
            <p class="mt-6 rounded bg-red-50 p-4 text-sm text-red-800">This invitation is invalid, expired, or has already been used. Ask an owner to send a new one.</p>
        @else
            <p class="mt-4 text-sm">Join <strong>{{ $invitation->account->name }}</strong> as <strong>{{ $invitation->role->getLabel() }}</strong> with <strong>{{ $invitation->email }}</strong>.</p>
            <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-6 space-y-4">
                @csrf
                <label class="block text-sm">Your name
                    <input name="name" value="{{ old('name') }}" required class="mt-1 block w-full rounded border border-slate-300 px-3 py-2">
                </label>
                <label class="block text-sm">Choose a password (12+ characters)
                    <input type="password" name="password" required autocomplete="new-password" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2">
                </label>
                <label class="block text-sm">Confirm password
                    <input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2">
                </label>
                @if ($errors->any())
                    <ul class="text-sm text-red-700">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                @endif
                <button class="rounded bg-teal-700 px-4 py-2 text-sm font-medium text-white">Accept and sign in</button>
            </form>
        @endif
    </main>
</body>
</html>
