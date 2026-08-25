import { type ReactNode, useCallback, useState } from 'react'
import ActionsMenu, { type ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { buildColumnStyle } from '@/components/ColumnBlock/buildColumnStyle'
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog'
import { useEditorRoot } from '@/hooks/GridEditorContext'
import { useEditorTree } from '@/hooks/useEditorTree'
import { useReorderElement } from '@/hooks/useElementMutations'
import { useDetachSharedBlock, useSetSharedBlockPublished } from '@/hooks/useSharedBlockMutations'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import type { ElementNode, SharedBlockReferenceNode } from '@/types/elements'
import { isColumnNode } from '@/types/elements'
import { resolveViewportSettings } from '@/utils/gridAdapter'
import { PlacementContext } from './PlacementContext'
import SharedPlacementActions from './SharedPlacementActions'

interface SharedBlockFrameProps {
  readonly node: SharedBlockReferenceNode
  /** The placement's siblings in render order — the basis for move up/down. */
  readonly siblings: readonly ElementNode[]
  /** Readonly hosts (history viewer) render the frame without its actions. */
  readonly readonly?: boolean
  readonly children: ReactNode
}

type PendingConfirm = 'publish' | 'detach' | null

/**
 * The bounded frame around a placed shared block.
 *
 * It exists to make one thing unmistakable: edits inside it are not local. The
 * chip says how many pages the change reaches, and the status marker says
 * whether live has caught up yet.
 *
 * The frame is deliberately NOT a sortable. At page root a placement sits among
 * Sections, and mixed-type sibling dragging is new ground for the collision
 * system; v1 moves placements with explicit up/down actions instead, through
 * the same reorder endpoint a drag would use.
 *
 * It IS, however, the layout item wherever the block it stands in for would
 * have been one. A column-rooted block placed in a row makes this element the
 * child of `.ssgrid-row-columns`, so the row's direct-child sizing rules match
 * the frame and not the `.ssgrid-column` buried two levels inside it — hence
 * the width/offset custom properties below. Without them the placement is
 * auto-placed into a single grid track and renders as a sliver.
 */
export default function SharedBlockFrame({
  node,
  siblings,
  readonly = false,
  children,
}: SharedBlockFrameProps) {
  const editorRoot = useEditorRoot()
  const { activeViewport } = useViewportContext()
  // The same cached query the editor already holds — no extra request, and it
  // gives nested placements the rollback snapshot the reorder mutation needs.
  //
  // Disabled in a readonly host: it is only ever read by `move`, which readonly
  // never renders, and the history viewer's own tree is version-scoped — so an
  // unversioned fetch there is a wasted request that seeds the draft cache from
  // a read-only screen.
  const { data: tree } = useEditorTree(readonly ? null : editorRoot)
  const setPublished = useSetSharedBlockPublished(editorRoot)
  const detach = useDetachSharedBlock(editorRoot)
  const reorder = useReorderElement(editorRoot)

  const [pendingConfirm, setPendingConfirm] = useState<PendingConfirm>(null)
  const closeConfirm = useCallback(() => setPendingConfirm(null), [])

  const { blockId, title, usageCount, status } = node.sharedBlock

  // Only a column-rooted block needs this; every other shape is a plain block
  // item in a vertical stack and sizes itself.
  const root = node.children[0]
  const frameStyle =
    root !== undefined && isColumnNode(root)
      ? buildColumnStyle(resolveViewportSettings(root.gridSettings, activeViewport), {})
      : undefined

  const index = siblings.findIndex((sibling) => sibling.nodeKey === node.nodeKey)
  const canMoveUp = index > 0
  const canMoveDown = index !== -1 && index < siblings.length - 1

  /**
   * The reorder API places an element AFTER a reference sibling, so moving up
   * means "after the one before my predecessor" — null when that lands first.
   *
   * Availability is decided by sibling position alone, not by whether the tree
   * query has resolved: gating on the query would make the menu's contents
   * change under the user a moment after it opens.
   */
  const move = useCallback(
    (direction: -1 | 1) => {
      if (tree === undefined || index === -1) return

      const targetIndex = index + direction
      const after =
        direction === -1 ? (siblings[targetIndex - 1]?.self ?? null) : siblings[targetIndex].self

      reorder.mutate({
        params: { element: node.self, parent: node.parent, after },
        tree,
        clearPendingTree: () => {},
      })
    },
    [index, node.parent, node.self, reorder, siblings, tree],
  )

  const confirmPublish = useCallback(() => {
    setPublished.mutate({ blockId, published: true })
    closeConfirm()
  }, [blockId, closeConfirm, setPublished])

  const confirmDetach = useCallback(() => {
    detach.mutate({ element: node.self })
    closeConfirm()
  }, [closeConfirm, detach, node.self])

  const actions: ActionItem[] = []

  if (!readonly) {
    if (status !== 'published') {
      actions.push({
        key: 'shared-block-action-publish',
        label: t('WeDevelopGrid.SharedBlockFrame.ACTION_PUBLISH', 'Publish shared block'),
        onAction: () => setPendingConfirm('publish'),
      })
    }

    if (canMoveUp) {
      actions.push({
        key: 'shared-block-action-move-up',
        label: t('WeDevelopGrid.SharedBlockFrame.ACTION_MOVE_UP', 'Move up'),
        onAction: () => move(-1),
      })
    }

    if (canMoveDown) {
      actions.push({
        key: 'shared-block-action-move-down',
        label: t('WeDevelopGrid.SharedBlockFrame.ACTION_MOVE_DOWN', 'Move down'),
        onAction: () => move(1),
      })
    }

    actions.push({
      key: 'shared-block-action-detach',
      label: t('WeDevelopGrid.SharedBlockFrame.ACTION_DETACH', 'Detach into this page'),
      destructive: true,
      onAction: () => setPendingConfirm('detach'),
    })
  }

  const statusLabel = (() => {
    if (status === 'notPublished') {
      return t('WeDevelopGrid.SharedBlockFrame.NOT_PUBLISHED', 'Not published yet')
    }
    if (status === 'modified') {
      return t('WeDevelopGrid.SharedBlockFrame.PENDING_CHANGES', 'Unpublished changes')
    }
    return null
  })()

  return (
    <div
      className="ssgrid-shared-block"
      data-testid="shared-block-frame"
      data-status={status}
      style={frameStyle}
    >
      <div className="ssgrid-shared-block-bar" data-testid="shared-block-actions">
        <span className="ssgrid-shared-block-chip" data-testid="shared-block-chip">
          {usageCount === 1
            ? t(
                'WeDevelopGrid.SharedBlockFrame.CHIP_ONE',
                'Shared · {title} · used on {count} page',
                {
                  title,
                  count: usageCount,
                },
              )
            : t('WeDevelopGrid.SharedBlockFrame.CHIP', 'Shared · {title} · used on {count} pages', {
                title,
                count: usageCount,
              })}
        </span>

        {statusLabel !== null && (
          <span
            className="ssgrid-status-badge"
            data-testid="shared-block-status"
            data-status={status}
          >
            {statusLabel}
          </span>
        )}

        {!readonly && (
          <div className="ssgrid-shared-block-actions">
            <SharedPlacementActions placement={node} />
            {actions.length > 0 && <ActionsMenu actions={actions} testId="shared-block-actions" />}
          </div>
        )}
      </div>

      <div className="ssgrid-shared-block-body">
        <PlacementContext.Provider value={node}>{children}</PlacementContext.Provider>
      </div>

      {pendingConfirm === 'publish' && (
        <ConfirmDialog
          isOpen
          title={t('WeDevelopGrid.SharedBlockFrame.PUBLISH_TITLE', 'Publish shared block')}
          message={t(
            'WeDevelopGrid.SharedBlockFrame.PUBLISH_MESSAGE',
            'Publishing "{title}" updates it on {count} pages at once.',
            { title, count: usageCount },
          )}
          confirmLabel={t('WeDevelopGrid.SharedBlockFrame.PUBLISH_CONFIRM', 'Publish')}
          onConfirm={confirmPublish}
          onCancel={closeConfirm}
        />
      )}

      {pendingConfirm === 'detach' && (
        <ConfirmDialog
          isOpen
          destructive
          title={t('WeDevelopGrid.SharedBlockFrame.DETACH_TITLE', 'Detach shared block')}
          message={t(
            'WeDevelopGrid.SharedBlockFrame.DETACH_MESSAGE',
            'This page gets its own copy of "{title}". Later changes to the shared block will no longer reach it.',
            { title },
          )}
          confirmLabel={t('WeDevelopGrid.SharedBlockFrame.DETACH_CONFIRM', 'Detach')}
          onConfirm={confirmDetach}
          onCancel={closeConfirm}
        />
      )}
    </div>
  )
}
