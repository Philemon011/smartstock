<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.writable' => EnsureTenantIsWritable::class,
        ]);

        // L'entreprise doit être connue avant que les modèles des routes soient résolus.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureTenantIsWritable::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = max(1, (int) ($e->getHeaders()['Retry-After'] ?? 60));
            $minutes = (int) ceil($retryAfter / 60);

            $wait = $retryAfter < 60
                ? $retryAfter.' '.($retryAfter > 1 ? 'secondes' : 'seconde')
                : $minutes.' '.($minutes > 1 ? 'minutes' : 'minute');

            return response()->json([
                'message' => "Trop de tentatives. Réessayez dans {$wait}.",
                'code' => 'too_many_requests',
                'retry_after' => $retryAfter,
            ], 429, $e->getHeaders());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Votre session a expiré. Reconnectez-vous pour continuer.',
                'code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 409);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $message = trim($e->getMessage());

            $error = match ($status) {
                403 => [
                    'code' => 'forbidden',
                    // Les refus des règles d'accès portent déjà un message en français.
                    'message' => $message !== '' && $message !== 'This action is unauthorized.'
                        ? $message
                        : "Vous n'avez pas l'autorisation d'effectuer cette action.",
                ],
                404 => ['code' => 'not_found', 'message' => 'Cette ressource est introuvable.'],
                405 => ['code' => 'method_not_allowed', 'message' => "Cette action n'est pas autorisée."],
                419 => ['code' => 'session_expired', 'message' => 'Votre session a expiré. Rechargez la page, puis réessayez.'],
                default => null,
            };

            return $error === null ? null : response()->json($error, $status);
        });
    })->create();