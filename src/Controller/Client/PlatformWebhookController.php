<?php

namespace Base\Restaurant\Controller\Client;

use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Omnifood\Receiver;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where a delivery or booking platform calls (glitchr/omnifood): its
 * signature checked by the platform's package - 401 and nothing done when
 * it does not hold -, the order made a ticket on the pass, the reservation
 * put in the book. Answered 200 once handled; 502 when the platform could
 * not be asked for the order (it retries: the event is not marked as seen).
 * 404 without glitchr/omnifood or for a platform the site does not have.
 */
class PlatformWebhookController extends AbstractController
{
    public function __construct(private readonly ?Receiver $receiver = null, private readonly ?LoggerInterface $logger = null)
    {
    }

    #[Route('/restaurant/plateforme/{name}/webhook', name: 'restaurant_platform_webhook', requirements: ['name' => '[a-z0-9_\-]+'], methods: ['POST'])]
    public function __invoke(Request $request, string $name): JsonResponse
    {
        if (!$this->receiver) {
            throw $this->createNotFoundException();
        }
        try {
            $handled = $this->receiver->receive($name, $request->getContent(), $request->headers->all());
        } catch (\Omnifood\Exception\InvalidSignatureException $e) {
            $this->logger?->warning('restaurant: a webhook refused: '.$e->getMessage());

            return $this->json(['error' => 'signature'], 401);
        } catch (\Omnifood\Exception\InvalidConfigException|\Omnifood\Exception\NotSupportedException $e) {
            $this->logger?->warning('restaurant: a webhook for an unknown platform: '.$e->getMessage());

            return $this->json(['error' => 'platform'], 404);
        } catch (\Omnifood\Exception\OmnifoodException $e) {
            // The platform could not be asked for the order: it retries, and so do we.
            $this->logger?->error('restaurant: a webhook could not be handled: '.$e->getMessage());

            return $this->json(['error' => 'unavailable'], 502);
        }

        return $this->json([
            'ok' => true,
            'ticket' => $handled instanceof Ticket ? $handled->getId() : null,
            'reservation' => $handled instanceof \Base\Restaurant\Entity\Reservation ? $handled->getId() : null,
        ]);
    }
}
