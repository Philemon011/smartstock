<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SaleMetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'payment_methods' => array_map(fn (PaymentMethod $method) => [
                'value' => $method->value,
                'label' => $method->label(),
                'is_cash' => $method->isCash(),
            ], PaymentMethod::cases()),
            'payment_statuses' => array_map(fn (PaymentStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ], PaymentStatus::cases()),
        ]);
    }
}