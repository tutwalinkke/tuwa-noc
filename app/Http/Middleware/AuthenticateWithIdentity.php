<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $identityUrl = config('services.identity.url');

        $response = Http::withToken($token)
            ->timeout(5)
            ->get($identityUrl . '/me');

        if (! $response->successful()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $identity = $response->json();

        $request->attributes->set('identity_user', $identity['user']);
        $request->attributes->set('identity_roles', $identity['roles']);
        $request->attributes->set('identity_permissions', $identity['permissions'] ?? []);

        return $next($request);
    }
}
