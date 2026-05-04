<?php

use App\Exceptions\RuleResultException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Same-origin fetch() to /api/* with session cookies (e.g. location suggestions) must see the web user.
        $middleware->statefulApi();
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'subscription.feature' => \App\Http\Middleware\EnsureSubscriptionFeature::class,
        ]);
        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\VerifyCsrfToken::class,
        ]);
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\SetLocaleFromQuery::class,
            \App\Http\Middleware\UpdateUserLastSeen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (RuleResultException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(array_merge(['success' => false], $e->result->toArray()), 422);
            }

            return back()->with('error', $e->result->message)->with('rule_action', $e->result->action ?? []);
        });
    })->create();
