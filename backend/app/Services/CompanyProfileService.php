<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CompanyProfileService
{
    /**
     * Les seuls champs modifiables ici : le pays, la devise, le fuseau et le statut ne le sont jamais.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'name',
        'phone',
        'email',
        'address',
        'city',
        'website',
        'tax_id',
        'registration_number',
        'legal_mentions',
        'receipt_footer',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data): Tenant {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill(Arr::only($data, self::FIELDS))->save();

            return $locked->refresh();
        });
    }

    public function storeLogo(Tenant $tenant, UploadedFile $file): Tenant
    {
        $disk = Storage::disk(config('filesystems.files_disk'));
        $previous = $tenant->logo_path;

        $path = $disk->putFileAs(
            "tenants/{$tenant->id}/logo",
            $file,
            Str::lower((string) Str::ulid()).'.'.$file->extension(),
        );

        try {
            $tenant->forceFill(['logo_path' => $path])->save();
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $tenant->refresh();
    }

    public function removeLogo(Tenant $tenant): Tenant
    {
        $path = $tenant->logo_path;

        if ($path !== null) {
            $tenant->forceFill(['logo_path' => null])->save();
            Storage::disk(config('filesystems.files_disk'))->delete($path);
        }

        return $tenant->refresh();
    }
}