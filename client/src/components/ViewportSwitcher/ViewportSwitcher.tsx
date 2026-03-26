import { useViewportContext } from '@/hooks/ViewportContext';
import { useResetOverridesAction } from '@/hooks/useResetOverridesAction';
import { getViewports } from '@/utils/gridAdapter';
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog';

export default function ViewportSwitcher() {
  const viewports = getViewports();
  const { activeViewport, setActiveViewport } = useViewportContext();
  const reset = useResetOverridesAction();

  return (
    <div className="viewport-switcher" data-testid="viewport-switcher">
      <div role="group" aria-label="Viewport size">
        {viewports.map((viewport) => {
          const isActive = viewport.key === activeViewport;

          return (
            <button
              key={viewport.key}
              type="button"
              className={`viewport-switcher__button${isActive ? ' viewport-switcher__button--active' : ''}`}
              data-testid="viewport-button"
              aria-pressed={isActive}
              aria-disabled={isActive || undefined}
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
      {reset.showReset && (
        <button
          type="button"
          className="viewport-switcher__reset"
          data-testid="reset-overrides-button"
          onClick={reset.onResetClick}
        >
          {reset.label}
        </button>
      )}
      <ConfirmDialog
        isOpen={reset.isDialogOpen}
        title={reset.dialogTitle}
        message={reset.dialogMessage}
        confirmLabel="Reset"
        onConfirm={reset.onConfirm}
        onCancel={reset.onCancel}
        destructive
      />
    </div>
  );
}
