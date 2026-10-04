<?php

namespace Base\Restaurant\Controller\Admin;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;

/**
 * The restaurant's screens are written by its manager (ROLE_ADMIN), not only
 * by the super-admin omnibase/admin requires by default for anything that
 * writes - as omnibase/classroom and omnibase/estate do.
 */
trait OpenToManagersTrait
{
    protected function openToManagers(Actions $actions, string ...$custom): Actions
    {
        return $actions->setPermissions(array_fill_keys(array_merge([
            Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE,
            Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER,
        ], $custom), 'ROLE_ADMIN'));
    }
}
