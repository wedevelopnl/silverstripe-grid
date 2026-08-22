<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Controllers;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Value\AdapterConfig;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridTree;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\ValidationErrorCode;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Service\ElementPlacementService;
use WeDevelop\Grid\Service\GridElementService;
use WeDevelop\Grid\Service\GridSettingsService;
use WeDevelop\Grid\Service\GridTreeService;

/**
 * The JSON API for a page's grid: reading a zone's tree and every operation on
 * the elements in it.
 *
 * The block library has its own controller, {@see SharedBlockController}. The
 * line between them is the resource, not the feature: a placement is a
 * GridElement, so it is deleted and reordered through this controller like any
 * other element.
 */
class GridController extends GridApiController
{
    private static string $url_segment = 'grid';

    /** @var array<string, string> */
    private static array $dependencies = [
        'treeService' => '%$' . GridTreeService::class,
        'placementService' => '%$' . ElementPlacementService::class,
        'gridAdapter' => '%$' . GridAdapterInterface::class,
        'elementService' => '%$' . GridElementService::class,
        'settingsService' => '%$' . GridSettingsService::class,
    ];

    public GridTreeService $treeService;

    public ElementPlacementService $placementService;

    public GridAdapterInterface $gridAdapter;

    public GridElementService $elementService;

    public GridSettingsService $settingsService;

    /** @var array<string, string> */
    private static array $url_handlers = [
        'GET api/readTree/$PageID!/$Zone!/version/$Version!' => 'apiReadTreeAtVersion',
        'GET api/readTree/$PageID!/$Zone!' => 'apiReadTree',
        'POST api/create' => 'apiCreate',
        'PATCH api/setPublished' => 'apiSetPublished',
        'DELETE api/delete' => 'apiDelete',
        'POST api/duplicate' => 'apiDuplicate',
        'POST api/duplicateTo' => 'apiDuplicateTo',
        'PATCH api/reorder' => 'apiReorder',
        'PATCH api/updateGridSettings' => 'apiUpdateGridSettings',
        'DELETE api/resetGridSettingsOverrides' => 'apiResetGridSettingsOverrides',
        'GET api/acceptableContainers/$PageID!/$Zone!/$ElementType!' => 'apiAcceptableContainers',
        'GET api/zones/$PageID!' => 'apiZones',
        'GET api/pages' => 'apiPages',
    ];

    /** @var list<string> */
    private static array $allowed_actions = [
        'apiReadTree',
        'apiReadTreeAtVersion',
        'apiCreate',
        'apiSetPublished',
        'apiDelete',
        'apiDuplicate',
        'apiDuplicateTo',
        'apiReorder',
        'apiUpdateGridSettings',
        'apiResetGridSettingsOverrides',
        'apiAcceptableContainers',
        'apiZones',
        'apiPages',
    ];

    /** @var list<string> */
    protected const array READ_ONLY_ACTIONS = [
        'apireadtree',
        'apireadtreeatversion',
        'apiacceptablecontainers',
        'apizones',
        'apipages',
    ];

    public function apiReadTree(HTTPRequest $request): HTTPResponse
    {
        $pageId = $this->requireIdParam($request, 'PageID');

        $zone = $this->requireZone($request);

        $page = $this->requireDraftPage($pageId);

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $tree = $this->treeService->buildViewableTree($page, $zone);

        return $this->jsonSuccess(200, $tree->jsonSerialize());
    }

    /**
     * Load a historical tree for a specific page version.
     *
     * Uses archived reading mode so that standalone ORM queries in the tree
     * builder (which don't inherit version context from the page record)
     * resolve against the correct historical snapshot.
     *
     * KNOWN LIMITATION: the archive cutoff is derived from the page version
     * row's LastEdited, which is a MySQL DATETIME with second precision.
     * Element writes that land in the same wall-clock second as the page's
     * target version cannot be distinguished from earlier same-second
     * writes and may leak into the historical snapshot. In practice this
     * only affects rapid (sub-second) successive publishes of the same
     * page; normal editor cadence is monotonic at second resolution so the
     * archive reading mode resolves correctly. A fully accurate fix would
     * require either microsecond-precision timestamps or a per-element
     * version-pinned query against GridElement_Versions — see
     * tests/Functional/Controllers/GridControllerTest::testReadTreeAtVersionReturnsEmptyTreeForPreSectionVersion.
     */
    public function apiReadTreeAtVersion(HTTPRequest $request): HTTPResponse
    {
        $pageId = $this->requireIdParam($request, 'PageID');

        $zone = $this->requireZone($request);

        $version = $this->requireIdParam($request, 'Version');

        /** @var SiteTree|null $page */
        $page = Versioned::get_version(SiteTree::class, $pageId, $version);

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $treeService = $this->treeService;

        // Wrap tree building in archived reading mode so standalone ORM queries
        // (in OrmGridElementRepository) resolve against the historical snapshot.
        // Versioned::reading_archived_date() is required because the tree service
        // uses GridElement::get()->filter(...), NOT relation traversals from the
        // page record — updateInheritableQueryParams() does not apply.
        $tree = Versioned::withVersionedMode(static function () use ($treeService, $page, $zone): GridTree {
            Versioned::reading_archived_date($page->LastEdited);

            return $treeService->buildViewableTree($page, $zone);
        });

        return $this->jsonSuccess(200, $tree->jsonSerialize());
    }

    public function apiCreate(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);

        $hasContainerType = array_key_exists('containerType', $data);
        $hasClassName = array_key_exists('className', $data);

        // Exactly one discriminator must be present. Both (ambiguous) or
        // neither (no discriminator) is a malformed request.
        if ($hasContainerType === $hasClassName) {
            $this->jsonError(400);
        }

        if ($hasContainerType) {
            return $this->createContainer($data);
        }

        return $this->createContent($data);
    }

    /**
     * Create a container element (Section/Row/Column) under its parent.
     *
     * @param array<string, mixed> $data
     */
    private function createContainer(array $data): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseCreateBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $newElementClass = $body->containerType->toElementClass();

        // The parent's NodeType is client-supplied and selects the ORM table in
        // resolveNodeRef(), so it must be checked against the hierarchy before
        // the lookup rather than left to the downstream write-time validator.
        // A block is the exception: it roots a subtree of any shape, so the
        // library editor may seed a new block with any container type.
        if (
            $body->parent->type !== NodeType::SharedBlock
            && $body->parent->type !== NodeType::fromClass($newElementClass)->expectedParentType()
        ) {
            $this->jsonError(400);
        }

        $parent = $this->resolveNodeRef($body->parent);
        if ($parent === null) {
            $this->jsonError(400);
        }

        if (!$parent->canEdit()) {
            $this->jsonError(403);
        }

        if (!singleton($newElementClass)->canCreate()) {
            $this->jsonError(403);
        }

        $result = $this->elementService->createElement(
            $parent,
            $body->containerType,
            $body->zone,
            $body->insertAfterElementID,
            $body->insertAtStart,
        );
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    /**
     * Create a content (leaf) element under a Column parent.
     *
     * @param array<string, mixed> $data
     */
    private function createContent(array $data): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseCreateContentBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        if ($body->parent->type !== NodeType::Column) {
            $this->jsonError(400);
        }

        // A null lookup result and a non-Column result both reduce to the same
        // 400: `null instanceof Column` is false, so the single instanceof
        // guard below covers the not-found case too (jsonError throws, never
        // returns), keeping behaviour identical to an explicit null check.
        $parent = $this->elementRepository->findByRef($body->parent);
        if (!$parent instanceof Column) {
            $this->jsonError(400);
        }

        if (!$parent->canEdit()) {
            $this->jsonError(403);
        }

        if (!singleton($body->className)->canCreate()) {
            $this->jsonError(403);
        }

        $result = $this->elementService->createContentElement($parent, $body->className, $body->insertAfterElementID);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    public function apiSetPublished(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);

        $published = $data['published'] ?? null;
        if (!is_bool($published)) {
            $this->jsonError(400);
        }

        $parseResult = $this->requestBodyParser->parseElementRef($data);
        if ($parseResult->isErr()) {
            $this->jsonError(400);
        }

        $ref = $parseResult->unwrap();

        $permissionCheck = $published
            ? static fn (GridElement $e): bool => $e->canPublish()
            : static function (GridElement $e): bool {
                $result = $e->canUnpublish();
                assert(is_bool($result));

                return $result;
            };

        $element = $this->requireElementWithPermission($ref, $permissionCheck);

        if ($published) {
            $element->publishRecursive();
        } else {
            $element->doUnpublish();
        }

        return $this->jsonSuccess(204);
    }

    public function apiDelete(HTTPRequest $request): HTTPResponse
    {
        $refResult = $this->requestBodyParser->parseElementRefFromQuery(
            $request->getVar('type'),
            $request->getVar('id'),
        );
        if ($refResult->isErr()) {
            $this->jsonError(400);
        }
        $ref = $refResult->unwrap();

        $element = $this->requireElementWithPermission(
            $ref,
            static fn (GridElement $e): bool => $e->canDelete(),
        );

        $page = $element->getPage();
        $element->doArchive();

        if ($page instanceof SiteTree) {
            $this->touchOwningPage($page);
        }

        return $this->jsonSuccess(204);
    }

    public function apiDuplicate(HTTPRequest $request): HTTPResponse
    {
        $ref = $this->requireElementRefFromRequest($request);
        $element = $this->requireElementWithPermission(
            $ref,
            static fn (GridElement $e): bool => $e->canCreate(),
        );

        $parent = $element->Parent();
        if ($parent === null || !$parent->exists() || !$parent->canEdit()) {
            $this->jsonError(403);
        }

        // Duplicating in place reuses the original's ParentID/ParentClass, so
        // under a block it would write a SECOND root. getRootElement() returns
        // the first one, leaving the copy as invisible orphaned content.
        if ($parent instanceof SharedBlock) {
            $this->jsonError(400);
        }

        $result = $this->elementService->duplicateElement($element);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    public function apiDuplicateTo(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseDuplicateToBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        // The source element must be readable/editable on its OWN page, not merely
        // creatable in the abstract: canCreate() is page-independent (a global
        // CMS_ACCESS check), so gating on it alone would let an editor copy an
        // element off a page they cannot access into one they can, then read the
        // clone. canEdit() delegates to the source element's owning page, closing
        // that cross-page disclosure — mirroring apiDuplicate's parent->canEdit() gate.
        $element = $this->requireElementWithPermission(
            $body->element,
            static fn (GridElement $e): bool => $e->canCreate() && $e->canEdit(),
        );

        // Judged by the PLACEMENT class, not the concrete one: a shared-block
        // placement is a SharedBlockReference, which NodeType classifies as a
        // leaf Element and so demanded a Column parent — refusing every legal
        // target for a section-, row- or column-rooted placement.
        $placementClass = $element->getPlacementClass();
        if ($placementClass === null) {
            // The block is missing or empty, so the copy would stand in for
            // nothing and no target could be validated.
            $this->jsonError(422);
        }

        // Never null: the placement class is always a GridElement subclass, so
        // the source can never resolve to NodeType::Page (the one rootless type).
        $expectedTargetType = NodeType::fromClass($placementClass)->expectedParentType();
        if ($body->targetParent->type !== $expectedTargetType) {
            $this->jsonError(400);
        }

        $targetParent = $this->resolveNodeRef($body->targetParent);

        if ($targetParent === null || !$targetParent->exists()) {
            $this->jsonError(404);
        }

        if (!$targetParent->canEdit()) {
            $this->jsonError(403);
        }

        $result = $this->elementService->duplicateElementTo(
            $element,
            $targetParent,
            $body->targetPageId,
            $body->targetZone,
        );
        if ($result->isErr()) {
            // Ownership validation failures (C1) are bad-request errors;
            // hierarchy violations (C2) are domain validation errors (422).
            $statusCode = match ($result->errors()[0]->code ?? null) {
                ValidationErrorCode::OwnershipDenied => 400,
                default => 422,
            };

            return $this->resultToResponse($result, $statusCode);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    public function apiReorder(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseReorderBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $element = $this->elementRepository->findByRef($body->element);
        if ($element === null) {
            $this->jsonError(400);
        }

        if (!$element->canEdit()) {
            $this->jsonError(403);
        }

        $targetParent = $this->resolveNodeRef($body->parent);
        if (!$targetParent instanceof DataObject) {
            $this->jsonError(400);
        }

        if (!$targetParent->canEdit()) {
            $this->jsonError(403);
        }

        /** @var positive-int $sourceParentId */
        $sourceParentId = (int) $element->ParentID;
        /** @var class-string $sourceParentClass */
        $sourceParentClass = (string) $element->ParentClass;
        // Resolve the source parent to a NodeType so the comparison is semantic
        // rather than literal: a stored `Page` ParentClass and a request's
        // `NodeType::Page` (which maps to SiteTree::class) must compare equal
        // for same-parent section reorders.
        $isCrossParent = $sourceParentId !== $body->parent->id
            || NodeType::fromClass($sourceParentClass) !== $body->parent->type;

        if ($isCrossParent) {
            $sourceParent = $element->Parent();
            if ($sourceParent === null || !$sourceParent->exists() || !$sourceParent->canEdit()) {
                $this->jsonError(403);
            }
        }

        $result = $this->placementService->reorder($element, $targetParent, $body->after?->id);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($element);

        return $this->jsonSuccess(204);
    }

    public function apiUpdateGridSettings(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseUpdateGridSettingsBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        if ($body->element->type !== NodeType::Column) {
            $this->jsonError(400);
        }

        $element = $this->requireElementWithPermission(
            $body->element,
            static fn (GridElement $e): bool => $e->canEdit(),
        );

        if (!$element instanceof Column) {
            $this->jsonError(400);
        }

        $result = $this->settingsService->updateSettings($element, $body->viewport, $body->width, $body->offset, $body->visible);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        $this->touchOwningPage($result->unwrap());

        return $this->jsonSuccess(204);
    }

    public function apiResetGridSettingsOverrides(HTTPRequest $request): HTTPResponse
    {
        $parseResult = $this->requestBodyParser->parseResetGridSettingsOverridesFromQuery(
            $request->getVar('pageId'),
            $request->getVar('zone'),
            $request->getVar('viewport'),
        );
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $page = $this->requireDraftPage($body->pageId);

        if (!$page->canEdit()) {
            $this->jsonError(403);
        }

        // A null viewport is intentional, not missing data: it means "reset
        // overrides across all viewports" (used when the editor is on the
        // default viewport, which has no overrides of its own). A non-null key
        // resets only that single viewport. See ResetGridSettingsOverridesRequest.
        $result = $this->settingsService->resetOverrides($page, $body->zone, $body->viewport);
        if ($result->isErr()) {
            return $this->resultToResponse($result);
        }

        if ($result->unwrap() > 0) {
            $this->touchOwningPage($page);
        }

        return $this->jsonSuccess(204);
    }

    public function apiAcceptableContainers(HTTPRequest $request): HTTPResponse
    {
        // Deliberately left as plain `string`: `$ElementType!` is a presence
        // check, so an empty segment is reachable (`acceptableContainers/5/main/.json`).
        // The match below maps '' to null and 400s, so no separate guard is needed.
        $elementType = (string) $request->param('ElementType');

        // Map element type to the container type that holds it
        $targetContainerType = match ($elementType) {
            'row' => ContainerType::Section,
            'column' => ContainerType::Row,
            'element' => ContainerType::Column,
            'section' => null,
            default => null,
        };

        if ($elementType !== 'section' && $targetContainerType === null) {
            $this->jsonError(400);
        }

        // Sections are root-level — no container needed
        if ($elementType === 'section') {
            return $this->jsonSuccess(200, []);
        }

        $pageId = $this->requireIdParam($request, 'PageID');

        $page = $this->requireDraftPage($pageId);

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $zone = $this->requireZone($request);

        assert($targetContainerType instanceof ContainerType);
        $containers = $this->treeService->findViewableContainersOfType($page, $zone, $targetContainerType);

        $payload = [];
        foreach ($containers as $container) {
            /** @var positive-int $containerId */
            $containerId = (int) $container->ID;
            $payload[] = [
                'id' => $containerId,
                'title' => $container->getDisplayTitle(),
                'type' => $targetContainerType->value,
            ];
        }

        return $this->jsonSuccess(200, $payload);
    }

    public function apiZones(HTTPRequest $request): HTTPResponse
    {
        $pageId = $this->requireIdParam($request, 'PageID');

        $page = $this->requireDraftPage($pageId);

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $fields = $page->getCMSFields();

        /** @var list<non-empty-string> $zones */
        $zones = [];
        foreach ($fields->flattenFields() as $field) {
            if ($field instanceof GridEditorField) {
                $zones[] = $field->getZone();
            }
        }

        return $this->jsonSuccess(200, array_values(array_unique($zones)));
    }

    /**
     * Return up to 50 pages the current member can edit, for the duplicate-to
     * picker. The DB query is capped at 50 rows BEFORE the per-row canEdit()
     * filter, so the response holds "up to 50 editable matches" — not "the
     * first 50 editable pages". The search box (Title:PartialMatch) is the
     * real navigation affordance; the cap bounds the unfiltered listing.
     */
    public function apiPages(HTTPRequest $request): HTTPResponse
    {
        $search = $request->getVar('search');

        /** @var list<array{id: positive-int, title: string, parentId: int, hasGridZones: bool}> $results */
        $results = Versioned::withVersionedMode(static function () use ($search): array {
            Versioned::set_stage(Versioned::DRAFT);

            $pages = SiteTree::get()->sort(['Title' => 'ASC']);

            if (is_string($search) && $search !== '') {
                $pages = $pages->filter(['Title:PartialMatch' => $search]);
            }

            $pages = $pages->limit(50);

            /** @var list<array{id: positive-int, title: string, parentId: int, hasGridZones: bool}> $items */
            $items = [];
            foreach ($pages as $page) {
                if (!$page->canEdit()) {
                    continue;
                }

                $hasGridZones = $page->hasExtension(GridPageExtension::class);

                /** @var positive-int $id */
                $id = (int) $page->ID;

                $items[] = [
                    'id' => $id,
                    'title' => (string) $page->Title,
                    'parentId' => (int) $page->ParentID,
                    'hasGridZones' => $hasGridZones,
                ];
            }

            return $items;
        });

        return $this->jsonSuccess(200, $results);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getClientConfig(): array
    {
        $clientConfig = parent::getClientConfig();
        $clientConfig['gridAdapter'] = AdapterConfig::fromAdapter($this->gridAdapter);

        return $clientConfig;
    }

    /**
     * Read the required `Zone` route param as a zone name, or 404.
     *
     * `$Zone!` is a presence check and `isset('')` is true, so "required" does
     * not imply non-empty: {@see HTTPRequest::setUrl()} strips a trailing slash
     * before the extension regex puts one back, so `readTree/5/.json` splits to
     * a trailing '' segment and reaches the action with an empty zone.
     *
     * @return non-empty-string
     */
    private function requireZone(HTTPRequest $request): string
    {
        $zone = (string) $request->param('Zone');
        if ($zone === '') {
            $this->jsonError(404);
        }

        return $zone;
    }

    /**
     * Parse a NodeRef-shaped `element` field from the JSON request body.
     */
    private function requireElementRefFromRequest(HTTPRequest $request): NodeRef
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseElementRef($data);
        if ($parseResult->isErr()) {
            $this->jsonError(400);
        }

        return $parseResult->unwrap();
    }

    /**
     * Fetch a page on the DRAFT stage, or 404. The permission check stays at the
     * call site — read endpoints require canView(), writes canEdit().
     *
     * @param positive-int $pageId
     */
    private function requireDraftPage(int $pageId): SiteTree
    {
        $page = $this->findDraftPage($pageId);
        if ($page === null) {
            $this->jsonError(404);
        }

        return $page;
    }
}
