<?php

namespace Base\Restaurant\Controller\Client;

use Base\Entity\User;
use Base\Marketplace\Controller\Client\QuickOrderController;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Form\TakeHomeOrderType;
use Base\Restaurant\Model\TakeHomeOrder;
use Base\Restaurant\Service\TakeHomeOrders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ordering at the take-home shop, without an account: the form of /traiteur
 * (Form\TakeHomeOrderType, guarded as glitchr/omnibase guards a form) posts here, the payment follows (omnibase/marketplace's quick order), and
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
    public function order(Request $request, FormFactoryInterface $forms): Response
    {
        if (!$this->enabled || !$this->orders->isOpen()) {
            throw $this->createNotFoundException();
        }
        $order = new TakeHomeOrder();
        $form = $forms->createNamed('', TakeHomeOrderType::class, $order)->handleRequest($request);
        if (!$form->isSubmitted()) {
            throw new BadRequestHttpException('No take-home order was sent.');
        }
        if (!$form->isValid()) {
            // The guard's word (a trap, too fast, a captcha not solved), a field's, or the CSRF token's.
            $error = $form->getErrors(true)->current();

            return $this->refuse($request, $error ? $error->getMessage() : '@restaurant.takehome.error.form', [], true);
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

    /**
     * @param array<string, string|int|float> $parameters
     * @param bool                             $said       $key is a message already translated (the form's)
     */
    private function refuse(Request $request, string $key, array $parameters = [], bool $said = false): Response
    {
        $this->addFlash('error', $said ? $key : (str_starts_with($key, '@') ? $this->translator->trans($key, $parameters) : $this->translator->trans($key, $parameters, 'restaurant')));
        // What was typed comes back in the form - not the guard's fields, nor the token.
        $posted = array_filter($request->request->all(), static fn ($value, $name) => !\in_array($name, ['_token', '_csrf_token', 'omniguard-token'], true) && !str_starts_with((string) $name, 'guard_'), \ARRAY_FILTER_USE_BOTH);
        $request->getSession()->set(TakeHomeController::POSTED, $posted);

        return $this->redirect($this->generateUrl('restaurant_takehome').'#commander');
    }
}
