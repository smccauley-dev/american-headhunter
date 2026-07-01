<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'American Headhunter') }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0a1512">
    <link rel="apple-touch-icon" href="/icons/ah-mark.svg">
    <link rel="icon" type="image/svg+xml" href="/icons/ah-mark.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,300;1,9..144,400;1,9..144,500&family=Crimson+Pro:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=JetBrains+Mono:wght@300;400;500;600&display=swap">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
