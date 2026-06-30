<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Controllers;

use Override;
use LogicException;
use SilverStripe\Admin\AdminController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\AdapterConfig;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;
use WeDevelop\Grid\Forms\GridEditorField;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Service\ElementPlacementService;
use WeDevelop\Grid\Service\GridElementService;
use WeDevelop\Grid\Service\GridSettingsService;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Service\RequestBodyParser;

/**
 * @property GridElementRepositoryInterface $elementRepository
 * @property GridTreeBuilder $treeBuilder
 * @property ElementPlacementService $placementService
 * @property GridAdapterInterface $gridAdapter
 * @property RequestBodyParser $requestBodyParser
 * @property GridElementService $elementService
 * @property GridSettingsService $settingsService
 */
class GridController extends AdminController
{
    private static string $url_segment = 'grid';

    private static string $required_permission_codes = 'CMS_ACCESS';

    /** @var array<string, string> */
    private static array $dependencies = [
        'elementRepository' => '%$' . GridElementRepositoryInterface::class,
        'treeBuilder' => '%$' . GridTreeBuilder::class,
        'placementService' => '%$' . ElementPlacementService::class,
        'gridAdapter' => '%$' . GridAdapterInterface::class,
        'requestBodyParser' => '%$' . RequestBodyParser::class,
        'elementService' => '%$' . GridElementService::class,
        'settingsService' => '%$' . GridSettingsService::class,
    ];

    public GridElementRepositoryInterface $elementRepository;

    public GridTreeBuilder $treeBuilder;

    public ElementPlacementService $placementService;

    public GridAdapterInterface $gridAdapter;

    public RequestBodyParser $requestBodyParser;

    public GridElementService $elementService;

    public GridSettingsService $settingsService;

    /** @var array<string, string> */
    private static array $url_handlers = [
        'GET api/readTree/$PageID!/$Zone!/version/$Version!' => 'apiReadTreeAtVersion',
        'GET api/readTree/$PageID!/$Zone!' => 'apiReadTree',
        'POST api/create' => 'apiCreate',
        'POST api/createContent' => 'apiCreateContent',
        'PATCH api/publish' => 'apiPublish',
        'PATCH api/unpublish' => 'apiUnpublish',
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
        'apiCreateContent',
        'apiPublish',
        'apiUnpublish',
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

    #[Override]
    protected function init(): void
    {
        parent::init();

        if ($this->getRequest()->httpMethod() !== 'GET'
            && !SecurityToken::inst()->checkRequest($this->getRequest())
        ) {
            $this->jsonError(400);
        }
    }

    public function apiReadTree(HTTPRequest $request): HTTPResponse
    {
        $pageId = (int) $request->param('PageID');

        /** @var non-empty-string $zone Route pattern guarantees non-empty zone segment */
        $zone = (string) $request->param('Zone');

        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $tree = $this->treeBuilder->buildForPage($page, $zone);

        /** @var positive-int $pageId */
        $pageId = (int) $page->ID;
        /** @var positive-int $pageId — $page was loaded by ID above; byID returns null for non-positive IDs, and the null check jumps to jsonError. */
        $rootNodes = $tree[$pageId] ?? [];

        return $this->jsonSuccess(200, [
            'rootParent' => (new NodeRef(NodeType::Page, $pageId))->jsonSerialize(),
            'nodes' => $rootNodes,
        ]);
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
        $pageId = (int) $request->param('PageID');

        /** @var non-empty-string $zone Route pattern guarantees non-empty zone segment */
        $zone = (string) $request->param('Zone');

        $version = filter_var(
            $request->param('Version'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($version === false) {
            $this->jsonError(404);
        }

        /** @var positive-int $version filter_var guarantees min_range=1 */

        /** @var SiteTree|null $page */
        $page = Versioned::get_version(SiteTree::class, $pageId, $version);

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        $treeBuilder = $this->treeBuilder;

        // Wrap tree building in archived reading mode so standalone ORM queries
        // (in OrmGridElementRepository) resolve against the historical snapshot.
        // Versioned::reading_archived_date() is required because the tree builder
        // uses GridElement::get()->filter(...), NOT relation traversals from the
        // page record — updateInheritableQueryParams() does not apply.
        $tree = Versioned::withVersionedMode(static function () use ($treeBuilder, $page, $zone): array {
            Versioned::reading_archived_date($page->LastEdited);

            return $treeBuilder->buildForPage($page, $zone);
        });

        /** @var positive-int $pageId */
        $pageId = (int) $page->ID;
        $rootNodes = $tree[$pageId] ?? [];

        return $this->jsonSuccess(200, [
            'rootParent' => (new NodeRef(NodeType::Page, $pageId))->jsonSerialize(),
            'nodes' => $rootNodes,
        ]);
    }

    public function apiCreate(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
        $parseResult = $this->requestBodyParser->parseCreateBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $expectedParentType = match ($body->containerType) {
            ContainerType::Section => NodeType::Page,
            ContainerType::Row => NodeType::Section,
            ContainerType::Column => NodeType::Row,
        };
        if ($body->parent->type !== $expectedParentType) {
            $this->jsonError(400);
        }

        $parent = $this->resolveNodeRef($body->parent);
        if ($parent === null) {
            $this->jsonError(400);
        }

        if (!$parent->canEdit()) {
            $this->jsonError(403);
        }

        $newElementClass = $body->containerType->toElementClass();
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

    public function apiCreateContent(HTTPRequest $request): HTTPResponse
    {
        $data = $this->parseJsonBody($request);
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

    public function apiPublish(HTTPRequest $request): HTTPResponse
    {
        $ref = $this->requireElementRefFromRequest($request);
        $element = $this->requireElementWithPermission(
            $ref,
            static fn (GridElement $e): bool => $e->canPublish(),
        );

        $element->publishRecursive();

        return $this->jsonSuccess(204);
    }

    public function apiUnpublish(HTTPRequest $request): HTTPResponse
    {
        $ref = $this->requireElementRefFromRequest($request);
        $element = $this->requireElementWithPermission(
            $ref,
            static function (GridElement $e): bool {
                $result = $e->canUnpublish();
                assert(is_bool($result));

                return $result;
            },
        );

        $element->doUnpublish();

        return $this->jsonSuccess(204);
    }

    public function apiDelete(HTTPRequest $request): HTTPResponse
    {
        $ref = $this->requireElementRefFromQuery($request);
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

        $element = $this->requireElementWithPermission(
            $body->element,
            static fn (GridElement $e): bool => $e->canCreate(),
        );

        $elementType = NodeType::fromClass($element::class);
        $expectedTargetType = match ($elementType) {
            NodeType::Section => NodeType::Page,
            NodeType::Row => NodeType::Section,
            NodeType::Column => NodeType::Row,
            NodeType::Element => NodeType::Column,
            NodeType::Page => throw new LogicException(
                'apiDuplicateTo: element resolved to NodeType::Page, but the element repository '
                . 'only returns GridElement subclasses. This indicates a broken invariant.',
            ),
        };
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
        $data = $this->parseResetGridSettingsOverridesQuery($request);
        $parseResult = $this->requestBodyParser->parseResetGridSettingsOverridesBody($data);
        if ($parseResult->isErr()) {
            return $this->resultToResponse($parseResult, 400);
        }

        $body = $parseResult->unwrap();

        $page = Versioned::withVersionedMode(static function () use ($body): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($body->pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

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
        $pageId = (int) $request->param('PageID');
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

        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

        if (!$page->canView()) {
            $this->jsonError(403);
        }

        /** @var non-empty-string $zone Route pattern guarantees non-empty zone segment */
        $zone = (string) $request->param('Zone');

        assert($targetContainerType instanceof ContainerType);
        $containers = $this->treeBuilder->findContainersOfType($page, $zone, $targetContainerType);

        return $this->jsonSuccess(200, $containers);
    }

    public function apiZones(HTTPRequest $request): HTTPResponse
    {
        $pageId = (int) $request->param('PageID');

        $page = Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });

        if ($page === null) {
            $this->jsonError(404);
        }

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
        /** @var array<string, mixed> $clientConfig */
        $clientConfig = parent::getClientConfig();
        $clientConfig['controllerLink'] = $this->Link();
        $clientConfig['gridAdapter'] = AdapterConfig::fromAdapter($this->gridAdapter);

        return $clientConfig;
    }

    /**
     * Write the owning page to DRAFT so it appears as "modified" in the CMS.
     *
     * Accepts either a GridElement (walks parent chain) or a pre-resolved SiteTree
     * (for delete operations where the element is already archived).
     */
    private function touchOwningPage(GridElement|SiteTree $subject): void
    {
        $page = $subject instanceof SiteTree ? $subject : $subject->getPage();

        if (!$page instanceof SiteTree) {
            return;
        }

        // writeToStage calls forceChange() + write() within the correct
        // reading mode and creates a new draft version even when no page
        // fields have changed
        $page->writeToStage(Versioned::DRAFT);
    }

    /**
     * Decode the JSON request body into an associative array, or 400 on failure.
     *
     * @return array<string, mixed>
     */
    private function parseJsonBody(HTTPRequest $request): array
    {
        $data = json_decode($request->getBody() ?? '', true);

        if (!is_array($data)) {
            $this->jsonError(400);
        }

        /** @var array<string, mixed> $data JSON object keys are always strings */
        return $data;
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
     * Parse a NodeRef from the query string (DELETE requests).
     *
     * DELETE bodies are not universally supported, so the element identity
     * arrives as `?type=section&id=42`. Both segments are coerced and handed
     * to the shared NodeRef validator.
     */
    private function requireElementRefFromQuery(HTTPRequest $request): NodeRef
    {
        $rawType = $request->getVar('type');
        $rawId = $request->getVar('id');
        $id = filter_var($rawId, FILTER_VALIDATE_INT);

        $parseResult = $this->requestBodyParser->parseElementRef([
            'element' => [
                'type' => is_string($rawType) ? $rawType : null,
                'id' => $id === false ? null : $id,
            ],
        ]);
        if ($parseResult->isErr()) {
            $this->jsonError(400);
        }

        return $parseResult->unwrap();
    }

    /**
     * Build a parser-compatible associative array from the reset-overrides
     * query string. Query values are always strings; coerce pageId to int so
     * `RequestBodyParser::parseResetGridSettingsOverridesBody` sees the same
     * shape it got when parameters were sent in a JSON body.
     *
     * @return array<string, mixed>
     */
    private function parseResetGridSettingsOverridesQuery(HTTPRequest $request): array
    {
        $rawPageId = $request->getVar('pageId');
        $pageId = filter_var($rawPageId, FILTER_VALIDATE_INT);

        $zone = $request->getVar('zone');
        $viewport = $request->getVar('viewport');

        return [
            'pageId' => $pageId === false ? null : $pageId,
            'zone' => is_string($zone) ? $zone : null,
            'viewport' => is_string($viewport) && $viewport !== '' ? $viewport : null,
        ];
    }

    /**
     * Load a grid element by NodeRef, or 400/403 on failure.
     *
     * Uses NodeRef rather than a bare ID so the lookup is collision-free
     * across SilverStripe's polymorphic ID namespaces (page IDs and grid
     * element IDs share the numeric space but live in separate tables).
     *
     * @param callable(GridElement): bool $permissionCheck
     */
    private function requireElementWithPermission(NodeRef $ref, callable $permissionCheck): GridElement
    {
        $element = $this->elementRepository->findByRef($ref);
        if ($element === null) {
            $this->jsonError(400);
        }

        if (!$permissionCheck($element)) {
            $this->jsonError(403);
        }

        return $element;
    }

    /**
     * Resolve a {@see NodeRef} to the concrete DataObject it refers to.
     *
     * Uses the NodeRef's type to pick the correct ORM table, avoiding the
     * polymorphic ID collision between SiteTree page IDs and GridElement IDs.
     */
    private function resolveNodeRef(NodeRef $ref): ?DataObject
    {
        return Versioned::withVersionedMode(static function () use ($ref): ?DataObject {
            Versioned::set_stage(Versioned::DRAFT);

            /** @var class-string<DataObject> $class */
            $class = $ref->type->toClass();

            return DataObject::get($class)->byID($ref->id);
        });
    }

    /**
     * Convert a failed Result into a JSON error response.
     *
     * @template T
     * @param Result<T> $result
     */
    private function resultToResponse(Result $result, int $statusCode = 422): never
    {
        $messages = array_map(
            static fn (ValidationError $error): string => $error->translate(),
            $result->errors(),
        );

        $this->jsonError($statusCode, implode(' ', $messages));
    }

}
