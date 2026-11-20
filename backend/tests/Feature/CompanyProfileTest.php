<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use InteractsWithTenants;

    private const URL = '/api/v1/company-profile';

    private const LOGO = '/api/v1/company-profile/logo';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Boutique Étoile',
            'phone' => '+229 01 02 03 04',
            'email' => 'contact@etoile.bj',
            'address' => 'Rue 12, Haie Vive',
            'city' => 'Cotonou',
            'website' => 'https://www.etoile.bj',
            'tax_id' => '3201234567890',
            'registration_number' => 'RB/COT/21 B 1234',
            'legal_mentions' => "SARL au capital de 1 000 000 FCFA\nSiège : Cotonou",
            'receipt_footer' => 'Merci et à bientôt !',
        ], $overrides);
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('filesystems.files_disk'));
    }

    // ----------------------------------------------------- Droits, isolation

    public function test_every_role_can_read_the_profile_but_only_owners_and_admins_can_edit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.name', $tenant->name)
            ->assertJsonPath('data.country', $tenant->country)
            ->assertJsonPath('data.currency', $tenant->currency)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.receipt_footer', null)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.can.update', true);

        foreach ([TenantRole::Seller, TenantRole::StockManager, TenantRole::Viewer] as $role) {
            $this->asMember($this->addMember($tenant, $role), $tenant);

            $this->getJson(self::URL)->assertOk()->assertJsonPath('data.can.update', false);
            $this->putJson(self::URL, $this->payload())
                ->assertForbidden()
                ->assertJsonPath('message', "Seuls le propriétaire et les administrateurs peuvent modifier le profil de l'entreprise.");
        }

        $this->asMember($this->addMember($tenant, TenantRole::Admin), $tenant);

        $this->putJson(self::URL, $this->payload())->assertOk()->assertJsonPath('data.can.update', true);
    }

    public function test_each_company_only_sees_and_edits_its_own_profile(): void
    {
        [$tenantA, $ownerA] = $this->createTenant('a');
        [$tenantB, $ownerB] = $this->createTenant('b');

        $this->asMember($ownerB, $tenantB);
        $this->putJson(self::URL, $this->payload(['name' => 'Entreprise B']))->assertOk();

        $this->asMember($ownerA, $tenantA);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.id', $tenantA->id)
            ->assertJsonPath('data.name', $tenantA->name)
            ->assertJsonPath('data.email', null);
    }

    public function test_the_profile_requires_a_session_and_a_writable_company(): void
    {
        $this->getJson(self::URL)->assertStatus(401);

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $tenant->forceFill(['status' => 'expired'])->save();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.can.update', false);
        $this->putJson(self::URL, $this->payload())->assertForbidden();
    }

    // ---------------------------------------------------------------- Édition

    public function test_the_profile_is_saved_and_normalized(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->putJson(self::URL, $this->payload([
            'name' => '  Boutique    Étoile ',
            'email' => '  Contact@ETOILE.bj ',
            'website' => 'www.etoile.bj',
            'city' => ' Cotonou ',
            'legal_mentions' => "  Ligne 1  \r\n\r\n\r\n\r\nLigne 2  ",
        ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Boutique Étoile')
            ->assertJsonPath('data.email', 'contact@etoile.bj')
            ->assertJsonPath('data.website', 'https://www.etoile.bj')
            ->assertJsonPath('data.city', 'Cotonou')
            ->assertJsonPath('data.legal_mentions', "Ligne 1\n\nLigne 2");

        $this->getJson(self::URL)
            ->assertJsonPath('data.name', 'Boutique Étoile')
            ->assertJsonPath('data.tax_id', '3201234567890')
            ->assertJsonPath('data.registration_number', 'RB/COT/21 B 1234')
            ->assertJsonPath('data.receipt_footer', 'Merci et à bientôt !');

        $this->putJson(self::URL, $this->payload(['website' => 'http://etoile.bj']))
            ->assertJsonPath('data.website', 'http://etoile.bj');
    }

    public function test_blank_fields_are_cleared(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->putJson(self::URL, $this->payload())->assertOk();

        $this->putJson(self::URL, [
            'name' => 'Boutique Étoile',
            'phone' => '',
            'email' => '   ',
            'website' => '',
            'legal_mentions' => '',
            'receipt_footer' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.website', null)
            ->assertJsonPath('data.legal_mentions', null)
            ->assertJsonPath('data.receipt_footer', null)
            ->assertJsonPath('data.city', 'Cotonou');
    }

    public function test_country_currency_timezone_and_status_cannot_be_changed(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $country = $tenant->country;
        $currency = $tenant->currency;
        $timezone = $tenant->timezone;

        $this->putJson(self::URL, $this->payload([
            'country' => $country === 'FR' ? 'BJ' : 'FR',
            'currency' => $currency === 'EUR' ? 'XOF' : 'EUR',
            'timezone' => 'Asia/Tokyo',
            'status' => 'active',
        ]))->assertOk();

        $fresh = $tenant->fresh();

        $this->assertSame($country, $fresh->country);
        $this->assertSame($currency, $fresh->currency);
        $this->assertSame($timezone, $fresh->timezone);
        $this->assertSame($tenant->status, $fresh->status);
    }

    public function test_validation_messages_are_explicit(): void
    {
        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $this->putJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => "Indiquez le nom de l'entreprise."]);

        $this->putJson(self::URL, $this->payload(['name' => 'A']))
            ->assertJsonValidationErrors(['name' => "Le nom de l'entreprise doit contenir au moins 2 caractères."]);

        $this->putJson(self::URL, $this->payload(['phone' => 'abc']))
            ->assertJsonValidationErrors(['phone' => "Ce numéro de téléphone n'est pas valide. Exemple : +229 01 02 03 04"]);

        $this->putJson(self::URL, $this->payload(['email' => 'pas-un-email']))
            ->assertJsonValidationErrors(['email' => "Cette adresse email n'est pas valide. Exemple : contact@entreprise.com"]);

        $this->putJson(self::URL, $this->payload(['website' => 'pas un site']))
            ->assertJsonValidationErrors(['website' => "Ce site web n'est pas valide. Exemple : https://www.entreprise.com"]);

        $this->putJson(self::URL, $this->payload(['tax_id' => str_repeat('1', 51)]))
            ->assertJsonValidationErrors(['tax_id' => "Le numéro d'identification fiscale ne peut pas dépasser 50 caractères."]);

        $this->putJson(self::URL, $this->payload(['legal_mentions' => str_repeat('a', 501)]))
            ->assertJsonValidationErrors(['legal_mentions' => 'Les mentions légales ne peuvent pas dépasser 500 caractères.']);

        $this->putJson(self::URL, $this->payload(['receipt_footer' => str_repeat('a', 201)]))
            ->assertJsonValidationErrors(['receipt_footer' => 'Le message de fin de reçu ne peut pas dépasser 200 caractères.']);
    }

    // ------------------------------------------------------------------ Logo

    public function test_the_logo_can_be_uploaded_served_replaced_and_removed(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $response = $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->image('logo.png', 400, 400)])
            ->assertOk();

        $url = $response->json('data.logo_url');
        $first = $tenant->fresh()->logo_path;

        $this->assertNotNull($url);
        $this->assertStringStartsWith("tenants/{$tenant->id}/logo/", $first);
        $this->disk()->assertExists($first);

        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=falsifie', $url))->assertForbidden();

        $replaced = $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->image('nouveau.jpg', 300, 300)])
            ->assertOk();

        $second = $tenant->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        $this->assertNotSame($url, $replaced->json('data.logo_url'));
        $this->disk()->assertMissing($first);
        $this->disk()->assertExists($second);

        $this->deleteJson(self::LOGO)->assertOk()->assertJsonPath('data.logo_url', null);

        $this->assertNull($tenant->fresh()->logo_path);
        $this->disk()->assertMissing($second);
    }

    public function test_the_logo_must_be_a_reasonable_image(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);

        $message = 'Le logo doit être une image JPG, PNG ou WebP.';

        $this->postJson(self::LOGO, [])
            ->assertStatus(422)->assertJsonValidationErrors(['logo' => 'Choisissez une image pour le logo.']);

        $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')])
            ->assertJsonValidationErrors(['logo' => $message]);

        $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertJsonValidationErrors(['logo' => $message]);

        $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->image('logo.png', 400, 400)->size(3000)])
            ->assertJsonValidationErrors(['logo' => 'Le logo ne peut pas dépasser 2 Mo.']);

        $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->image('petit.png', 32, 32)])
            ->assertJsonValidationErrors(['logo' => 'Le logo doit mesurer entre 64 × 64 et 4000 × 4000 pixels.']);

        $this->assertNull($tenant->fresh()->logo_path);
    }

    public function test_only_owners_and_admins_can_change_the_logo(): void
    {
        Storage::fake(config('filesystems.files_disk'));

        [$tenant, $owner] = $this->createTenant();
        $this->asMember($owner, $tenant);
        $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->image('logo.png', 400, 400)])->assertOk();

        $this->asMember($this->addMember($tenant, TenantRole::Seller), $tenant);

        $this->getJson(self::URL)->assertOk();
        $this->postJson(self::LOGO, ['logo' => UploadedFile::fake()->image('autre.png', 400, 400)])->assertForbidden();
        $this->deleteJson(self::LOGO)->assertForbidden();

        $this->assertNotNull($tenant->fresh()->logo_path);
    }

    public function test_a_signed_link_without_a_logo_is_not_found(): void
    {
        [$tenant] = $this->createTenant();

        $this->get(URL::signedRoute('tenants.logo', ['tenant' => $tenant->id]))->assertNotFound();
        $this->get(route('tenants.logo', ['tenant' => $tenant->id]))->assertForbidden();
    }
}