import { createElement } from 'react';
import { createRoot } from 'react-dom/client';

import GridEditorErrorBoundary from '@/components/GridEditorErrorBoundary/GridEditorErrorBoundary';
import GridQueryProvider from '@/hooks/QueryProvider';
import { loadComponent } from './Injector';

interface BridgeSchema {
  pageId: number | null;
  zone: string;
  readonly: boolean;
  version: number | undefined;
}

function parseBridgeData(data: unknown): BridgeSchema {
  const record = (typeof data === 'object' && data !== null ? data : {}) as Record<string, unknown>;
  const rawPageId = record['grid-page-id'];
  const rawZone = record['grid-zone'];

  const rawReadonly = record['grid-readonly'];
  const rawVersion = record['grid-version'];

  return {
    pageId: typeof rawPageId === 'number' ? rawPageId : null,
    zone: typeof rawZone === 'string' && rawZone !== '' ? rawZone : 'main',
    readonly: rawReadonly === true,
    version: typeof rawVersion === 'number' ? rawVersion : undefined,
  };
}

/**
 * jQuery entwine bridge that mounts the React grid editor inside CMS pages.
 *
 * Entwine's onmatch/onunmatch hooks fire automatically when the CMS
 * replaces page content via AJAX navigation, handling mount/unmount
 * without explicit lifecycle management.
 */
window.jQuery.entwine('ss', ($) => {
  $('.js-injector-boot .grid-editor__container').entwine({
    onmatch() {
      try {
        const GridEditor = loadComponent('GridEditor');
        const { pageId, zone, readonly, version } = parseBridgeData(this.data('schema'));

        const root = createRoot(this[0]);
        this.setReactRoot(root);
        root.render(
          createElement(
            GridQueryProvider,
            null,
            createElement(
              GridEditorErrorBoundary,
              null,
              createElement(GridEditor, { pageId, zone, readonly, version }),
            ),
          ),
        );
      } catch (error: unknown) {
        console.warn('[GridEditor] Failed to mount grid editor.', error);
      }
    },

    onunmatch() {
      const root = this.getReactRoot();
      if (root !== null) {
        root.unmount();
        this.setReactRoot(null);
      }
    },
  });
});
