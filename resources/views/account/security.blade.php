@component('layouts.app', ['title' => 'Segurança e senha'])
    <div class="grid max-w-2xl gap-6">
        <div class="grid gap-2"><p class="eyebrow">Configurações</p><h1 class="page-title">Segurança e senha</h1><p class="text-sm text-stone-500">Confirme sua senha atual para definir uma nova.</p></div>
        <x-success/>
        <form method="POST" action="{{ route('account.password') }}" class="panel grid gap-5 p-6 sm:p-7">
            @csrf
            @method('PUT')
            <x-field label="Senha atual" for="current_password"><input id="current_password" name="current_password" type="password" class="field" autocomplete="current-password" required></x-field>
            <x-field label="Nova senha" for="password" hint="Use pelo menos 12 caracteres, com maiúsculas, minúsculas, números e símbolos."><input id="password" name="password" type="password" class="field" autocomplete="new-password" maxlength="255" required></x-field>
            <x-field label="Confirmação da nova senha" for="password_confirmation"><input id="password_confirmation" name="password_confirmation" type="password" class="field" autocomplete="new-password" maxlength="255" required></x-field>
            <button type="submit" class="btn-primary justify-self-end">Alterar senha</button>
        </form>
    </div>
@endcomponent
