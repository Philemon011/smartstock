<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', app(CurrentTenant::class)->tenant());

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'bail',
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'Choisissez une image pour le logo.',
            'logo.uploaded' => "Le logo n'a pas pu être envoyé : il dépasse la taille autorisée (2 Mo).",
            'logo.file' => 'Le logo doit être une image JPG, PNG ou WebP.',
            'logo.image' => 'Le logo doit être une image JPG, PNG ou WebP.',
            'logo.mimes' => 'Le logo doit être une image JPG, PNG ou WebP.',
            'logo.max' => 'Le logo ne peut pas dépasser 2 Mo.',
            'logo.dimensions' => 'Le logo doit mesurer entre 64 × 64 et 4000 × 4000 pixels.',
        ];
    }
}