<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use SilverStripe\Admin\AdminController;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use WeDevelop\Grid\Model\SharedBlock;

/**
 * Swaps the stock archive button on a shared block's edit form for one that
 * opens the library's own delete dialog.
 *
 * Deleting a block reaches every page that places it, so the stock button's
 * generic "are you sure?" is the wrong instrument: the author has to see how
 * far the change travels and choose whether those pages keep the content. The
 * replacement is an inert button the front end binds to — no form action of its
 * own, so a broken bundle leaves a button that does nothing rather than a
 * one-click destructive submit.
 *
 * @extends Extension<GridFieldDetailForm_ItemRequest>
 */
class SharedBlockDeleteActionExtension extends Extension
{
    public function updateFormActions(FieldList $actions): void
    {
        $record = $this->getOwner()->getRecord();

        if (!$record instanceof SharedBlock || !$record->isInDB() || !$record->canDelete()) {
            return;
        }

        $actions->removeByName('action_doArchive');
        $actions->removeByName('action_doDelete');

        $field = LiteralField::create('DeleteSharedBlock', sprintf(
            '<button type="button" class="btn btn-outline-danger font-icon-trash-bin '
            . 'action--delete-shared-block" data-grid-shared-block-delete="%d" '
            . 'data-grid-shared-block-title="%s" data-grid-shared-block-return="%s">%s</button>',
            (int) $record->ID,
            htmlspecialchars((string) $record->Title, ENT_QUOTES),
            htmlspecialchars($this->libraryLink(), ENT_QUOTES),
            htmlspecialchars(
                _t(SharedBlock::class . '.DELETE_ACTION', 'Delete block…'),
                ENT_QUOTES,
            ),
        ));

        $moreOptions = $actions->findTab('ActionMenus.MoreOptions');

        if ($moreOptions instanceof Tab) {
            $moreOptions->push($field);

            return;
        }

        $actions->push($field);
    }

    /**
     * Where to send the author once the record they are editing no longer
     * exists. Read from the admin rather than hardcoded so a project that
     * re-registers the library under its own url_segment still lands somewhere.
     *
     * Reached through the GridField because the item request's own
     * getToplevelController() is protected, and an extension calling it goes
     * through __call() and fails. Empty when the chain does not resolve; the
     * front end falls back to reloading.
     *
     * Absolute, not the admin's own relative Link(): the front end hands this
     * to location.assign() from a URL several segments deep, where a relative
     * link would resolve against the edit form's path instead of the site root.
     */
    private function libraryLink(): string
    {
        $controller = $this->getOwner()->getGridField()->getForm()->getController();

        return $controller instanceof AdminController
            ? (string) Director::absoluteURL((string) $controller->Link())
            : '';
    }
}
