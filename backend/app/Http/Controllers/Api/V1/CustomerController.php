<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\SaleService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly SaleService $sales,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $search = trim((string) $request->query('search', ''));
        $like = addcslashes($search, '%_\\');

        $perPage = min(
            max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $customers = Customer::query()
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
                        ->when($request->boolean('has_balance'), fn ($query) => $query->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('sales')
                    ->whereColumn('sales.customer_id', 'customers.id')
                    ->where('sales.status', 'validated')
                    ->whereColumn('sales.total', '>', 'sales.amount_paid'),
            ))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name_key', 'like', '%'.addcslashes(Customer::keyFor($search), '%_\\').'%')
                    ->orWhere('phone', 'like', "%{$like}%")
                    ->orWhere('email', 'ilike', "%{$like}%")
                    ->orWhere('city', 'ilike', "%{$like}%"),
            ))
            ->orderBy('name_key')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $balances = $this->sales->balances($customers->getCollection()->pluck('id')->all());

        $customers->getCollection()->each(
            fn (Customer $customer) => $customer->setAttribute('balance_due', $balances[$customer->id]),
        );

        return CustomerResource::collection($customers)->additional([
            'meta' => [
                'can' => [
                    'create' => app(CurrentTenant::class)->tenant()->isWritable()
                        && Gate::allows('create', Customer::class),
                ],
            ],
        ]);
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return CustomerResource::make($this->withBalance($customer));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated());

        return CustomerResource::make($this->withBalance($customer))->response()->setStatusCode(201);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->withBalance($this->customers->update($customer, $request->validated())));
    }

    public function destroy(Customer $customer): Response
    {
        Gate::authorize('delete', $customer);

        $this->customers->delete($customer);

        return response()->noContent();
    }

    public function activate(Customer $customer): CustomerResource
    {
        Gate::authorize('activate', $customer);

        return CustomerResource::make($this->withBalance($this->customers->activate($customer)));
    }

    public function deactivate(Customer $customer): CustomerResource
    {
        Gate::authorize('deactivate', $customer);

        return CustomerResource::make($this->withBalance($this->customers->deactivate($customer)));
    }

    private function withBalance(Customer $customer): Customer
    {
        $customer->setAttribute('balance_due', $this->sales->balances([$customer->id])[$customer->id]);

        return $customer;
    }
}