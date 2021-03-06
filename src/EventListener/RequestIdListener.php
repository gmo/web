<?php declare(strict_types=1);

namespace Gmo\Web\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Generates a unique ID for each request (if it doesn't exist) and adds it the response headers.
 *
 * The header could also be generated by Nginx/Apache or upstream PHP code.
 *
 * This is a common practice to identify bad requests/responses in logging files/tools.
 *
 * @author Carson Full <carsonfull@gmail.com>
 */
class RequestIdListener implements EventSubscriberInterface
{
    protected const HEADER = 'X-Request-Id';

    public function onRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if (!$request->headers->has(static::HEADER)) {
            $request->headers->set(static::HEADER, bin2hex(random_bytes(16)));
        }
    }

    public function onResponse(FilterResponseEvent $event)
    {
        if (!($id = $event->getRequest()->headers->get(static::HEADER))) {
            return;
        }

        $event->getResponse()->headers->set(static::HEADER, $id);
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1024], // early in-case another listener throws an exception.
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }
}
