<?php

namespace App\Http\Middleware;

use App\Support\Notices;
use App\Support\Spa\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the React admin reuse the app's controllers: for its requests (X-OO-Page), a redirect
 * becomes {redirect, notice} and a page that hasn't moved to React yet becomes {legacy: true},
 * which the client opens with a full page load.
 */
class SpaResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->headers->has(Page::HEADER)) {
            return $response;
        }

        if ($response instanceof RedirectResponse) {
            $target = parse_url($response->getTargetUrl());
            parse_str($target['query'] ?? '', $query);
            $notice = Notices::get($query['notice'] ?? null);
            $query = array_diff_key($query, array_flip(['shop', 'host', 'notice', 'id_token']));
            $sameHost = ! isset($target['host']) || $target['host'] === $request->getHost();

            return response()->json([
                'redirect' => $sameHost ? ($target['path'] ?? '/').($query ? '?'.http_build_query($query) : '') : $response->getTargetUrl(),
                'external' => ! $sameHost,
                'notice' => $notice ? ['message' => $notice[0], 'error' => $notice[1]] : null,
            ]);
        }

        if (($response->original ?? null) instanceof View) {
            return response()->json(['legacy' => true], 200);
        }

        return $response;
    }
}
