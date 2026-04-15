import { t } from '@/i18n';

interface CollapseToggleProps {
  readonly isCollapsed: boolean;
  readonly onToggle: () => void;
  readonly label: string;
}

export default function CollapseToggle({ isCollapsed, onToggle, label }: CollapseToggleProps) {
  return (
    <button
      type="button"
      className={`collapse-toggle${isCollapsed ? ' collapse-toggle--collapsed' : ''}`}
      aria-expanded={!isCollapsed}
      aria-label={
        isCollapsed
          ? t('WeDevelopGrid.CollapseToggle.EXPAND_LABEL', 'Expand {label}', { label })
          : t('WeDevelopGrid.CollapseToggle.COLLAPSE_LABEL', 'Collapse {label}', { label })
      }
      data-testid="collapse-toggle"
      onClick={(e) => {
        e.stopPropagation();
        onToggle();
      }}
    >
      <span className="collapse-toggle__chevron" aria-hidden="true" />
    </button>
  );
}
