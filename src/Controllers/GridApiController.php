<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Controllers;

use Override;
use SilverStripe\Admin\AdminController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Service\RequestBodyParser;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;

/**
 * Plumbing shared by the grid's JSON APIs: the CSRF gate, draft-stage lookups,
 * {@see NodeRef} resolution, permission-checked element loading and the
 * Result-to-response mapping.
 *
 * One subclass per resource — {@see GridController} for a page's elements,
 * {@see SharedBlockController} for the block library. They are separate
 * controllers because they address different things (a page + zone versus a
 * block) under different URL segments, not because they need different
 * plumbing; everything they genuinely share lives here.
 */
abstract class GridApiController extends AdminController
{
    /**
     * Deliberately the broad CMS gate on both subclasses, including the block
     * library's: placing or reading an existing block is a page-editing act.
     * The library's own section code (`CMS_ACCESS_SharedBlockAdmin`) gates
     * MANAGING a block, and is checked per-record by SharedBlock::canEdit() and
     * friends rather than at the controller door.
     */
    private static string $required_permission_codes = 'CMS_ACCESS';

    /** @var array<string, string> */
    private static array $dependencies = [
        'elementRepository' => '%$' . GridElementRepositoryInterface::class,
        'requestBodyParser' => '%$' . RequestBodyParser::class,
    ];

    public GridElementRepositoryInterface $elementRepository;

    public RequestBodyParser $requestBodyParser;

    /**
     * Lowercased names of the actions that only read data, and therefore need no
     * security token. Each subclass declares its own read set; the empty default
     * fails closed, so an action left out of the list is merely token-guarded
     * rather than silently unguarded.
     *
     * @var list<string>
     */
    protected const array READ_ONLY_ACTIONS = [];

    /**
     * Require a security token for every action outside READ_ONLY_ACTIONS.
     *
     * The check cannot be keyed on the HTTP verb: the verb-bound $url_handlers
     * on the subclasses are not the only route to these methods.
     * RequestHandler::findAction() walks up the class hierarchy and falls back
     * to Controller's verb-agnostic '$Action//$ID/$OtherID' rule, which resolves
     * any $allowed_actions entry from the first URL segment — so a plain GET
     * reaches apiDelete() and every other mutation. Actions are matched
     * case-insensitively because that fallback passes the segment through as
     * typed, and hasMethod() accepts any casing.
     *
     * @param HTTPRequest $request
     * @param string $action
     */
    #[Override]
    protected function handleAction(mixed $request, mixed $action): mixed
    {
        if (!in_array(strtolower($action), static::READ_ONLY_ACTIONS, true)
            && !SecurityToken::inst()->checkRequest($request)
        ) {
            $this->jsonError(400);
        }

        return parent::handleAction($request, $action);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getClientConfig(): array
    {
        /** @var array<string, mixed> $clientConfig */
        $clientConfig = parent::getClientConfig();
        // Wire contract: a base URL without trailing slash — the client
        // concatenates "/api/..." onto it. Link() carries a trailing slash on
        // projects that enable Controller.add_trailing_slash.
        $clientConfig['controllerLink'] = rtrim((string) $this->Link(), '/');

        return $clientConfig;
    }

    /**
     * Decode the JSON request body into an associative array, or 400 on failure.
     *
     * @return array<string, mixed>
     */
    protected function parseJsonBody(HTTPRequest $request): array
    {
        $data = json_decode($request->getBody() ?? '', true);

        if (!is_array($data)) {
            $this->jsonError(400);
        }

        /** @var array<string, mixed> $data JSON object keys are always strings */
        return $data;
    }

    /**
     * Read a required route param as a record ID, or 404.
     *
     * A trailing `!` in a route pattern only makes the URL segment mandatory —
     * the router matches any non-empty string, so the value is validated rather
     * than cast: a plain `(int)` cast would silently turn `abc` into 0 and
     * `12abc` into record 12.
     *
     * @param non-empty-string $param
     * @return positive-int
     */
    protected function requireIdParam(HTTPRequest $request, string $param): int
    {
        $id = filter_var(
            $request->param($param),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($id === false) {
            $this->jsonError(404);
        }

        /** @var positive-int $id filter_var guarantees min_range=1 */

        return $id;
    }

    /**
     * Fetch a page on the DRAFT stage, regardless of the ambient reading stage.
     *
     * Every grid endpoint edits or previews draft content, so the stage is pinned
     * here rather than at each call site — a request whose ambient stage is LIVE
     * would otherwise miss a draft-only page.
     *
     * @param positive-int $pageId
     */
    protected function findDraftPage(int $pageId): ?SiteTree
    {
        return Versioned::withVersionedMode(static function () use ($pageId): ?SiteTree {
            Versioned::set_stage(Versioned::DRAFT);

            return SiteTree::get()->byID($pageId);
        });
    }

    /**
     * Fetch a shared block on the DRAFT stage, regardless of the ambient reading
     * stage — the library always edits draft content.
     *
     * @param positive-int $blockId
     */
    protected function findDraftBlock(int $blockId): ?SharedBlock
    {
        return Versioned::withVersionedMode(static function () use ($blockId): ?SharedBlock {
            Versioned::set_stage(Versioned::DRAFT);

            return SharedBlock::get()->byID($blockId);
        });
    }

    /**
     * Resolve a {@see NodeRef} to the concrete DataObject it refers to.
     *
     * Element refs go through the repository, so stage pinning and the
     * polymorphic-collision guard (a Row ref whose numeric ID also exists as
     * another GridElement subclass) live in one place. Page and SharedBlock are
     * the two types {@see GridElementRepositoryInterface::findByRef()}
     * deliberately excludes, because neither is a grid element — resolved here.
     */
    protected function resolveNodeRef(NodeRef $ref): ?DataObject
    {
        if ($ref->type === NodeType::Page) {
            /** @var positive-int $pageId NodeRef rejects non-positive ids */
            $pageId = $ref->id;

            return $this->findDraftPage($pageId);
        }

        // The library editor is rooted at a block, so a block is a legitimate
        // create target for the tree's single root element.
        if ($ref->type === NodeType::SharedBlock) {
            return $this->findDraftBlock($ref->id);
        }

        return $this->elementRepository->findByRef($ref);
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
    protected function requireElementWithPermission(NodeRef $ref, callable $permissionCheck): GridElement
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
     * Write the owning page to DRAFT so it appears as "modified" in the CMS.
     *
     * Accepts either a GridElement (walks parent chain) or a pre-resolved SiteTree
     * (for delete operations where the element is already archived).
     */
    protected function touchOwningPage(GridElement|SiteTree $subject): void
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
     * Convert a failed Result into a JSON error response.
     *
     * @template T
     * @param Result<T> $result
     */
    protected function resultToResponse(Result $result, int $statusCode = 422): never
    {
        $messages = array_map(
            static fn (ValidationError $error): string => $error->translate(),
            $result->errors(),
        );

        $this->jsonError($statusCode, implode(' ', $messages));
    }
}
