<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionAdmin = $request->session()->get('admin_user');
        $adminId = is_array($sessionAdmin) ? ($sessionAdmin['id'] ?? null) : null;

        if (! is_numeric($adminId) || ! AdminUser::whereKey((int) $adminId)->exists()) {
            $request->session()->forget('admin_user');

            return redirect()->route('login')->with('error', 'Veuillez vous connecter pour accéder à cette page.');
        }

        return $next($request);
    }
}
