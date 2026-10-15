<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $message = app(CurrentTenant::class)->tenant()->readOnlyMessage();

        if ($message !== null) {
            return response()->json(['message' => $message, 'code' => 'tenant_read_only'], 403);
        }

        return $next($request);
    }
}