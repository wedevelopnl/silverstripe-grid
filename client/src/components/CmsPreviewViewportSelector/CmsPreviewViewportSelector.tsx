import { useViewportContext } from '@/hooks/ViewportContext';
import { getViewports } from '@/utils/gridAdapter';

export default function CmsPreviewViewportSelector() {
  const viewports = getViewports();
  const { activeViewport, setActiveViewport } = useViewportContext();

  return (
    // biome-ignore lint/a11y/useSemanticElements: matches ViewportSwitcher's toolbar-style grouping; <fieldset> implies a form.
    <div
      className="cms-preview-viewport-selector"
      data-testid="cms-preview-viewport-selector"
      role="group"
    >
      {viewports.map((viewport) => {
        const isActive = viewport.key === activeViewport;
        return (
          <button
            key={viewport.key}
            type="button"
            className={`cms-preview-viewport-selector__button${isActive ? ' cms-preview-viewport-selector__button--active' : ''}`}
            data-testid="cms-preview-viewport-button"
            aria-pressed={isActive}
            onClick={() => {
              if (!isActive) {
                setActiveViewport(viewport.key);
              }
            }}
          >
            {viewport.label}
          </button>
        );
      })}
    </div>
  );
}
