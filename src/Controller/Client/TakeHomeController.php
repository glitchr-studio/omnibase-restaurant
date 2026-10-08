<?php

namespace Base\Restaurant\Controller\Client;

use Base\Marketplace\Repository\Order\PickupRepository;
use Base\Restaurant\Repository\TakeHomeRepository;
use Base\Restaurant\Service\ColdChain;
use Base\Restaurant\Service\TakeawayTickets;
use Base\Restaurant\Service\TakeHomeOrders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Base\Restaurant\Form\TakeHomeOrderType;
use Base\Restaurant\Model\TakeHomeOrder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The take-home shop: the fresh dishes to cook at home (/traiteur), a dish
 * and how to cook it (/traiteur/{slug}), and an order followed through its
 * link, without an account (/suivi/{token}). The order itself - without an
 * account either - is TakeHomeOrderController's, where omnibase/marketplace
 * offers its quick order.
 */
class TakeHomeController extends AbstractController
{
    /** Session: the fields of an order that was refused, for the form to show again. */
    public const POSTED = 'restaurant/takehome_posted';

    public function __construct(
        private readonly TakeHomeRepository $dishes,
        private readonly ColdChain $coldChain,
        #[Autowire('%restaurant.layout%')] private readonly string $layout = 'layout1.html.twig',
        #[Autowire('%restaurant.takehome.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%restaurant.takehome.max_quantity%')] private readonly int $maxQuantity = 20,
        private readonly ?TakeHomeOrders $orders = null,
        private readonly ?FormFactoryInterface $forms = null,
    ) {
    }

    #[Route('/traiteur', name: 'restaurant_takehome', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->open();
        $dishes = $this->dishes->shop();
        // Which ways the shop's dishes may leave, and their days: the form's choices. A parcel is
        // offered as soon as some dishes travel; whether a basket may go is said when it is ordered.
        $lines = array_values(array_map(fn ($dish) => ['product' => $dish, 'quantity' => 1], array_filter($dishes, fn ($dish) => $dish->isAvailable())));
        $options = $lines ? $this->coldChain->options($lines) : [];
        $travelling = $this->coldChain->travelling($lines);
        if ($travelling && !$options['shipping']->available) {
            $options['shipping'] = $this->coldChain->options($travelling)['shipping'];
        }

        return $this->render('@Restaurant/client/takehome.html.twig', [
            'layout' => $this->layout,
            'dishes' => $dishes,
            'ordering' => null !== $this->orders && $this->orders->isOpen(),
            'pickup_days' => self::calendar($this->coldChain->days()),
            'shipping_days' => self::calendar($this->coldChain->shippingDays()),
            'options' => $options,
            'slots' => $this->orders?->slots() ?? [],
            'max_quantity' => $this->maxQuantity,
            // What an order refused had typed (TakeHomeOrderController), given back once.
            'posted' => $request->hasPreviousSession() ? (array) $request->getSession()->remove(self::POSTED) : [],
            // The order's form: the page writes its fields, the form gives the guard's (trap, stamp, captcha) and the token.
            'form' => null !== $this->orders && $this->orders->isOpen() && null !== $this->forms ? $this->forms->createNamed('', TakeHomeOrderType::class, new TakeHomeOrder())->createView() : null,
        ]);
    }

    #[Route('/traiteur/{slug}', name: 'restaurant_takehome_dish', requirements: ['slug' => '[a-z0-9][a-z0-9\-]*'], methods: ['GET'], priority: -1)]
    public function show(string $slug): Response
    {
        $this->open();
        $dish = $this->dishes->findOneBySlug($slug);
        if (!$dish || (!$dish->isForSell() && !$dish->isAvailable())) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Restaurant/client/takehome_dish.html.twig', [
            'layout' => $this->layout,
            'dish' => $dish,
            'parcel' => $this->coldChain->options([['product' => $dish, 'quantity' => 1]])['shipping'] ?? null,
        ]);
    }

    #[Route('/suivi/{token}', name: 'restaurant_tracking', requirements: ['token' => '[A-Za-z0-9_\-]{32,43}'], methods: ['GET'])]
    public function track(string $token, PickupRepository $pickups, TakeawayTickets $tickets): Response
    {
        $found = $pickups->byToken($token);
        if (!$found) {
            throw $this->createNotFoundException();
        }
        $open = false;
        $rows = [];
        foreach ($found as $pickup) {
            $open = $open || $pickup->isOpen();
            $rows[] = ['pickup' => $pickup, 'ticket' => $tickets->of($pickup->getOrder()), 'use_by' => $this->coldChain->useBy(array_map(fn ($item) => ['product' => $item->getProduct(), 'quantity' => (int) $item->getQuantity()], array_filter($pickup->getOrder()->getItems()->toArray(), fn ($item) => null !== $item->getProduct())), $pickup->getDay())];
        }

        $response = $this->render('@Restaurant/client/tracking.html.twig', ['layout' => $this->layout, 'rows' => $rows, 'open' => $open]);
        // Behind a secret link: neither indexed nor kept by a shared cache.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();

        return $response;
    }

    /**
     * Days of the shop's calendar, for a template: at noon on PHP's own clock.
     * Twig shows a date in PHP's zone (the visitor's, with omnibase): midnight
     * in Paris read from UTC would be the day before.
     *
     * @param list<\DateTimeImmutable> $days
     *
     * @return list<\DateTimeImmutable>
     */
    private static function calendar(array $days): array
    {
        return array_map(fn (\DateTimeImmutable $day) => new \DateTimeImmutable($day->format('Y-m-d').' 12:00'), $days);
    }

    private function open(): void
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }
    }
}
