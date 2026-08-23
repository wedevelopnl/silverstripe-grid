<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Forms;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Tab;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\SharedBlockService;
use WeDevelop\Grid\Service\SharedBlockUsageResolver;
use WeDevelop\Grid\Value\SharedBlockDeleteMode;
use WeDevelop\Grid\Value\ValidationError;

/**
 * The edit form behind a block in the library, assigned by
 * {@see \WeDevelop\Grid\Admin\SharedBlockAdmin}.
 *
 * Its one job is the delete action. Deleting a block reaches every page that
 * places it, and the outcome cannot be inferred from the request: the pages
 * either lose the content or keep it as their own copy. Both are legitimate, so
 * the choice is the action itself — two buttons, each naming its outcome —
 * rather than one button opening a chooser. The admin's own confirmation
 * (entwine on `.action--delete` inside the edit form's toolbar) then guards the
 * click, so nothing here needs a dialog, an endpoint, or a line of JavaScript.
 *
 * Extends the versioned handler on purpose: assigning an item request class
 * makes {@see \SilverStripe\Versioned\VersionedGridFieldDetailForm} stand down,
 * so subclassing is what keeps save, publish and unpublish on a versioned
 * record.
 */
class SharedBlockItemRequest extends VersionedGridFieldItemRequest
{
    /**
     * `ItemEditForm` is listed because this class DECLARES it. Access is judged
     * against the uninherited `allowed_actions` of the class defining the
     * method ({@see \SilverStripe\Control\RequestHandler::checkAccessAction()}),
     * so overriding an inherited action without re-listing it here 403s the
     * form — and with it every nested element route underneath it.
     *
     * @var list<string>
     */
    private static array $allowed_actions = [
        'ItemEditForm',
        'doDeleteSharedBlock',
        'doUnshareSharedBlock',
    ];

    /** @var array<string, string> */
    private static array $dependencies = [
        'sharedBlockService' => '%$' . SharedBlockService::class,
        'usageResolver' => '%$' . SharedBlockUsageResolver::class,
    ];

    public SharedBlockService $sharedBlockService;

    public SharedBlockUsageResolver $usageResolver;

    /**
     * There is no form for an unsaved block. Blocks are created already seeded —
     * block plus root element in one transaction, the shape chosen at the add
     * control ({@see \WeDevelop\Grid\Forms\GridFieldAddSharedBlockButton}) —
     * and the stock `item/new` route bypasses that: it yields a rootless block
     * which, once saved, can only ever grow a Section root. Nothing links to it,
     * so refusing it closes a second creation path with weaker guarantees rather
     * than taking anything away.
     *
     * A record that is absent entirely is left to the parent, which redirects
     * back to the listing.
     */
    #[Override]
    public function ItemEditForm(): mixed
    {
        /** @var DataObject|null $record */
        $record = $this->getRecord();

        if ($record !== null && !$record->isInDB()) {
            $this->httpError(404);
        }

        return parent::ItemEditForm();
    }

    /**
     * Runs after `parent::getFormActions()` rather than through the
     * `updateFormActions` hook it fires, so the stock archive button is gone
     * whatever another extension pushed in the meantime.
     */
    #[Override]
    protected function getFormActions(): FieldList
    {
        $actions = parent::getFormActions();
        $record = $this->getRecord();

        if (!$record instanceof SharedBlock || !$record->isInDB() || !$record->canDelete()) {
            return $actions;
        }

        $actions->removeByName('action_doArchive');
        $actions->removeByName('action_doDelete');

        $moreOptions = $actions->findTab('ActionMenus.MoreOptions');
        $target = $moreOptions instanceof Tab ? $moreOptions : $actions;

        foreach ($this->deleteActions($this->usageResolver->usageCount($record)) as $action) {
            $target->push($action);
        }

        return $actions;
    }

    /**
     * With no placements the two outcomes are the same thing, so an unplaced
     * block offers one plain delete — a choice that decides nothing is worse
     * than no choice at all.
     *
     * @param int<0, max> $usageCount
     * @return list<FormAction>
     */
    private function deleteActions(int $usageCount): array
    {
        if ($usageCount === 0) {
            return [
                $this->deleteAction(
                    'doDeleteSharedBlock',
                    _t(SharedBlock::class . '.DELETE_ACTION', 'Delete block'),
                    _t(
                        SharedBlock::class . '.DELETE_DESC',
                        'This block is not placed on any page, so nothing else changes.',
                    ),
                ),
            ];
        }

        return [
            $this->deleteAction(
                'doDeleteSharedBlock',
                _t(
                    SharedBlock::class . '.DELETE_AND_REMOVE_ACTION',
                    'Delete and remove from {count} page|Delete and remove from {count} pages',
                    ['count' => $usageCount],
                ),
                _t(
                    SharedBlock::class . '.DELETE_AND_REMOVE_DESC',
                    'The content disappears from those pages, published ones included, without republishing them.',
                ),
            ),
            $this->deleteAction(
                'doUnshareSharedBlock',
                _t(SharedBlock::class . '.DELETE_AND_KEEP_ACTION', 'Delete and keep a copy on each page'),
                _t(
                    SharedBlock::class . '.DELETE_AND_KEEP_DESC',
                    'Each page keeps what it shows today as its own copy; only the sharing ends.',
                ),
            ),
        ];
    }

    /**
     * `action--delete` is load-bearing: the admin binds its confirmation to
     * that class inside `.cms-edit-form .btn-toolbar`. Renaming it removes the
     * only thing standing between a click and an irreversible cross-page write.
     */
    private function deleteAction(string $name, string $title, string $description): FormAction
    {
        return FormAction::create($name, $title)
            ->addExtraClass('action--delete btn btn-secondary')
            ->setDescription($description);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doDeleteSharedBlock(array $data, Form $form): HTTPResponse
    {
        return $this->deleteBlock(SharedBlockDeleteMode::Remove, $form);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doUnshareSharedBlock(array $data, Form $form): HTTPResponse
    {
        return $this->deleteBlock(SharedBlockDeleteMode::Unshare, $form);
    }

    /**
     * @throws ValidationException When the mode cannot be honoured — unsharing
     * a block whose content is gone leaves the pages nothing to copy.
     */
    private function deleteBlock(SharedBlockDeleteMode $mode, Form $form): HTTPResponse
    {
        $record = $this->getRecord();

        if (!$record instanceof SharedBlock || !$record->isInDB()) {
            $this->httpError(404);
        }

        if (!$record->canDelete()) {
            $this->httpError(403);
        }

        $title = (string) $record->Title;

        // Resolved before the delete, which is what removes the placements the
        // page list is derived from.
        $pages = $this->usageResolver->pagesUsing($record);

        $result = $this->sharedBlockService->delete($record, $mode);

        if ($result->isErr()) {
            throw ValidationException::create(implode(' ', array_map(
                static fn (ValidationError $error): string => $error->translate(),
                $result->errors(),
            )));
        }

        // The consuming pages now render something else, so each needs a draft
        // version recording that — the delete itself writes only the block.
        foreach ($pages as $page) {
            if ($page instanceof SiteTree) {
                $page->writeToStage(Versioned::DRAFT);
            }
        }

        $message = _t(
            SharedBlock::class . '.DELETED_MESSAGE',
            'Deleted shared block "{title}"',
            ['title' => $title],
        );

        $this->setFormMessage($form, $message);

        $controller = $this->getToplevelController();
        $controller->getRequest()->addHeader('X-Pjax', 'Content');
        $controller->getResponse()->addHeader('X-Status', rawurlencode($message));

        /** @var string $backLink The parent is untyped; it always returns the listing URL. */
        $backLink = $this->getBackLink();

        return $controller->redirect($backLink, 302);
    }
}
