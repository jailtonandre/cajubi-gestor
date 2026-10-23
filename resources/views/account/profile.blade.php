@component('layouts.app', ['title' => 'Minha conta'])
    <div class="grid max-w-2xl gap-6">
        <div class="grid gap-2"><p class="eyebrow">Configurações</p><h1 class="page-title">Minha conta</h1><p class="text-sm text-stone-500">Mantenha seu nome e e-mail atualizados.</p></div>
        <x-success/>
        <form method="POST" action="{{ route('account.update') }}" class="panel grid gap-5 p-6 sm:p-7">
            @csrf
            @method('PATCH')
            <x-field label="Nome" for="name"><input id="name" name="name" value="{{ old('name', $user->name) }}" class="field" autocomplete="name" maxlength="255" required></x-field>
            <x-field label="E-mail" for="email"><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" class="field" autocomplete="email" maxlength="255" required></x-field>
            <button type="submit" class="btn-primary justify-self-end">Salvar alterações</button>
        </form>
    </div>
@endcomponent
