<?php

namespace Tests\Feature\Auth;

use App\Actions\Tenants\RegisterTenant;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RegistrationAttemptNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private const PASSWORD = 'mot-de-passe-test-123';

    private const REGISTER = '/api/v1/auth/register';

    private const RESEND = '/api/v1/auth/email/resend';

    private string $pwnedBody = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Aucune requête réseau réelle : par défaut, aucun mot de passe n'est « compromis ».
        Http::fake([
            'api.pwnedpasswords.com/*' => fn () => Http::response($this->pwnedBody, 200),
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Boutique Test',
            'country' => 'BJ',
            'currency' => 'XOF',
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => self::PASSWORD,
        ], $overrides);
    }

    /**
     * @return array{user: User, tenant: Tenant}
     */
    private function registerUser(string $email = 'jean@example.com'): array
    {
        return app(RegisterTenant::class)->handle($this->payload(['email' => $email]));
    }

    private function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }

    private function frontend(string $status): string
    {
        return config('app.frontend_url').'/verify-email?status='.$status;
    }

    // ---------------------------------------------------------------- Inscription

    public function test_registration_creates_user_tenant_and_owner_membership(): void
    {
        Event::fake([Registered::class]);

        $this->postJson(self::REGISTER, $this->payload([
            'email' => '  Jean@Example.COM ',
            'company_name' => '  Boutique   Test ',
        ]))->assertStatus(202)->assertJsonStructure(['message']);

        $user = User::where('email', 'jean@example.com')->firstOrFail();
        $tenant = Tenant::firstOrFail();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('Boutique Test', $tenant->name);
        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertNull($tenant->trial_ends_at);
        $this->assertSame('Africa/Porto-Novo', $tenant->timezone);
        $this->assertSame(TenantRole::Owner, $user->memberships()->firstOrFail()->role);

        Event::assertDispatched(Registered::class);
    }

    public function test_existing_email_gets_an_identical_response_and_no_new_account(): void
    {
        Notification::fake();
        Event::fake([Registered::class]);

        $this->registerUser('jean@example.com');

        $new = $this->postJson(self::REGISTER, $this->payload(['email' => 'nouveau@example.com']));
        $existing = $this->postJson(self::REGISTER, $this->payload([
            'email' => 'jean@example.com',
            'company_name' => 'Autre Entreprise',
        ]));

        $existing->assertStatus(202);
        $this->assertSame($new->json(), $existing->json());
        $this->assertSame(2, User::count());
        $this->assertSame(2, Tenant::count());

        Notification::assertSentTo(
            User::where('email', 'jean@example.com')->firstOrFail(),
            RegistrationAttemptNotification::class,
        );
    }

    public function test_validation_errors_are_explicit_and_in_french(): void
    {
        $response = $this->postJson(self::REGISTER, [
            'company_name' => 'A',
            'country' => 'ZZ',
            'email' => 'pas-un-email',
            'password' => 'court',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'country', 'currency', 'name', 'email', 'password']);

        $response->assertJsonPath('errors.company_name.0', "Le nom de l'entreprise doit contenir au moins 2 caractères.");
        $response->assertJsonPath('errors.country.0', "Ce pays n'est pas encore pris en charge.");
        $response->assertJsonPath('errors.currency.0', 'Choisissez la devise de votre entreprise.');
        $response->assertJsonPath('errors.name.0', 'Indiquez votre nom complet.');
        $response->assertJsonPath('errors.email.0', 'Cette adresse email n\'est pas valide. Exemple : nom@entreprise.com');
        $response->assertJsonPath('errors.password.0', 'Votre mot de passe doit contenir au moins 10 caractères.');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Tenant::count());
    }

    public function test_a_password_found_in_a_data_leak_is_refused(): void
    {
        $password = 'mot-de-passe-fuite-123';
        $hash = strtoupper(sha1($password));
        $this->pwnedBody = substr($hash, 5).':150';

        $this->postJson(self::REGISTER, $this->payload(['password' => $password]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertSame(0, User::count());
    }

    public function test_registration_is_rate_limited_per_email(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::REGISTER, $this->payload())->assertStatus(202);
        }

        $this->postJson(self::REGISTER, $this->payload())
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    // ------------------------------------------------------------ Vérification

    public function test_valid_link_verifies_the_email_and_starts_the_14_day_trial(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->registerUser();

        $this->get($this->verificationUrl($user))
            ->assertRedirect($this->frontend('verified'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(14, $tenant->fresh()->trialDaysRemaining());
    }

    public function test_a_second_click_never_restarts_or_extends_the_trial(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->registerUser();
        $url = $this->verificationUrl($user);

        $this->get($url);
        $endsAt = $tenant->fresh()->trial_ends_at;

        $this->travel(10)->minutes();

        $this->get($url)->assertRedirect($this->frontend('already-verified'));
        $this->assertTrue($endsAt->equalTo($tenant->fresh()->trial_ends_at));
    }

    public function test_a_tampered_link_is_refused(): void
    {
        ['user' => $user] = $this->registerUser();

        $this->get($this->verificationUrl($user).'x')
            ->assertRedirect($this->frontend('invalid'));

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_refused(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->registerUser();
        $url = $this->verificationUrl($user);

        $this->travel(2)->hours();

        $this->get($url)->assertRedirect($this->frontend('invalid'));

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($tenant->fresh()->trial_ends_at);
    }

    public function test_a_link_with_the_hash_of_another_account_is_refused(): void
    {
        ['user' => $user] = $this->registerUser('jean@example.com');
        ['user' => $other] = $this->registerUser('autre@example.com');

        $this->get($this->verificationUrl($user, sha1($other->getEmailForVerification())))
            ->assertRedirect($this->frontend('invalid'));

        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ------------------------------------------------------------------ Renvoi

    public function test_resend_only_sends_to_unverified_accounts_with_identical_responses(): void
    {
        Notification::fake();

        ['user' => $unverified] = $this->registerUser('non-verifie@example.com');
        ['user' => $verified] = $this->registerUser('verifie@example.com');
        $verified->markEmailAsVerified();

        $first = $this->postJson(self::RESEND, ['email' => 'non-verifie@example.com']);
        $second = $this->postJson(self::RESEND, ['email' => 'verifie@example.com']);
        $third = $this->postJson(self::RESEND, ['email' => 'inconnu@example.com']);

        $first->assertStatus(202);
        $second->assertStatus(202);
        $third->assertStatus(202);
        $this->assertSame($first->json(), $second->json());
        $this->assertSame($first->json(), $third->json());

        Notification::assertSentTo($unverified, VerifyEmail::class);
        Notification::assertNotSentTo($verified, VerifyEmail::class);
        Notification::assertCount(1);
    }

    public function test_resend_is_limited_to_one_request_per_minute(): void
    {
        $this->postJson(self::RESEND, ['email' => 'a@example.com'])->assertStatus(202);

        $this->postJson(self::RESEND, ['email' => 'a@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }
}