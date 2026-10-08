<?php

namespace App\Http\DeviceApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every failure on /api/v1/device/* in the documented error
 * envelope with stable codes and retry semantics.
 */
final class DeviceApiExceptionRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/device', 'api/v1/device/*')) {
            return null;
        }

        [$code, $message, $details, $headers] = self::classify($exception);

        $request->attributes->set('error_code', $code->value);

        return DeviceApiResponse::error($request, $code, $message, $details, $headers);
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<string, mixed>, 3: array<string, string>}
     */
    private static function classify(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof DeviceApiException => [$exception->errorCode, $exception->getMessage(), $exception->details, $exception->headers],
            $exception instanceof AuthenticationException => [ErrorCode::InvalidCredentials, 'Missing, invalid, expired, or revoked device credential.', [], []],
            $exception instanceof AuthorizationException => [ErrorCode::ForbiddenAbility, 'This credential is not permitted to perform this action.', [], []],
            $exception instanceof ValidationException => [ErrorCode::ValidationFailed, 'The request failed validation.', ['errors' => $exception->errors()], []],
            $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => [ErrorCode::NotFound, 'The requested resource does not exist or is not accessible to this device.', [], []],
            $exception instanceof MethodNotAllowedHttpException => [ErrorCode::NotFound, 'No such device API route.', [], []],
            $exception instanceof ThrottleRequestsException => [ErrorCode::RateLimited, 'Too many requests; retry after the indicated delay.', [], self::retryHeaders($exception)],
            $exception instanceof QueryException => [ErrorCode::ServiceUnavailable, 'Temporary storage failure; retry with backoff.', [], ['Retry-After' => '5']],
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 503 => [ErrorCode::ServiceUnavailable, 'Service temporarily unavailable; retry with backoff.', [], ['Retry-After' => '30']],
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 413 => [ErrorCode::PayloadTooLarge, 'Request body too large.', [], []],
            default => [ErrorCode::InternalError, 'Unexpected server error; retry with backoff.', [], []],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function retryHeaders(ThrottleRequestsException $exception): array
    {
        $headers = array_map('strval', $exception->getHeaders());

        $headers['Retry-After'] ??= '60';

        return $headers;
    }
}
