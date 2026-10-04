<?php

namespace Base\Restaurant\Controller\Client;

use Base\Enum\Allergen;
use Base\Restaurant\Enum\Diet;
use Base\Restaurant\Repository\DishRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The menu, to read: by section, each dish with its name in the kitchen's
 * language, its price, its allergens (filterable: "sans gluten, sans
 * lait" hides what has them) and its diets; and the same on paper.
 */
class MenuController extends AbstractController
{
    public function __construct(
        private readonly DishRepository $dishes,
        #[Autowire('%restaurant.layout%')] private readonly string $layout = 'layout1.html.twig',
    ) {
    }

    #[Route('/carte', name: 'restaurant_menu', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Restaurant/client/menu.html.twig', [
            'layout' => $this->layout,
            'sections' => $this->dishes->bySection(),
            'allergens' => Allergen::cases(),
            'diets' => Diet::cases(),
        ]);
    }

    #[Route('/carte/imprimer', name: 'restaurant_menu_print', methods: ['GET'])]
    public function print(): Response
    {
        return $this->render('@Restaurant/client/menu_print.html.twig', [
            'sections' => $this->dishes->bySection(),
            'allergens' => Allergen::cases(),
        ]);
    }
}
