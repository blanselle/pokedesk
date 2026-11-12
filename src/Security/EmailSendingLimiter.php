<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Caps the number of emails one client can trigger (registration, resend, password reset). */
final class EmailSendingLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.email_sending')]
        private readonly RateLimiterFactoryInterface $factory,
    ) {
    }

    public function consume(Request $request): void
    {
        $limit = $this->factory->create($request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Trop de demandes, veuillez réessayer plus tard.');
        }
    }
}
