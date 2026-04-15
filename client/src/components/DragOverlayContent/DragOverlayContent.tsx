import type { ElementNode } from '@/types/elements';
import { isContainerNode } from '@/types/elements';
import type { DraggableType } from '@/types/dnd';
import { t } from '@/i18n';
import './DragOverlayContent.scss';

interface DragOverlayContentProps {
  readonly node: ElementNode;
  readonly type: DraggableType;
}

function pluralize(count: number, singular: string): string {
  return count === 1 ? `${count} ${singular}` : `${count} ${singular}s`;
}

function getChildCount(node: ElementNode): number {
  if (!isContainerNode(node)) return 0;
  return node.children?.length ?? 0;
}

interface PreviewProps {
  readonly node: ElementNode;
  readonly type: DraggableType;
}

function SectionPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i className={`drag-overlay-content__icon ${node.blockSchema.icon}`} />
      <span className="drag-overlay-content__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
      <span className="drag-overlay-content__meta">
        {pluralize(getChildCount(node), t('WeDevelopGrid.DragOverlayContent.ROW_SINGULAR', 'row'))}
      </span>
    </>
  );
}

function RowPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i className={`drag-overlay-content__icon ${node.blockSchema.icon}`} />
      <span className="drag-overlay-content__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
      <span className="drag-overlay-content__meta">
        {pluralize(
          getChildCount(node),
          t('WeDevelopGrid.DragOverlayContent.COLUMN_SINGULAR', 'column'),
        )}
      </span>
    </>
  );
}

function ColumnPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i className={`drag-overlay-content__icon ${node.blockSchema.icon}`} />
      <span className="drag-overlay-content__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
    </>
  );
}

function ElementPreview({ node, type }: PreviewProps): React.JSX.Element {
  return (
    <>
      <i className={`drag-overlay-content__icon ${node.blockSchema.icon}`} />
      <span className="drag-overlay-content__title" data-testid={`drag-overlay-${type}-title`}>
        {node.title}
      </span>
    </>
  );
}

const PREVIEW_BY_TYPE: Record<DraggableType, React.ComponentType<PreviewProps>> = {
  section: SectionPreview,
  row: RowPreview,
  column: ColumnPreview,
  element: ElementPreview,
};

export default function DragOverlayContent({
  node,
  type,
}: DragOverlayContentProps): React.JSX.Element {
  const Preview = PREVIEW_BY_TYPE[type];

  return (
    <div
      className={`drag-overlay-content drag-overlay-content--${type}`}
      data-testid={`drag-overlay-${type}`}
    >
      <Preview node={node} type={type} />
    </div>
  );
}
