<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrativeAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Authentication tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    public function test_valid_login_rotates_session_and_redirects_to_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'Fixture-Login-902!']);
        $this->withSession(['url.intended' => route('sales')])->get(route('login'))->assertOk();
        $id = session()->getId();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Fixture-Login-902!'])->assertRedirect(route('commercial-dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($id, session()->getId());
        $this->get(route('login'))->assertRedirect(route('commercial-dashboard'));
    }

    public function test_invalid_login_is_generic_and_does_not_flash_password(): void
    {
        $user = User::factory()->create();
        $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrect-secret'])
            ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);
        $this->assertGuest();
        $this->assertArrayNotHasKey('password', session()->getOldInput());
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => 'absent@example.test', 'password' => 'invalid'])->assertSessionHasErrors('email');
        }
        $this->post(route('login.store'), ['email' => 'absent@example.test', 'password' => 'invalid'])->assertTooManyRequests();
    }

    #[DataProvider('internalPages')]
    public function test_visitors_are_redirected_from_all_internal_pages(string $path): void
    {
        $this->get($path)->assertRedirect(route('login'));
    }

    public static function internalPages(): array
    {
        return array_map(fn (string $path): array => [$path], ['/', '/painel-comercial', '/produtos', '/produtos/novo', '/produtos/1/editar', '/insumos', '/insumos/novo', '/insumos/1', '/simulador', '/tarifas', '/tarifas/novo', '/tarifas/1/editar', '/cenarios', '/viabilidade', '/fluxo-de-caixa', '/vendas', '/minha-conta', '/seguranca']);
    }

    public function test_visitors_cannot_submit_internal_actions(): void
    {
        $this->patch(route('account.update'), ['name' => 'Intruder'])->assertRedirect(route('login'));
        $this->put(route('account.password'), ['password' => 'Intruder-123!'])->assertRedirect(route('login'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_livewire_actions_recheck_authentication_after_logout(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('sales'))->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches);
        Auth::logout();
        $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update'), ['components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [],
            'calls' => [['path' => '', 'method' => 'saveSale', 'params' => []]],
        ]]])->assertUnauthorized();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_profile_update_changes_only_own_profile_and_preserves_password(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $password = $user->password;
        $this->actingAs($user)->patch(route('account.update'), ['name' => 'Jailton', 'email' => 'updated@example.test', 'id' => $other->id, 'password' => 'injected'])
            ->assertRedirect(route('account.edit'))->assertSessionHas('success');
        $this->assertSame('Jailton', $user->fresh()->name);
        $this->assertSame('updated@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame($other->email, $other->fresh()->email);
    }

    public function test_profile_rejects_duplicate_and_invalid_fields_and_accepts_unchanged_email(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->patch(route('account.update'), ['name' => '', 'email' => $other->email])->assertSessionHasErrors(['name', 'email']);
        $this->patch(route('account.update'), ['name' => str_repeat('a', 256), 'email' => 'not-email'])->assertSessionHasErrors(['name', 'email']);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->patch(route('account.update'), ['name' => 'Same email', 'email' => $user->email])->assertSessionHasNoErrors();
    }

    public function test_password_change_requires_current_password_and_stores_hash(): void
    {
        $user = User::factory()->create(['password' => 'Current-Password-123!']);
        $hash = $user->password;
        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'incorrect', 'password' => 'Replacement-Password-902!', 'password_confirmation' => 'Replacement-Password-902!'])
            ->assertSessionHasErrors(['current_password' => 'A senha atual está incorreta.']);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertArrayNotHasKey('current_password', session()->getOldInput());
        $this->put(route('account.password'), ['current_password' => 'Current-Password-123!', 'password' => 'Replacement-Password-902!', 'password_confirmation' => 'Replacement-Password-902!'])
            ->assertRedirect(route('account.security'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Replacement-Password-902!', $user->fresh()->password));
        $this->get(route('account.security'))->assertOk();
    }

    #[DataProvider('invalidPasswords')]
    public function test_password_change_rejects_weak_unconfirmed_or_reused_password(string $password, string $confirmation): void
    {
        $user = User::factory()->create(['password' => 'Current-Password-123!']);
        $hash = $user->password;
        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'Current-Password-123!', 'password' => $password, 'password_confirmation' => $confirmation])->assertSessionHasErrors('password');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public static function invalidPasswords(): array
    {
        return ['weak' => ['short', 'short'], 'unconfirmed' => ['Replacement-Password-902!', 'mismatch'], 'reused' => ['Current-Password-123!', 'Current-Password-123!']];
    }

    public function test_logout_invalidates_session_rotates_token_and_requires_post(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private-marker' => 'secret'])->get(route('account.edit'))->assertOk();
        $id = session()->getId();
        $token = session()->token();
        $this->get('/logout')->assertMethodNotAllowed();
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('private-marker');
        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->get(route('sales'))->assertRedirect(route('login'));
    }

    public function test_a_session_with_a_previous_password_hash_cannot_access_internal_pages(): void
    {
        $user = User::factory()->create();
        $previousHash = $user->password;
        $user->update(['password' => 'Changed-On-Another-Session-902!']);
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $previousHash])
            ->get(route('account.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_registration_is_not_public_and_seeder_creates_no_default_credentials(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->seed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_sidebar_preserves_commercial_order_and_opens_settings_on_account_pages(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
        $response = $this->actingAs($user)->get(route('account.edit'))->assertOk();
        $response->assertSeeInOrder(['Painel Comercial', 'Produtos', 'Insumos', 'Simulador de Preços', 'Tarifas dos Marketplaces', 'Cenários', 'Viabilidade', 'Fluxo de Caixa', 'Vendas', 'Configurações', 'Minha conta', 'Segurança e senha', 'Sair do sistema']);
        $response->assertSee('class="account-settings"  open', false)->assertSee('aria-current="page"', false)->assertDontSee('Regras de tarifas')->assertDontSee('Parâmetros do caixa')->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('account.security'))->assertOk()->assertSee('Senha atual')->assertSee('Confirmação da nova senha');
    }

    public function test_initial_administrator_is_created_securely_and_is_never_replaced(): void
    {
        $this->artisan('cajubi:admin', ['--name' => 'Administrator fixture', '--email' => 'admin@example.test'])
            ->expectsQuestion('Senha (mínimo de 12 caracteres, maiúsculas, minúsculas, números e símbolos)', 'Fixture-Administrator-902!')
            ->expectsQuestion('Confirme a senha', 'Fixture-Administrator-902!')->assertSuccessful();
        $user = User::query()->sole();
        $this->assertTrue(Hash::check('Fixture-Administrator-902!', $user->password));
        $this->artisan('cajubi:admin')->expectsOutput('Já existe uma conta cadastrada. Nenhuma credencial foi alterada.')->assertFailed();
        $this->assertSame($user->getAttributes(), $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_initial_administrator_rejects_invalid_data(): void
    {
        $this->artisan('cajubi:admin', ['--name' => 'Administrator fixture', '--email' => 'invalid'])
            ->expectsQuestion('Senha (mínimo de 12 caracteres, maiúsculas, minúsculas, números e símbolos)', 'weak')
            ->expectsQuestion('Confirme a senha', 'mismatch')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_initial_administrator_refuses_noninteractive_password_entry(): void
    {
        $this->artisan('cajubi:admin', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
