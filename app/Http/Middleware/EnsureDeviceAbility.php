<?php

namespace App\Http\Middleware;

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Models\DeviceCredential;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $credential = $request->user('device');

        // The auth middleware may run before DeviceApiContext clears a credential
        // cached by an earlier request in the same process; re-resolution can
        // therefore come back empty (revoked/expired) and must yield 401.
        if (! $credential instanceof DeviceCredential) {
            throw new AuthenticationException('Unauthenticated.', ['device']);
        }

        if (! $credential->hasAbility($ability)) {
            throw new DeviceApiException(ErrorCode::ForbiddenAbility, 'This credential does not grant the '.$ability.' ability.');
        }

        return $next($request);
    }
}
