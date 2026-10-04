<?php

namespace Base\Restaurant\EventListener;

use Base\Restaurant\Service\Revision;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/** Anything of the restaurant written: the revision the pass watches grows. */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RevisionListener
{
    private bool $changed = false;

    public function __construct(private readonly Revision $revision)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                if (str_starts_with($entity::class, 'Base\\Restaurant\\') || str_contains($entity::class, '\\Restaurant\\')) {
                    $this->changed = true;

                    return;
                }
            }
        }
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if (str_starts_with($collection->getOwner()::class, 'Base\\Restaurant\\')) {
                $this->changed = true;

                return;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->changed) {
            $this->changed = false;
            $this->revision->bump();
        }
    }
}
