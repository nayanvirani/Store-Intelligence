<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStorePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->attributes->get('storeUser');

        if (Permissions::allows($user?->role, $permission)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'You don\'t have permission to perform this action.'], 403);
        }

        return page('forbidden', [], 403)->toResponse($request);
    }
}
