@component('layouts.guest')
    <section class="panel grid gap-6 p-6 sm:p-8" aria-labelledby="login-title">
        <div class="grid gap-2"><p class="eyebrow">Acesso administrativo</p><h1 id="login-title" class="page-title">Entrar no gestor</h1><p class="text-sm text-stone-500">Acesse a gestão comercial da cajubi.</p></div>
        <form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
            @csrf
            <x-field label="E-mail" for="email"><input id="email" name="email" type="email" value="{{ old('email') }}" class="field" autocomplete="username" maxlength="255" required autofocus></x-field>
            <x-field label="Senha" for="password"><input id="password" name="password" type="password" class="field" autocomplete="current-password" maxlength="255" required></x-field>
            <button type="submit" class="btn-primary w-full">Entrar</button>
        </form>
    </section>
@endcomponent
