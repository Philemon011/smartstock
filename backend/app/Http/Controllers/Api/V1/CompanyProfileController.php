<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyLogoRequest;
use App\Http\Requests\CompanyProfileRequest;
use App\Http\Resources\CompanyProfileResource;
use App\Models\Tenant;
use App\Services\CompanyProfileService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyProfileController extends Controller
{
    public function __construct(private readonly CompanyProfileService $profile)
    {
    }

    public function show(): CompanyProfileResource
    {
        $tenant = app(CurrentTenant::class)->tenant();

        Gate::authorize('view', $tenant);

        return CompanyProfileResource::make($tenant);
    }

    public function update(CompanyProfileRequest $request): CompanyProfileResource
    {
        return CompanyProfileResource::make(
            $this->profile->update(app(CurrentTenant::class)->tenant(), $request->validated()),
        );
    }

    public function storeLogo(CompanyLogoRequest $request): CompanyProfileResource
    {
        return CompanyProfileResource::make(
            $this->profile->storeLogo(app(CurrentTenant::class)->tenant(), $request->file('logo')),
        );
    }

    public function destroyLogo(): CompanyProfileResource
    {
        $tenant = app(CurrentTenant::class)->tenant();

        Gate::authorize('update', $tenant);

        return CompanyProfileResource::make($this->profile->removeLogo($tenant));
    }

    /**
     * Lien signé : l'accès est contrôlé par la signature, pas par la session.
     */
    public function logo(string $tenant): StreamedResponse
    {
        $record = Tenant::query()->find($tenant);

        abort_if($record === null || $record->logo_path === null, 404);

        $disk = Storage::disk(config('filesystems.files_disk'));

        abort_unless($disk->exists($record->logo_path), 404);

        return $disk->response($record->logo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}