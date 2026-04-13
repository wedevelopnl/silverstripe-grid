import { getInjector } from '@/bridge/Injector';
import GridEditor from '@/components/GridEditor/GridEditor';
import GridEditorField from '@/components/GridEditorField/GridEditorField';

/**
 * Register all grid editor components with the SilverStripe Injector.
 *
 * - `GridEditor`      — legacy entwine bridge mount target for the
 *                       main edit view (server-rendered HTML path)
 * - `GridEditorField` — React FormBuilder entry point for the history
 *                       viewer (JSON schema path)
 *
 * Both components resolve to the same underlying grid editor React
 * tree; only the mounting strategy differs.
 */
export function registerComponents(): void {
  getInjector().component.registerMany({
    GridEditor,
    GridEditorField,
  });
}
