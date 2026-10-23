<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('cajubi:admin {--name= : Nome do administrador} {--email= : E-mail do administrador}')]
#[Description('Cria a conta administrativa inicial sem alterar usuários existentes')]
class CreateAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('Já existe uma conta cadastrada. Nenhuma credencial foi alterada.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Execute este comando em um terminal interativo para informar a senha de forma oculta.');

            return self::FAILURE;
        }

        $data = [
            'name' => trim((string) ($this->option('name') ?: $this->ask('Nome do administrador'))),
            'email' => trim((string) ($this->option('email') ?: $this->ask('E-mail do administrador'))),
            'password' => $this->secret('Senha (mínimo de 12 caracteres, maiúsculas, minúsculas, números e símbolos)', false),
            'password_confirmation' => $this->secret('Confirme a senha', false),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'max:255', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        User::query()->create(collect($validator->validated())->only(['name', 'email', 'password'])->all());
        $this->info('Conta administrativa criada. Acesse a tela de login com as credenciais informadas.');

        return self::SUCCESS;
    }
}
