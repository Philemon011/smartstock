<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CollectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('collectAny', Sale::class);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => $this->clean($this->input('amount')),
            'reference' => $this->clean($this->input('reference')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => [
                'bail',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $decimals = Money::decimals(app(CurrentTenant::class)->tenant()->currency);
                    $pattern = $decimals > 0 ? '/^\d{1,13}(\.\d{1,'.$decimals.'})?$/' : '/^\d{1,13}$/';

                    if (! is_string($value) || ! preg_match($pattern, $value)) {
                        $fail($decimals > 0
                            ? "Le montant est invalide : saisissez un montant avec au plus {$decimals} décimales."
                            : 'Le montant est invalide : saisissez un montant entier, sans décimales.');
                    }
                },
            ],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.required' => 'Choisissez le mode de paiement.',
            'method.enum' => "Ce mode de paiement n'est pas valide.",
            'amount.required' => 'Indiquez le montant du paiement.',
            'reference.max' => 'La référence ne peut pas dépasser 100 caractères.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $decimals = Money::decimals(app(CurrentTenant::class)->tenant()->currency);

            if (bccomp((string) $this->input('amount'), '0', $decimals) <= 0) {
                $validator->errors()->add('amount', 'Le montant du paiement doit être supérieur à zéro.');
            }
        }];
    }

    private function clean(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}