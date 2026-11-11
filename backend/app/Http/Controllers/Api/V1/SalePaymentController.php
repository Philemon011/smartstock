<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CollectPaymentRequest;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SalePaymentController extends Controller
{
    public function __construct(private readonly SalePaymentService $payments)
    {
    }

    public function store(CollectPaymentRequest $request, Sale $sale): SaleResource
    {
        Gate::authorize('collect', $sale);

        return SaleResource::make($this->payments->collect($sale, $request->validated()));
    }

    public function void(Request $request, Sale $sale, SalePayment $payment): SaleResource
    {
        Gate::authorize('voidPayment', $sale);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'reason.required' => 'Indiquez pourquoi vous annulez ce paiement.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
            'reason.max' => 'Le motif ne peut pas dépasser 300 caractères.',
        ]);

        return SaleResource::make($this->payments->void($sale, $payment, trim($data['reason'])));
    }

    public function storeForCustomer(CollectPaymentRequest $request, Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $this->payments->collectForCustomer($customer, $request->validated()),
        ]);
    }
}