<?php

namespace Tests\Feature;

use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CountriesTest extends TestCase
{
    public function test_it_lists_countries_and_currencies(): void
    {
        $response = $this->getJson('/api/v1/meta/countries');

        $response->assertOk()->assertJsonStructure([
            'countries' => [['code', 'name', 'currency']],
            'currencies' => [['code', 'name']],
        ]);

        $benin = collect($response->json('countries'))->firstWhere('code', 'BJ');

        $this->assertSame(
            ['code' => 'BJ', 'name' => 'Bénin', 'currency' => 'XOF'],
            $benin,
        );
    }

    public function test_every_listed_country_and_currency_is_accepted_by_registration(): void
    {
        $data = $this->getJson('/api/v1/meta/countries')->json();
        $rules = (new RegisterRequest())->rules();
        $currencyCodes = array_column($data['currencies'], 'code');

        foreach ($data['countries'] as $country) {
            $this->assertContains($country['currency'], $currencyCodes);

            $validator = Validator::make(
                ['country' => $country['code'], 'currency' => $country['currency']],
                ['country' => $rules['country'], 'currency' => $rules['currency']],
            );

            $this->assertTrue(
                $validator->passes(),
                "Pays refusé par l'inscription : {$country['code']}",
            );
        }

        foreach ($currencyCodes as $code) {
            $this->assertTrue(
                Validator::make(['currency' => $code], ['currency' => $rules['currency']])->passes(),
                "Devise refusée par l'inscription : {$code}",
            );
        }
    }
}