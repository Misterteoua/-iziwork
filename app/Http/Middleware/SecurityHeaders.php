<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Add browser-side protections to every application response.
     *
     * CSP intentionally allows the existing inline scripts and the two
     * current CDN assets; the policy can be tightened when those assets are
     * moved to the application build.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; ".
            "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; ".
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ".
            "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; ".
            "connect-src 'self'; object-src 'none'"
        );

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
