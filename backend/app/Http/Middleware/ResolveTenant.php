<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $memberships = $request->user()->memberships()->active()->with('tenant')->get();
        $requested = trim((string) $request->header('X-Tenant-Id'));

        if ($requested !== '') {
            $membership = $memberships->firstWhere('tenant_id', $requested)
                ?? $this->fail(403, 'tenant_forbidden', "Vous n'avez pas accès à cette entreprise.");
        } elseif ($memberships->count() === 1) {
            $membership = $memberships->first();
        } elseif ($memberships->isEmpty()) {
            $this->fail(403, 'no_active_membership', "Votre accès à une entreprise est suspendu ou n'existe plus.");
        } else {
            $this->fail(400, 'tenant_required', "Choisissez l'entreprise sur laquelle vous souhaitez travailler.");
        }

        app(CurrentTenant::class)->set($membership->tenant, $membership);

        return $next($request);
    }

    private function fail(int $status, string $code, string $message): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], $status));
    }
}