<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminNavigationAccess;
use App\Support\Admin\AdminNavigationCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSectionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin || ! $admin->isAnyAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required',
                ], 403);
            }

            abort(403);
        }

        if ($admin->isSuperAdmin()) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        $section = AdminNavigationCatalog::sectionForRouteName($routeName);

        if ($section === null) {
            return $next($request);
        }

        $capabilities = DB::table('admin_capabilities')
            ->where('admin_id', $admin->id)
            ->first();

        $access = AdminNavigationAccess::accessFor($admin, $capabilities);

        if ((bool) ($access[$section] ?? false)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Admin section access denied',
            ], 403);
        }

        abort(403, 'Admin section access denied.');
    }
}
