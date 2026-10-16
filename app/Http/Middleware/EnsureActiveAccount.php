<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->account_status === 'active') {
            $passwordUpdateRequired = (bool) $user->force_password_change
                || (! $user->password_expiry_exempt && $user->password_expires_at !== null && now()->greaterThanOrEqualTo($user->password_expires_at));

            if ($passwordUpdateRequired) {
                $refererPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: '';
                $isSecurityRequest = $request->routeIs('security.edit', 'password.confirm', 'logout')
                    || str_starts_with(ltrim($refererPath, '/'), 'settings/security')
                    || str_starts_with(ltrim($request->path(), '/'), 'settings/security');

                if (! $isSecurityRequest) {
                    return redirect()->route('security.edit')->with('status', 'Your temporary or expired password must be changed before continuing.');
                }
            }

            return $next($request);
        }

        if ($user) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('login')
            ->with('status', 'Your account is inactive. Please contact an administrator.');
    }
}