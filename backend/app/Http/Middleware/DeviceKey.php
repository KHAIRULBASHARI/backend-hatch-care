<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.device.key');
        $providedKey = $request->header('X-Device-Key');

        if (empty($expectedKey) || empty($providedKey) || ! hash_equals($expectedKey, (string) $providedKey)) {
            abort(401, 'Unauthorized device.');
        }

        return $next($request);
    }
}
