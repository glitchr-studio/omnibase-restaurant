<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\MealService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MealService> */
class MealServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealService::class);
    }

    /** @return list<MealService> */
    public function ordered(): array
    {
        return $this->findBy([], ["position" => "ASC", "id" => "ASC"]);
    }

    /** @return list<MealService> the services running that day, in their order */
    public function runningOn(\DateTimeInterface $day): array
    {
        return array_values(array_filter($this->findBy(['active' => true], ['position' => 'ASC', 'id' => 'ASC']), fn (MealService $s) => $s->runsOn($day)));
    }

    /** The service a time of that day falls in. */
    public function at(\DateTimeInterface $day, string $time): ?MealService
    {
        foreach ($this->runningOn($day) as $service) {
            if ($service->covers($time)) {
                return $service;
            }
        }

        return null;
    }
}
