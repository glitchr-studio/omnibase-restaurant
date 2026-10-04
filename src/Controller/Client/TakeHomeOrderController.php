<?php

namespace Base\Restaurant\Controller\Client;

use Base\Entity\User;
use Base\Marketplace\Controller\Client\QuickOrderController;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Model\TakeHomeOrder;
use Base\Restaurant\Service\TakeHomeOrders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ordering at the take-home shop, without an account: the form of /traiteur
 * posts here, the payment follows (omnibase/marketplace's quick order), and
 * the customer ends on the page that follows the order (/suivi/{token}).
 * Loaded only where the marketplace has its quick order.
 */
class TakeHomeOrderController extends AbstractController
{
    public function __construct(
        private readonly TakeHomeOrders $orders,
        private readonly TranslatorInterface $translator,
        #[Autowire('%restaurant.takehome.enabled%')] private readonly bool $enabled = true,
    ) {
    }

    #[Route('/traiteur/commander', name: 'restaurant_takehome_order', methods: ['POST'])]
    public function order(Request $request, #[MapRequestPayload] TakeHomeOrder $order): Response
    {
        if (!$this->enabled || !$this->orders->isOpen()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('restaurant_takehome', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        // The trap was filled: sent back to the shop, nothing made.
        if ($order->isRobot()) {
            return $this->redirectToRoute('restaurant_takehome');
        }

        try {
            $user = $this->getUser();
            $placed = $this->orders->place($order, $user instanceof User ? $user : null);
        } catch (RestaurantException $e) {
            return $this->refuse($request, $e->getMessage(), $e->parameters);
        }

        // The buyer back from a provider is recognised by the orders of their session (the marketplace's quick order).
        $session = $request->getSession();
        $session->set(QuickOrderController::SESSION, array_merge((array) $session->get(QuickOrderController::SESSION, []), [$placed['payment']->order->getId()]));

        $result = $placed['payment']->result;
        if ($result->isRedirect()) {
            return $this->redirect($result->redirectUrl);
        }
        if ($result->isRefused()) {
            return $this->refuse($request, '@marketplace.quick.error.refused');
        }

        return $this->redirectToRoute('restaurant_tracking', ['token' => $placed['pickup']->getToken()]);
    }

    /** @param array<string, string|int|float> $parameters */
    private function refuse(Request $request, string $key, array $parameters = []): Response
    {
        $this->addFlash('error', str_starts_with($key, '@') ? $this->translator->trans($key, $parameters) : $this->translator->trans($key, $parameters, 'restaurant'));
        // What was typed comes back in the form.
        $posted = $request->request->all();
        unset($posted['_token'], $posted['website']);
        $request->getSession()->set(TakeHomeController::POSTED, $posted);

        return $this->redirect($this->generateUrl('restaurant_takehome').'#commander');
    }
}
