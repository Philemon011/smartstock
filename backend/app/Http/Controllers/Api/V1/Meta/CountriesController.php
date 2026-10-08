<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CountriesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $countries = collect(config('tenants.countries'))
            ->map(fn (array $country, string $code): array => [
                'code' => $code,
                'name' => $country['name'],
                'currency' => $country['currency'],
            ])
            ->sortBy(fn (array $country): string => Str::ascii($country['name']))
            ->values();

        $currencies = collect(config('tenants.currencies'))
            ->map(fn (string $name, string $code): array => [
                'code' => $code,
                'name' => $name,
            ])
            ->sortBy(fn (array $currency): string => Str::ascii($currency['name']))
            ->values();

        return response()
            ->json([
                'countries' => $countries,
                'currencies' => $currencies,
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }
}