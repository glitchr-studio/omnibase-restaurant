<?php

namespace Base\Restaurant\Controller\Client;

use Base\Entity\User;
use Base\Marketplace\Controller\Client\QuickOrderController;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Model\BillPayment;
use Base\Restaurant\Repository\TableRepository;
use Base\Restaurant\Service\TableOrders;
use Base\Restaurant\Service\TablePayments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Payer depuis mon téléphone" (restaurant.table.pay_online): a table's
 * phone pays its bill - an e-mail address for the receipt, then the shop's
 * payment (Service\TablePayments). Answers where to go: the provider's page,
 * or back to the table's, settled. Loaded only where omnibase/marketplace
 * has its quick order.
 */
class TablePaymentController extends AbstractController
{
    public function __construct(
        private readonly TableRepository $tables,
        private readonly TableOrders $orders,
        private readonly TablePayments $payments,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'restaurant.table_limiter')] private readonly RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/t/{token}/payer', name: 'restaurant_table_pay', requirements: ['token' => '[A-Za-z0-9_\-]{16,32}'], methods: ['POST'], format: 'json')]
    public function pay(Request $request, string $token, #[MapRequestPayload] BillPayment $bill): JsonResponse
    {
        $table = $this->tables->findOneByToken($token);
        if (!$table || !$table->isActive() || !$this->payments->isEnabled()) {
            throw $this->createNotFoundException();
        }
        // Only from the page's script (a JSON request), and not too often: as the table's other posts.
        if (!str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            throw new UnsupportedMediaTypeHttpException('JSON only.');
        }
        $limit = $this->limiter->create($token.'|'.$request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time());
        }
        $session = $this->orders->session($table, false);
        if (!$session) {
            return $this->json(['error' => $this->translator->trans('table.error.pay_till', [], 'restaurant')], 422);
        }

        try {
            $user = $this->getUser();
            $payment = $this->payments->pay($session, $bill->email, $user instanceof User ? $user : null);
        } catch (RestaurantException $e) {
            $message = str_starts_with($e->getMessage(), '@') ? $this->translator->trans($e->getMessage(), $e->parameters) : $this->translator->trans($e->getMessage(), $e->parameters, 'restaurant');

            return $this->json(['error' => $message], 422);
        }

        // The buyer back from a provider is recognised by the orders of their session (the marketplace's quick order).
        $http = $request->getSession();
        $http->set(QuickOrderController::SESSION, array_merge((array) $http->get(QuickOrderController::SESSION, []), [$payment->order->getId()]));

        if ($payment->result->isRefused()) {
            return $this->json(['error' => $this->translator->trans('@marketplace.quick.error.refused')], 422);
        }

        return $this->json([
            'paid' => $payment->order->isPaid(),
            'redirect' => $payment->result->isRedirect() ? $payment->result->redirectUrl : null,
            'amount' => (int) $payment->order->getNetPrice(),
            'reference' => (string) $payment->order->getReference(),
        ]);
    }
}
