<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Controllers;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Service\SharedBlockService;
use WeDevelop\Grid\Service\SharedBlockUsageResolver;
use WeDevelop\Grid\Value\NodeType;

/**
 * The block library's JSON API: the library listing, a block's own tree, and the
 * operations that act on a block or on the boundary between a block and the
 * pages that place it.
 *
 * Everything that is merely an ELEMENT stays on {@see GridController}, including
 * a placement: a `SharedBlockReference` is a GridElement, so removing one goes
 * through `api/delete` there and moving one through `api/reorder`. Only the
 * block entity and the block-to-placement relationship are addressed here.
 */
class SharedBlockController extends GridApiController
{
    private static string $url_segment = 'grid-shared-blocks';

    /** @var array<string, string> */
    private static array $dependencies = [
        'treeService' => '%$' . GridTreeService::class,
        'sharedBlockService' => '%$' . SharedBlockService::class,
        'usageResolver' => '%$' . SharedBlockUsageResolver::class,
    ];

    public GridTreeService $treeService;

    public SharedBlockService $sharedBlockService;

    public SharedBlockUsageResolver $usageResolver;

    /** @var array<string, string> */
    private static array $url_handlers = [
        'GET api/list' => 'apiList',
        'GET api/readTree/$BlockID!' => 'apiReadTree',
        'GET api/usage/$BlockID!' => 'apiUsage',
        'POST api/create' => 'apiCreate',
        'POST api/place' => 'apiPlace',
        'POST api/convert' => 'apiConvert',
        'POST api/detach' => 'apiDetach',
        'PATCH api/setPublished' => 'apiSetPublished',
        'DELETE api/delete' => 'apiDelete',
    ];

    /** @var list<string> */
    private static array $allowed_actions = [
        'apiList',
        'apiReadTree',
        'apiUsage',
        'apiCreate',
        'apiPlace',
        'apiConvert',
        'apiDetach',
        'apiSetPublished',
        'apiDelete',
    ];

    /** @var list<string> */
    protected const array READ_ONLY_ACTIONS = [
        'apilist',
        'apireadtree',
        'apiusage',
    ];

    /**
     * Blocks the author may place, optionally narrowed to those whose root type
     * fits a given parent kind — a row-rooted block belongs in a section, never
     * at page level.
     */
    public function apiList(HTTPRequest $request): HTTPResponse
    {
        $parentTypeValue = $request->getVar('parentType');
        $parentType = is_string($parentTypeValue) ? NodeType::tryFrom($parentTypeValue) : null;
        $requiredRoot = $parentType === null ? null : $this->rootTypeAcceptedBy($parentType);

        $entries = [];

        foreach (SharedBlock::get() as $block) {
            if (!$block->canView()) {
                continue;
            }

            $root = $block->getRootElement();
            $rootType = $root === null ? null : NodeType::fromClass($root::class);

            if ($requiredRoot !== null && $rootType !== $requiredRoot) {
                continue;
            }

            $entries[] = [
                'id' => (int) $block->ID,
                'title' => (string) $block->Title,
                'rootType' => $rootType?->value,
                'usageCount' => $this->usageResolver->usageCount($block),
                'status' => $this->treeService->blockStatus($block)->value,
            ];
        }

        return $this->jsonSuccess(200, $entries);
    }

    /**
     * The library editor's tree read: rooted at a block instead of a page + zone.
     */
    public function apiReadTree(HTTPRequest $request): HTTPResponse
    {
        $block = $this->requireDraftBlock($this->requireIdParam($request, 'BlockID'));

        if (!$block->canView()) {
            $this->jsonError(403);
        }

        return $this->jsonSuccess(200, $this->treeService->buildViewableTree($block, '')->jsonSerialize());
    }

    /**
     * How widely a block is used, for the confirmation the library shows before
     * deleting it. Live usage is reported separately because that is the half
     * the author cannot undo by simply not publishing.
     */
    public function apiUsage(HTTPRequest $request): HTTPResponse
    {
        $block = $this->requireDraftBlock($this->requireIdParam($request, 'BlockID'));

        if (!$block->canView()) {
            $this->jsonError(403);
        }

        return $this->jsonSuccess(200, [
            'usageCount' => $this->usageResolver->usageCount($block),
            'liveUsageCount' => $this->usageResolver->liveUsageCount($block),
        ]);
    }

    /**
     * Create a block seeded with its root, and hand back where to edit it.
     *
     * The library's add button creates rather than opening an empty form: the
     * grid editor is keyed by the block's id, so a block has to exist before it
     * can host one, and a block without a root is a broken state rather than an
     * intermediate one.
     */
    public function apiCreate(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseCreateSharedBlockBody($this->parseJsonBody($request));
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $rootClass = $parseResult->unwrap()->rootClass;

        // Both gates: the library record is what the author is adding, and the
        // root is an element a project may forbid creating on its own.
        if (!SharedBlock::singleton()->canCreate() || !singleton($rootClass)->canCreate()) {
            $this->jsonError(403);
        }

        $result = $this->sharedBlockService->create($rootClass);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $block = $result->unwrap();

        return $this->jsonSuccess(200, [
            'id' => (int) $block->ID,
            'editLink' => (string) $block->getCMSEditLink(),
        ]);
    }

    public function apiPlace(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parsePlaceSharedBlockBody($this->parseJsonBody($request));
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $block = $this->requireDraftBlock($body->blockId);

        // The picker already hides blocks the author may not view, but that is
        // UI: a direct call carries an arbitrary blockId, so the same gate has
        // to hold here or a project's updateCanView veto is bypassable.
        if (!$block->canView()) {
            $this->jsonError(403);
        }

        $parent = $this->resolveNodeRef($body->parent);
        if ($parent === null || !$parent->exists()) {
            $this->jsonError(404);
        }

        if (!$parent->canEdit()) {
            $this->jsonError(403);
        }

        $result = $this->sharedBlockService->place($block, $parent, $body->zone, $body->insertAfterElementID, $body->insertAtStart);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    public function apiConvert(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseConvertToSharedBlockBody($this->parseJsonBody($request));
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $element = $this->requireElementWithPermission(
            $body->element,
            static fn (GridElement $e): bool => $e->canEdit(),
        );

        if (!SharedBlock::singleton()->canCreate()) {
            $this->jsonError(403);
        }

        // The page loses the subtree, so record the new draft version before the
        // re-parent detaches the element from it.
        $this->touchOwningPage($element);

        $title = $body->title !== '' ? $body->title : $element->getDisplayTitle();

        $result = $this->sharedBlockService->convertToShared($element, $title);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        return $this->jsonSuccess(200, ['blockId' => (int) $result->unwrap()->ID]);
    }

    public function apiDetach(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseElementRef($this->parseJsonBody($request));
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $element = $this->requireElementWithPermission(
            $parseResult->unwrap(),
            static fn (GridElement $e): bool => $e->canEdit() && $e->canDelete(),
        );

        if (!$element instanceof SharedBlockReference) {
            $this->jsonError(400);
        }

        $result = $this->sharedBlockService->detach($element);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    public function apiSetPublished(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseSetSharedBlockPublishedBody($this->parseJsonBody($request));
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $block = $this->requireDraftBlock($body->blockId);

        $permitted = $body->published ? $block->canPublish() : $block->canUnpublish();
        if ($permitted !== true) {
            $this->jsonError(403);
        }

        $result = $this->sharedBlockService->setPublished($block, $body->published);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        return $this->jsonSuccess(204);
    }

    public function apiDelete(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseDeleteSharedBlockFromQuery(
            $request->getVar('blockId'),
            $request->getVar('mode'),
        );
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $block = $this->requireDraftBlock($body->blockId);

        if (!$block->canDelete()) {
            $this->jsonError(403);
        }

        // Resolved before the delete, which is what removes the placements the
        // page list is derived from.
        $pages = $this->usageResolver->pagesUsing($block);

        $result = $this->sharedBlockService->delete($block, $body->mode);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        foreach ($pages as $page) {
            if ($page instanceof SiteTree) {
                $this->touchOwningPage($page);
            }
        }

        return $this->jsonSuccess(204);
    }

    /**
     * Fetch a draft block or 404. Permission is deliberately left to the call
     * site — reads require canView(), writes canEdit()/canDelete()/canPublish().
     *
     * @param positive-int $blockId
     */
    private function requireDraftBlock(int $blockId): SharedBlock
    {
        $block = $this->findDraftBlock($blockId);
        if ($block === null) {
            $this->jsonError(404);
        }

        return $block;
    }

    /**
     * The block root type a parent of this kind can hold — the inverse of
     * {@see NodeType::expectedParentType()}, used to filter the picker list.
     */
    private function rootTypeAcceptedBy(NodeType $parentType): ?NodeType
    {
        return match ($parentType) {
            NodeType::Page => NodeType::Section,
            NodeType::Section => NodeType::Row,
            NodeType::Row => NodeType::Column,
            NodeType::Column => NodeType::Element,
            NodeType::Element, NodeType::SharedBlock => null,
        };
    }
}
