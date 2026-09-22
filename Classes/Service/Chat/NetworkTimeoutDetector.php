<?php

declare(strict_types=1);

namespace AutoDudes\Cheddi\Service\Chat;

use Psr\Http\Client\NetworkExceptionInterface;

final class NetworkTimeoutDetector
{
    private const CURL_TIMEOUT_ERRNO = 28;

    private const TIMEOUT_EXCEPTIONS = [
        'GuzzleHttp\Exception\ConnectTimeoutException',
        'GuzzleHttp\Exception\NetworkTimeoutException',
    ];

    public static function isTimeout(NetworkExceptionInterface $exception): bool
    {
        foreach (self::TIMEOUT_EXCEPTIONS as $timeoutException) {
            if ($exception instanceof $timeoutException) {
                return true;
            }
        }

        if (!method_exists($exception, 'getHandlerContext')) {
            return false;
        }

        $context = $exception->getHandlerContext();

        return self::CURL_TIMEOUT_ERRNO === (int) ($context['errno'] ?? 0);
    }
}
