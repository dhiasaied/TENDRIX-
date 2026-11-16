<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Transport-level protections for every API response, plus the sign-up rate limit
 * (applied before validation so failed attempts count too).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactory $registrationLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the router (32) so the route is known, before the firewall and API Platform.
            KernelEvents::REQUEST => ['limitRegistration', 20],
            KernelEvents::RESPONSE => ['addHeaders', -100],
        ];
    }

    public function limitRegistration(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || '/api/auth/register' !== $request->getPathInfo()) {
            return;
        }

        $limit = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new JsonResponse(
                ['title' => 'Too Many Requests', 'detail' => 'Too many sign-up attempts. Please try again later.', 'status' => 429],
                429,
                ['Retry-After' => (string) $retryAfter, 'Content-Type' => 'application/problem+json'],
            ));
        }
    }

    public function addHeaders(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        // API payloads are personal and tenant-scoped: never store them in shared or browser caches.
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // JSON must never be rendered as a document; the HTML API docs keep their own policy.
        if (!str_contains((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
    }
}
