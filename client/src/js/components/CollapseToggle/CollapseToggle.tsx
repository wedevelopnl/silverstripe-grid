import { t } from '@/i18n'

interface CollapseToggleProps {
  readonly isCollapsed: boolean
  readonly onToggle: () => void
  readonly label: string
}

export default function CollapseToggle({ isCollapsed, onToggle, label }: CollapseToggleProps) {
  return (
    <button
      type="button"
      className="ssgrid-icon-button"
      aria-expanded={!isCollapsed}
      aria-label={
        isCollapsed
          ? t('WeDevelopGrid.CollapseToggle.EXPAND_LABEL', 'Expand {label}', { label })
          : t('WeDevelopGrid.CollapseToggle.COLLAPSE_LABEL', 'Collapse {label}', { label })
      }
      data-testid="collapse-toggle"
      data-state={isCollapsed ? 'collapsed' : 'expanded'}
      onClick={(e) => {
        e.stopPropagation()
        onToggle()
      }}
    >
      <span
        className={`ssgrid-icon-button__glyph ${isCollapsed ? 'font-icon-down-open' : 'font-icon-up-open'}`}
        aria-hidden="true"
      />
    </button>
  )
}
