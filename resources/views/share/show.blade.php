<!DOCTYPE html>
<html lang="en" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · Noise Monitor</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/noise-charts.js'])
    @include('filament.partials.chart-bootstrap')
    <script>
        // Chart colours for the public page; follows the visitor's colour scheme.
        window.nmPublicChartTheme = function () {
            const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            return dark
                ? { grid: 'rgba(148, 163, 184, 0.12)', text: '#94a3b8', palette: ['#2dd4bf', '#a78bfa', '#fb923c'] }
                : { grid: 'rgba(15, 23, 42, 0.06)', text: '#64748b', palette: ['#0d9488', '#7c3aed', '#ea580c'] };
        };
    </script>
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-[32rem] bg-gradient-to-b from-teal-500/15 via-sky-500/5 to-transparent dark:from-teal-400/10"></div>

    <livewire:public-device-dashboard :token="$token" />
</body>
</html>
