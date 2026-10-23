<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · cajubi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-800 antialiased">
    <main class="grid min-h-screen place-items-center px-5 py-10">
        <div class="w-full max-w-md">
            <div class="mb-6 rounded-xl bg-[#002d20] px-8 py-7"><span class="dashboard-brand-logo mx-auto" style="--brand-logo: url('{{ asset('images/cajubi-gestor-logo.png') }}')"><span class="sr-only">cajubi gestor</span></span></div>
            {{ $slot }}
        </div>
    </main>
</body>
</html>
