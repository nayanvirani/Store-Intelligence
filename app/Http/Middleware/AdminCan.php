<?php

namespace App\Http\Middleware;

use App\Support\AdminRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal Admin: the signed-in team member's role must allow this area (AdminRoles).
 */
class AdminCan
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        abort_unless(AdminRoles::can($request->user(), $area), 403, 'Your admin role doesn\'t include this page.');

        return $next($request);
    }
}
