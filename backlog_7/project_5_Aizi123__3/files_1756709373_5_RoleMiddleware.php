<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next,$role): Response
    // {
        
    //     if (!Auth::check()) {
    //         return redirect('/')->with('error', 'You must log in first.');
    //     }

    //     if (Auth::user()->role != $role) {
    //         return redirect('/')->with('error', 'Access Denied.');
    //     }

    //     return $next($request);
    // }

    public function handle(Request $request, Closure $next, $roles): Response
{
    if (!Auth::check()) {
        return redirect('/')->with('error', 'You must log in first.');
    }

    // Split roles using |
    $roleArray = explode('|', $roles);

    if (!in_array((string) Auth::user()->role, $roleArray)) {
        return redirect('/')->with('error', 'Access Denied.');
    }

    return $next($request);
}
}
