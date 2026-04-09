import { useCallback, useState } from 'react';
import { useViewportContext } from './ViewportContext';
import { useGridEditorContext } from './GridEditorContext';
import { useViewportOverrideCounts } from './useElementTree';
import { useResetGridSettingsOverrides } from './useElementMutations';
import { getDefaultViewport, getViewports } from '@/utils/gridAdapter';

interface ResetOverridesState {
  readonly showReset: boolean;
  readonly label: string;
  readonly affectedCount: number;
  readonly isDialogOpen: boolean;
  readonly dialogTitle: string;
  readonly dialogMessage: string;
  readonly onResetClick: () => void;
  readonly onConfirm: () => void;
  readonly onCancel: () => void;
}

export function useResetOverridesAction(): ResetOverridesState {
  const { activeViewport } = useViewportContext();
  const { pageId, zone } = useGridEditorContext();
  const overrideCounts = useViewportOverrideCounts(pageId, zone);
  const { mutate: resetOverrides } = useResetGridSettingsOverrides(pageId, zone);
  const [isDialogOpen, setDialogOpen] = useState(false);

  const defaultViewport = getDefaultViewport();
  const isDefaultViewport = activeViewport === defaultViewport;

  const affectedCount = isDefaultViewport
    ? (overrideCounts._total ?? 0)
    : (overrideCounts[activeViewport] ?? 0);

  const viewportLabel =
    getViewports().find((vp) => vp.key === activeViewport)?.label ?? activeViewport;

  const label = isDefaultViewport ? 'Reset all' : 'Reset viewport';

  const dialogTitle = isDefaultViewport
    ? 'Reset all overrides'
    : `Reset ${viewportLabel} overrides`;

  const suffix = affectedCount === 1 ? 'column' : 'columns';
  const dialogMessage = isDefaultViewport
    ? `Reset all viewport overrides across ${affectedCount} ${suffix}?`
    : `Reset overrides for ${affectedCount} ${suffix} on ${viewportLabel}?`;

  const handleResetClick = useCallback(() => {
    setDialogOpen(true);
  }, []);

  const handleCancel = useCallback(() => {
    setDialogOpen(false);
  }, []);

  const handleConfirm = useCallback(() => {
    setDialogOpen(false);
    const params = isDefaultViewport
      ? { pageId, zone }
      : { pageId, zone, viewport: activeViewport };
    resetOverrides(params);
  }, [isDefaultViewport, pageId, zone, activeViewport, resetOverrides]);

  return {
    showReset: affectedCount > 0,
    label,
    affectedCount,
    isDialogOpen,
    dialogTitle,
    dialogMessage,
    onResetClick: handleResetClick,
    onConfirm: handleConfirm,
    onCancel: handleCancel,
  };
}
