<?php

namespace Base\Restaurant\Controller\Client;

use Base\Restaurant\Entity\Table;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Model\Round;
use Base\Restaurant\Repository\DishRepository;
use Base\Restaurant\Repository\TableRepository;
use Base\Restaurant\Service\TableOrders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a table's QR code opens (/t/{token}): the menu in ordering mode on
 * the guests' phones, the table's bill joined (no account), rounds sent to
 * the pass, the waiter called, the bill asked for - and where each round
 * stands. The token is the only key: a poster reprinted with a new token
 * locks the old one out. Each table's phones get so many requests a minute
 * (symfony/rate-limiter, restaurant.table.rate_limit).
 */
class TableController extends AbstractController
{
    public function __construct(
        private readonly TableRepository $tables,
        private readonly DishRepository $dishes,
        private readonly TableOrders $orders,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'restaurant.table_limiter')] private readonly RateLimiterFactory $limiter,
        #[Autowire('%restaurant.layout%')] private readonly string $layout = 'layout1.html.twig',
        #[Autowire('%restaurant.table.ordering%')] private readonly bool $ordering = true,
        #[Autowire('%restaurant.table.pay_online%')] private readonly bool $payOnline = false,
        #[Autowire('%restaurant.pass.poll%')] private readonly int $poll = 6,
    ) {
    }

    #[Route('/t/{token}', name: 'restaurant_table', requirements: ['token' => '[A-Za-z0-9_\-]{16,32}'], methods: ['GET'])]
    public function index(string $token): Response
    {
        $table = $this->table($token);
        // The bill opens with the first round (or call), not with the scan: a code scanned in passing seats nobody.
        $session = $this->ordering ? $this->orders->session($table, false) : null;

        return $this->render('@Restaurant/client/table.html.twig', [
            'layout' => $this->layout,
            'table' => $table,
            'session' => $session,
            'state' => $session ? $this->orders->state($session) : null,
            'sections' => $this->dishes->bySection(),
            'ordering' => $this->ordering,
            'pay_online' => $this->payOnline,
            'poll' => $this->poll,
        ]);
    }

    #[Route('/t/{token}/etat', name: 'restaurant_table_state', requirements: ['token' => '[A-Za-z0-9_\-]{16,32}'], methods: ['GET'])]
    public function state(string $token, \Base\Restaurant\Service\Revision $revision): JsonResponse
    {
        $session = $this->orders->session($this->table($token), false);

        return $this->json(($session ? $this->orders->state($session) : ['session' => null]) + ['revision' => $revision->current()]);
    }

    #[Route('/t/{token}/tournee', name: 'restaurant_table_round', requirements: ['token' => '[A-Za-z0-9_\-]{16,32}'], methods: ['POST'], format: 'json')]
    public function round(Request $request, string $token, #[MapRequestPayload] Round $round): JsonResponse
    {
        $table = $this->limited($request, $token);
        try {
            $ticket = $this->orders->send($this->orders->session($table), $round);
        } catch (RestaurantException $e) {
            return $this->json(['error' => $this->translator->trans($e->getMessage(), [], 'restaurant')], 422);
        }
        $session = $ticket->getSession();

        return $this->json(['ticket' => $ticket->getId(), 'round' => $ticket->getRound()] + $this->orders->state($session), 201);
    }

    #[Route('/t/{token}/appel', name: 'restaurant_table_call', requirements: ['token' => '[A-Za-z0-9_\-]{16,32}'], methods: ['POST'])]
    public function call(Request $request, string $token): JsonResponse
    {
        $session = $this->orders->session($this->limited($request, $token));
        $this->orders->callWaiter($session);

        return $this->json($this->orders->state($session));
    }

    #[Route('/t/{token}/addition', name: 'restaurant_table_bill', requirements: ['token' => '[A-Za-z0-9_\-]{16,32}'], methods: ['POST'])]
    public function bill(Request $request, string $token): JsonResponse
    {
        $session = $this->orders->session($this->limited($request, $token));
        $this->orders->requestBill($session);

        return $this->json($this->orders->state($session));
    }

    private function table(string $token): Table
    {
        $table = $this->tables->findOneByToken($token);
        if (!$table || !$table->isActive()) {
            throw $this->createNotFoundException();
        }

        return $table;
    }

    /** The table, if its phones have not asked too often this minute; only from a script (a JSON request), never a cross-site form. */
    private function limited(Request $request, string $token): Table
    {
        $table = $this->table($token);
        if (!str_contains((string) $request->headers->get('Content-Type'), 'json') && 'XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            throw new UnsupportedMediaTypeHttpException('JSON only.');
        }
        $limit = $this->limiter->create($token.'|'.$request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time());
        }

        return $table;
    }
}
