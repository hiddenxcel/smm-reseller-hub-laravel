<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers that tell a browser what this site is allowed to do.
 *
 * Set here rather than in nginx: nginx on this box is managed by HestiaCP,
 * which rewrites its own vhosts, so a header added there would survive only
 * until the next time the panel touched a domain.
 *
 * No Content-Security-Policy yet. A CSP written without measuring what the
 * page actually loads either blocks the app or is so loose it protects
 * nothing, and this app runs inline Vite scripts in development — that needs
 * a nonce pipeline to do properly rather than a header bolted on.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never render this site inside someone else's iframe. Without it a
        // convincing copy of the login page can be framed and clickjacked.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Stops a browser second-guessing a Content-Type — the trick that
        // turns an uploaded "image" into an executed script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Send the path to our own pages, only the origin to other sites. A
        // reseller following a link out should not leak which customer or
        // order they were looking at.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Features this app never uses. Denying them means a compromised
        // script cannot quietly reach for a camera or a location.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        );

        // HSTS only over https, and only in production: sending it from a
        // local http server would pin the browser to https for localhost and
        // break every other project on the machine.
        if ($request->secure() && app()->isProduction()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
