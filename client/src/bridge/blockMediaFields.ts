export {};

/**
 * Toggles visibility of layout-dependent fields (MediaPosition, VerticalAlignment,
 * GapSize) based on the ContentColumns radio selection. These fields are only
 * meaningful in side-by-side layout mode (ContentColumns > 0).
 *
 * Also manages the visual selected state on picker option labels, since the
 * radio inputs are visually hidden and CSS :checked alone cannot style the
 * parent label.
 *
 * Uses a MutationObserver to detect when the column-width-picker enters the DOM
 * (entwine onmatch does not reliably fire for elements loaded via CMS Pjax).
 */

function applyPickerState(picker: HTMLElement): void {
  const checked = picker.querySelector<HTMLInputElement>('input[name="ContentColumns"]:checked');
  if (!checked) {
    return;
  }

  const value = parseInt(checked.value, 10);
  const isSideBySide = value > 0;

  // Toggle holder visibility for dependent fields
  for (const suffix of ['_MediaPosition_Holder', '_VerticalAlignment_Holder', '_GapSize_Holder']) {
    const holder = document.querySelector<HTMLElement>(`[id$="${suffix}"]`);
    if (holder) {
      holder.style.display = isSideBySide ? '' : 'none';
    }
  }

  // Update selected visual state on all picker options
  for (const option of picker.querySelectorAll<HTMLElement>('.column-width-picker__option')) {
    option.classList.remove('column-width-picker__option--selected');
  }
  checked.closest('.column-width-picker__option')?.classList.add('column-width-picker__option--selected');
}

function initPicker(picker: HTMLElement): void {
  // Apply initial state
  applyPickerState(picker);

  // Listen for radio changes
  picker.addEventListener('change', () => applyPickerState(picker));
}

// Initialize any pickers already in the DOM
for (const picker of document.querySelectorAll<HTMLElement>('.column-width-picker')) {
  initPicker(picker);
}

// Watch for pickers loaded via CMS AJAX/Pjax navigation
const observer = new MutationObserver((mutations) => {
  for (const mutation of mutations) {
    for (const node of mutation.addedNodes) {
      if (!(node instanceof HTMLElement)) {
        continue;
      }

      // The added node could be the picker itself or an ancestor containing it
      if (node.classList.contains('column-width-picker')) {
        initPicker(node);
      } else {
        for (const picker of node.querySelectorAll<HTMLElement>('.column-width-picker')) {
          initPicker(picker);
        }
      }
    }
  }
});

observer.observe(document.body, { childList: true, subtree: true });
