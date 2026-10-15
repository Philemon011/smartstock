<?php

namespace Tests\Feature\Auth;

use App\Actions\Tenants\RegisterTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;

class AuthenticationTest extends TestCase
{
    private const PASSWORD = 'mot-de-passe-test-123';

    private const LOGIN = '/api/v1/auth/login';

    private const LOGOUT = '/api/v1/auth/logout';

    private const ME = '/api/v1/me';

    private const FORGOT = '/api/v1/auth/forgot-password';

    private const RESET = '/api/v1/auth/reset-password';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        // Le frontend appelle l'API depuis cette origine : les requêtes sont « avec session ».
        $this->withHeaders(['Referer' => 'http://localhost:3000']);
    }

    /**
     * @return array{user: User, tenant: Tenant}
     */
    private function createAccount(bool $verified = true): array
    {
        $result = app(RegisterTenant::class)->handle([
            'company_name' => 'Boutique Test',
            'country' => 'BJ',
            'currency' => 'XOF',
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => self::PASSWORD,
        ]);

        if ($verified) {
            $result['user']->markEmailAsVerified();
        }

        return $result;
    }

    // ------------------------------------------------------------------ Connexion

    public function test_login_succeeds_and_returns_the_account_with_its_companies(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createAccount();

        $this->postJson(self::LOGIN, ['email' => ' Jean@Example.com ', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.email', 'jean@example.com')
            ->assertJsonPath('memberships.0.role', 'owner')
            ->assertJsonPath('memberships.0.tenant.id', $tenant->id)
            ->assertJsonPath('memberships.0.tenant.status', 'trial');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $this->createAccount();

        $wrong = $this->postJson(self::LOGIN, ['email' => 'jean@example.com', 'password' => 'mauvais-mot-de-passe']);
        $unknown = $this->postJson(self::LOGIN, ['email' => 'inconnu@example.com', 'password' => 'mauvais-mot-de-passe']);

        $wrong->assertStatus(422)->assertJsonPath('errors.credentials.0', 'Email ou mot de passe incorrect.');
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertGuest();
    }

    public function test_an_unverified_account_is_refused_only_after_a_correct_password(): void
    {
        $this->createAccount(verified: false);

        $this->postJson(self::LOGIN, ['email' => 'jean@example.com', 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');

        $this->postJson(self::LOGIN, ['email' => 'jean@example.com', 'password' => 'mauvais-mot-de-passe'])
            ->assertStatus(422)
            ->assertJsonPath('errors.credentials.0', 'Email ou mot de passe incorrect.');

        $this->assertGuest();
    }

    public function test_login_is_blocked_after_five_failed_attempts(): void
    {
        $this->createAccount();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::LOGIN, ['email' => 'jean@example.com', 'password' => 'mauvais-mot-de-passe'])
                ->assertStatus(422);
        }

        $this->postJson(self::LOGIN, ['email' => 'jean@example.com', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');

        $this->assertGuest();
    }

    public function test_login_requires_all_fields_with_clear_messages(): void
    {
        $this->postJson(self::LOGIN, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Indiquez votre adresse email.')
            ->assertJsonPath('errors.password.0', 'Saisissez votre mot de passe.');
    }

    // --------------------------------------------------- Déconnexion et utilisateur

        public function test_logout_closes_the_session(): void
    {
        ['user' => $user] = $this->createAccount();

        $this->actingAs($user)->postJson(self::LOGOUT)->assertNoContent();

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_me_requires_a_session_and_returns_the_account(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createAccount();

        $this->getJson(self::ME)
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('message', 'Votre session a expiré. Reconnectez-vous pour continuer.');

        $this->actingAs($user)->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('memberships.0.tenant.id', $tenant->id)
            ->assertJsonPath('memberships.0.role_label', 'Propriétaire');
    }

    // ------------------------------------------------------- Mot de passe oublié

    public function test_forgot_password_answers_identically_and_only_sends_to_existing_accounts(): void
    {
        Notification::fake();

        ['user' => $user] = $this->createAccount();

        $existing = $this->postJson(self::FORGOT, ['email' => 'jean@example.com']);
        $unknown = $this->postJson(self::FORGOT, ['email' => 'inconnu@example.com']);

        $existing->assertStatus(202);
        $this->assertSame($existing->json(), $unknown->json());

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_reset_changes_the_password_closes_sessions_and_verifies_the_account(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createAccount(verified: false);

        DB::table('sessions')->insert([
            'id' => 'session-a-fermer',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $token = Password::createToken($user);

        $this->postJson(self::RESET, [
            'token' => $token,
            'email' => 'jean@example.com',
            'password' => 'nouveau-mot-de-passe-123',
        ])->assertOk();

        $user->refresh();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-123', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(14, $tenant->fresh()->trialDaysRemaining());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->postJson(self::LOGIN, ['email' => 'jean@example.com', 'password' => 'nouveau-mot-de-passe-123'])
            ->assertOk();
    }

    public function test_reset_with_an_invalid_token_is_refused_and_changes_nothing(): void
    {
        ['user' => $user] = $this->createAccount();

        $this->postJson(self::RESET, [
            'token' => 'jeton-invalide',
            'email' => 'jean@example.com',
            'password' => 'nouveau-mot-de-passe-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.token.0',
                'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.',
            );

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_reset_enforces_the_password_rules(): void
    {
        ['user' => $user] = $this->createAccount();

        $this->postJson(self::RESET, [
            'token' => Password::createToken($user),
            'email' => 'jean@example.com',
            'password' => 'court',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Votre mot de passe doit contenir au moins 10 caractères.');
    }
}