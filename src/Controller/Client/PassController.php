<?php

namespace Base\Restaurant\Controller\Client;

use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Order\Session;
use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Omnifood\Platforms;
use Base\Restaurant\Repository\DishRepository;
use Base\Restaurant\Service\Pass;
use Base\Restaurant\Service\Reservations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le passe (/service): the floor's one screen, a page of the site for the
 * staff (restaurant.staff_role). It draws the board (Service\Pass), asks its
 * state every few seconds (glitchr/omnibase's poll controller: "revision"
 * redraws, "latest" rings for a ticket to accept), and takes the staff's
 * taps - each a POST with the pass's token, answered with the new state
 * when asked from the page's script, with the page otherwise.
 */
class PassController extends AbstractController
{
    public function __construct(
        private readonly Pass $pass,
        private readonly Reservations $reservations,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ?Platforms $platforms = null,
        #[Autowire('%restaurant.staff_role%')] private readonly string $staffRole = 'ROLE_STAFF',
        #[Autowire('%restaurant.layout%')] private readonly string $layout = 'layout1.html.twig',
        #[Autowire('%restaurant.pass.poll%')] private readonly int $poll = 6,
    ) {
    }

    #[Route('/service', name: 'restaurant_pass', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->staffRole);

        return $this->render('@Restaurant/client/pass.html.twig', ['layout' => $this->layout, 'poll' => $this->poll, 'platforms' => $this->platformStates()] + $this->pass->board($this->station($request)));
    }

    #[Route('/service/tableau', name: 'restaurant_pass_board', methods: ['GET'])]
    public function board(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->staffRole);

        return $this->render('@Restaurant/client/_pass_board.html.twig', ['platforms' => $this->platformStates()] + $this->pass->board($this->station($request)));
    }

    #[Route('/service/etat', name: 'restaurant_pass_state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        $this->denyAccessUnlessGranted($this->staffRole);

        return $this->json($this->pass->state());
    }

    #[Route('/service/ticket/{id}/{action}', name: 'restaurant_pass_ticket', requirements: ['id' => '\d+', 'action' => 'forward|accept|start|ready|serve|refuse|cancel'], methods: ['POST'])]
    public function ticket(Request $request, Ticket $id, string $action): Response
    {
        return $this->act($request, fn () => $this->pass->move($id, $action, $request->request->get('reason') ?: null));
    }

    #[Route('/service/table/{id}/{action}', name: 'restaurant_pass_table', requirements: ['id' => '\d+', 'action' => 'settle|answer|ordering|transfer|open'], methods: ['POST'])]
    public function table(Request $request, Table $id, string $action): Response
    {
        return $this->act($request, function () use ($request, $id, $action) {
            $session = $this->entityManager->getRepository(Session::class)->findOneBy(['table' => $id], ['id' => 'DESC']);
            $session = $session?->isOpen() ? $session : null;
            match ($action) {
                'settle' => $session ? $this->pass->settle($session, (string) ($request->request->get('with') ?: 'till')) : throw new RestaurantException('pass.error.no_bill'),
                'answer' => $session ? $this->pass->answerCall($session) : null,
                'ordering' => $this->pass->toggleOrdering($id),
                'open' => $session ?? $this->openAt($id, $request->request->getInt('covers') ?: null),
                'transfer' => $session ? $this->pass->transfer($session, $this->entityManager->find(Table::class, $request->request->getInt('to')) ?? throw new RestaurantException('pass.error.no_table')) : throw new RestaurantException('pass.error.no_bill'),
            };
        });
    }

    #[Route('/service/reservation/{id}/{action}', name: 'restaurant_pass_reservation', requirements: ['id' => '\d+', 'action' => 'confirm|refuse|seat|finish|no_show|cancel'], methods: ['POST'])]
    public function reservation(Request $request, Reservation $id, string $action): Response
    {
        return $this->act($request, function () use ($id, $action) {
            $to = match ($action) {
                'confirm' => ReservationStatus::CONFIRMED,
                'refuse' => ReservationStatus::REFUSED,
                'seat' => ReservationStatus::SEATED,
                'finish' => ReservationStatus::FINISHED,
                'no_show' => ReservationStatus::NO_SHOW,
                'cancel' => ReservationStatus::CANCELLED,
            };
            if (ReservationStatus::CANCELLED === $to) {
                $this->reservations->cancel($id, false);
            } else {
                $this->reservations->move($id, $to);
            }
            if (ReservationStatus::SEATED === $to) {
                $this->pass->seat($id);
            }
        });
    }

    /** A reservation taken on the phone, or a walk-in seated now. */
    #[Route('/service/reservation', name: 'restaurant_pass_book', methods: ['POST'])]
    public function book(Request $request): Response
    {
        return $this->act($request, function () use ($request) {
            $now = $this->pass->now();
            $walkIn = 'walk_in' === $request->request->get('source');
            $reservation = (new Reservation(null, max(1, $request->request->getInt('covers', 2)), trim((string) $request->request->get('name')) ?: '—'))
                ->setPhone((string) $request->request->get('phone'))->setEmail((string) $request->request->get('email'))
                ->setNotes((string) $request->request->get('notes'));
            $day = (string) ($request->request->get('day') ?: $now->format('Y-m-d'));
            $time = (string) ($request->request->get('time') ?: $now->format('H:i'));
            $this->reservations->take($reservation, $day, $time, $walkIn ? Reservation::SOURCE_WALK_IN : Reservation::SOURCE_PHONE);
            if ($walkIn) {
                $this->reservations->move($reservation, ReservationStatus::SEATED);
                $this->pass->seat($reservation);
            }
        });
    }

    #[Route('/service/plat/{id}/{action}', name: 'restaurant_pass_dish', requirements: ['id' => '\d+', 'action' => 'out|back'], methods: ['POST'])]
    public function dish(Request $request, Dish $id, string $action): Response
    {
        return $this->act($request, fn () => $this->pass->stock($id, 'back' === $action));
    }

    #[Route('/service/plateforme/{name}/{action}', name: 'restaurant_pass_platform', requirements: ['name' => '[a-z0-9_\-]+', 'action' => 'pause|resume'], methods: ['POST'])]
    public function platform(Request $request, string $name, string $action): Response
    {
        return $this->act($request, function () use ($request, $name, $action) {
            $store = $this->platforms?->store($name) ?? throw new RestaurantException('pass.error.platform');
            'pause' === $action ? $store->pause(new \DateTimeImmutable(sprintf('+%d minutes', max(5, $request->request->getInt('minutes', 30))))) : $store->resume();
        });
    }

    private function act(Request $request, callable $do): Response
    {
        $this->denyAccessUnlessGranted($this->staffRole);
        if (!$this->isCsrfTokenValid('restaurant-pass', (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token')))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $error = null;
        try {
            $do();
        } catch (RestaurantException $e) {
            $error = $this->translator->trans($e->getMessage(), [], 'restaurant');
        } catch (\Omnifood\Exception\OmnifoodException $e) {
            $error = $e->getMessage();
        }

        if ('json' === $request->getPreferredFormat() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->json(['ok' => null === $error, 'error' => $error] + $this->pass->state(), $error ? 422 : 200);
        }
        if ($error) {
            $this->addFlash('danger', $error);
        }

        return $this->redirectToRoute('restaurant_pass', array_filter(['poste' => $request->query->get('poste')]));
    }

    private function openAt(Table $table, ?int $covers): Session
    {
        $session = new Session($table, $covers);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function station(Request $request): ?Station
    {
        return Station::tryFrom((string) $request->query->get('poste'));
    }

    /** @return array<string, array{name: string, open: ?bool}> the platforms that take orders, and whether they are open (null: unknown) */
    private function platformStates(): array
    {
        $states = [];
        foreach ($this->platforms?->having(\Omnifood\StoreInterface::class) ?? [] as $name => $store) {
            $states[$name] = ['name' => $name, 'open' => null];
        }

        return $states;
    }
}
