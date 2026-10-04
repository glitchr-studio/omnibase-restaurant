<?php

namespace Base\Restaurant\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Restaurant\Entity\Layout;
use Base\Restaurant\Entity\Room;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\TableShape;
use Base\Restaurant\Repository\RoomRepository;
use Base\Restaurant\Repository\TableRepository;
use Base\Restaurant\Service\FloorPlan;
use Base\Service\Qr\QrSheet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The manager's room screens in the back office (they open in the nest, on
 * @Admin/layout.html.twig): the floor editor - the room drawn to scale in
 * SVG, tables dragged on a snapping grid, turned, duplicated, joined in a
 * layout, walls and the counter drawn, saved as JSON (public/js/floor.js) -
 * and the QR posters of the tables, an A4 page each or labels
 * (glitchr/omnibase's QrSheet), a table's code renewed.
 */
class FloorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomRepository $rooms,
        private readonly TableRepository $tables,
        private readonly FloorPlan $plans,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
        #[Autowire('%restaurant.manager_role%')] private readonly string $managerRole = 'ROLE_ADMIN',
    ) {
    }

    #[Route('/admin/restaurant/salle', name: 'restaurant_admin_floor', defaults: ['_nest' => true], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->managerRole);
        $rooms = $this->rooms->ordered();
        $room = $request->query->getInt('salle') ? $this->entityManager->find(Room::class, $request->query->getInt('salle')) : ($rooms[0] ?? null);
        $layout = $request->query->getInt('disposition') ? $this->entityManager->find(Layout::class, $request->query->getInt('disposition')) : null;
        if ($layout && $layout->getRoom() !== $room) {
            $layout = null;
        }

        return $this->page('@Restaurant/admin/floor.html.twig', [
            'rooms' => $rooms,
            'room' => $room,
            'layout' => $layout,
            'drawing' => $room ? $this->plans->drawing($room, $layout) : null,
            'shapes' => TableShape::cases(),
        ]);
    }

    #[Route('/admin/restaurant/salle/nouvelle', name: 'restaurant_admin_floor_room', methods: ['POST'])]
    public function newRoom(Request $request): Response
    {
        $this->guard($request);
        $room = new Room(trim((string) $request->request->get('name')) ?: 'Salle', max(300, $request->request->getInt('width', 1200)), max(300, $request->request->getInt('height', 800)));
        $room->setPosition(\count($this->rooms->ordered()));
        $this->entityManager->persist($room);
        $this->entityManager->flush();

        return $this->redirectToRoute('restaurant_admin_floor', ['salle' => $room->getId()]);
    }

    #[Route('/admin/restaurant/salle/{room}/disposition', name: 'restaurant_admin_floor_layout', requirements: ['room' => '\d+'], methods: ['POST'])]
    public function newLayout(Request $request, Room $room): Response
    {
        $this->guard($request);
        $layout = new Layout(trim((string) $request->request->get('name')) ?: 'Disposition', $room);
        foreach ($room->getTables() as $table) {
            $layout->place($table, $table->getX(), $table->getY(), $table->getRotation(), $table->isActive());
        }
        $layout->setDefault(null === $room->getDefaultLayout());
        $this->entityManager->persist($layout);
        $this->entityManager->flush();

        return $this->redirectToRoute('restaurant_admin_floor', ['salle' => $room->getId(), 'disposition' => $layout->getId()]);
    }

    /** The editor's save: the room's size and decor, the tables (new, changed, removed), and - in a layout - their places there and the joins. */
    #[Route('/admin/restaurant/salle/{room}/enregistrer', name: 'restaurant_admin_floor_save', requirements: ['room' => '\d+'], methods: ['POST'])]
    public function save(Request $request, Room $room): JsonResponse
    {
        $this->guard($request);
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'json'], 400);
        }
        $layout = isset($data['layout']) && $data['layout'] ? $this->entityManager->find(Layout::class, (int) $data['layout']) : null;
        if ($layout && $layout->getRoom() !== $room) {
            return $this->json(['error' => 'layout'], 400);
        }

        if (isset($data['room']) && \is_array($data['room'])) {
            $room->setSize((int) ($data['room']['width'] ?? $room->getWidth()), (int) ($data['room']['height'] ?? $room->getHeight()));
            if (isset($data['room']['decor']) && \is_array($data['room']['decor'])) {
                $room->setDecor($data['room']['decor']);
            }
        }

        $byKey = [];
        $places = [];
        foreach ((array) ($data['tables'] ?? []) as $i => $t) {
            if (!\is_array($t)) {
                continue;
            }
            $table = isset($t['id']) && $t['id'] ? $this->tables->find((int) $t['id']) : null;
            if ($table && $table->getRoom() !== $room) {
                continue;
            }
            if (!$table) {
                $table = new Table();
                $room->addTable($table);
                $this->entityManager->persist($table);
            }
            $table->setLabel(mb_substr(trim((string) ($t['label'] ?? '')), 0, 20) ?: (string) ($i + 1))
                ->setCovers((int) ($t['min'] ?? 1), (int) ($t['max'] ?? 2))
                ->setShapeName((string) ($t['shape'] ?? 'square'))
                ->setWidth((int) ($t['w'] ?? 80))->setHeight((int) ($t['h'] ?? 80))
                ->setPosition($i);
            $place = ['x' => (int) round((float) ($t['x'] ?? 0)), 'y' => (int) round((float) ($t['y'] ?? 0)), 'rotation' => (int) ($t['rotation'] ?? 0), 'active' => (bool) ($t['active'] ?? true)];
            if (!$layout || !$table->getId()) {
                // The table's own place: where it stands without a layout, and where a new one starts.
                $table->place($place['x'], $place['y'], null, null, $place['rotation']);
                if (!$layout) {
                    $table->setActive($place['active']);
                }
            }
            $byKey[(string) ($t['key'] ?? $t['id'] ?? $i)] = $table;
            $places[] = [$table, $place];
        }
        foreach ((array) ($data['removed'] ?? []) as $id) {
            $table = $this->tables->find((int) $id);
            if ($table && $table->getRoom() === $room) {
                $room->removeTable($table);
                $this->entityManager->remove($table);
            }
        }
        $this->entityManager->flush(); // the new tables get their ids

        if ($layout) {
            $positions = [];
            foreach ($places as [$table, $place]) {
                $positions[(string) $table->getId()] = $place;
            }
            $layout->setPositions($positions);
            $joins = [];
            foreach ((array) ($data['joins'] ?? []) as $group) {
                $joins[] = array_values(array_filter(array_map(fn ($key) => $byKey[(string) $key] ?? null, (array) $group)));
            }
            $layout->setJoins(array_map(fn (array $g) => array_map(fn (Table $t) => (int) $t->getId(), $g), $joins));
            $this->entityManager->flush();
        }

        return $this->json([
            'ok' => true,
            'tables' => array_map(fn ($key, Table $t) => ['key' => (string) $key, 'id' => $t->getId()], array_keys($byKey), $byKey),
            'joins' => $layout?->getJoins() ?? [],
        ]);
    }

    /** The QR posters: one A4 page per table, or labels (L7121, L7160, L7163); ?salle= one room, ?skip= labels already used. */
    #[Route('/admin/restaurant/affiches', name: 'restaurant_admin_posters', methods: ['GET'])]
    public function posters(Request $request, QrSheet $sheet): Response
    {
        $this->denyAccessUnlessGranted($this->managerRole);
        $format = \in_array($request->query->get('format'), ['a4', 'L7121', 'L7160', 'L7163'], true) ? (string) $request->query->get('format') : 'a4';
        $room = $request->query->getInt('salle') ? $this->entityManager->find(Room::class, $request->query->getInt('salle')) : null;
        $items = [];
        foreach ($this->tables->active() as $table) {
            if ($room && $table->getRoom() !== $room) {
                continue;
            }
            $items[] = [
                'data' => $this->generateUrl('restaurant_table', ['token' => $table->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
                'label' => $table->getLabel(),
                'table' => $table,
            ];
        }

        if ('a4' !== $format) {
            return new Response($sheet->render($items, $format, 'Tables', max(0, $request->query->getInt('skip'))));
        }

        return $this->render('@Restaurant/admin/posters.html.twig', ['items' => $items, 'pages' => $sheet->pages($items, 'a4')]);
    }

    /** A table's code renewed: its printed poster stops working. */
    #[Route('/admin/restaurant/table/{id}/jeton', name: 'restaurant_admin_table_token', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function token(Request $request, Table $id): Response
    {
        $this->guard($request);
        $id->regenerateToken();
        $this->entityManager->flush();
        $this->addFlash('success', '@restaurant.admin.floor.token_renewed');

        return $this->redirectToRoute('restaurant_admin_floor', ['salle' => $id->getRoom()?->getId()]);
    }

    private function guard(Request $request): void
    {
        $this->denyAccessUnlessGranted($this->managerRole);
        if (!$this->isCsrfTokenValid('restaurant-floor', (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token')))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }

    private function page(string $template, array $parameters = []): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render($template, ['admin_context' => $this->adminContext] + $parameters);
    }
}
