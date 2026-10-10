<?php

namespace App\Modules\Financial\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class FinancialSecurityAudit
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            if ($response->getStatusCode() === 403) {
                $this->record($request);
            }

            return $response;
        } catch (Throwable $exception) {
            if ($exception instanceof AuthorizationException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403)) {
                $this->record($request);
            }
            throw $exception;
        }
    }

    private function record(Request $request): void
    {
        activity('financial-security')->causedBy($request->user())->withProperties([
            'method' => $request->method(), 'path' => $request->path(), 'action' => $request->route()?->getActionName(),
        ])->log('financial_access_denied');
    }
}
