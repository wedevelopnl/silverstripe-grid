// Entwine bridge first — registers jQuery hooks before DOM matching triggers
import '../bridge/entwine'
import '../bridge/gridSettingsField'

import { registerCmsPreviewBridge } from '../bridge/cmsPreviewBridge'

// Boot system — registers components with Injector on DOMContentLoaded
import '../boot'

// Styles — extracted by Vite into a separate CSS bundle
import '../../styles/bundle.css'

// Activate the CMS preview viewport selector. Runs on DOM ready so the
// vendor preview bar has a chance to render before the observer starts.
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => registerCmsPreviewBridge())
  } else {
    registerCmsPreviewBridge()
  }
}
