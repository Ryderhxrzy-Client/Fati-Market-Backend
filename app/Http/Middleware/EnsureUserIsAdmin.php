<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every admin-only action: setting the acquisition price, verifying
 * turnover, setting the public price, publishing, verifying GCash proof, and
 * completing or cancelling transactions.
 *
 * The role is read from the authenticated user behind the Sanctum token, never
 * from anything the client sends.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Admin access required'], 403);
        }

        return $next($request);
    }
}
