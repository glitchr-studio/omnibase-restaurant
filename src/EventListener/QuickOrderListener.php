<?php

namespace Base\Restaurant\EventListener;

use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Event\QuickOrderDoneEvent;
use Base\Marketplace\Service\Pickups;
use Base\Restaurant\Repository\SessionRepository;
use Base\Restaurant\Service\TablePayments;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What omnibase/marketplace's quick orders are to the restaurant: an order
 * paid settles the table's bill it pays (when the payment came back from a
 * provider: a card), and the page a quick order ends on is the restaurant's
 * own - the table's page for a bill, the page that follows a take-home order.
 */
final class QuickOrderListener
{
    public function __construct(
        private readonly TablePayments $payments,
        private readonly SessionRepository $sessions,
        private readonly Pickups $pickups,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    #[AsEventListener]
    public function onPaid(OrderPaidEvent $event): void
    {
        $this->payments->settle($event->order);
    }

    #[AsEventListener]
    public function onDone(QuickOrderDoneEvent $event): void
    {
        $reference = (string) $event->order->getReference();
        if ('' !== $reference && $session = $this->sessions->findOneBy(['orderReference' => $reference])) {
            $event->setResponse(new RedirectResponse($this->router->generate('restaurant_table', ['token' => $session->getTable()->getToken(), 'paye' => $event->order->isPaid() ? 1 : 0])));

            return;
        }
        if ($pickup = $this->pickups->of($event->order)) {
            $event->setResponse(new RedirectResponse($this->router->generate('restaurant_tracking', ['token' => $pickup->getToken()])));
        }
    }
}
