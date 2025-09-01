<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;


class UpdateLastActivity
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            // Only update if last update was more than 1 minute ago to avoid too many database updates
            $user = Auth::user();
            if (!$user->last_activity_at || $user->last_activity_at->lt(now()->subMinute())) {
                $user->update([
                    'last_activity_at' => now()
                ]);
            }
        }

        return $next($request);
    }
}
