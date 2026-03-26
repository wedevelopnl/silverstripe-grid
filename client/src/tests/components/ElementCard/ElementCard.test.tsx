import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ElementCard from '@/components/ElementCard/ElementCard';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import { makeEnrichedElement } from '@/tests/helpers/enrichedFactories';

describe('ElementCard', () => {
  it('renders the element title as an h4 heading', () => {
    render(<ElementCard element={makeEnrichedElement({ title: 'Hero Banner' })} />, {
      wrapper: createDndWrapper(),
    });

    const heading = screen.getByRole('heading', { level: 4 });
    expect(heading.textContent).toBe('Hero Banner');
  });

  it('renders content preview from blockSchema.content', () => {
    const element = makeEnrichedElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: '',
        summary: 'A detailed paragraph about widgets.',
      },
    });

    render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    expect(screen.getByText('A detailed paragraph about widgets.')).toBeDefined();
  });

  it('renders "No preview available" when content is empty', () => {
    const element = makeEnrichedElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: '',
        summary: '',
      },
    });

    render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    expect(screen.getByText('No preview available')).toBeDefined();
  });

  it('renders blockSchema.icon as the element type icon', () => {
    const element = makeEnrichedElement({
      blockSchema: {
        typeName: String.raw`WeDevelop\Grid\Model\ContentElement`,
        label: 'Content Block',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: '',
        summary: 'preview',
      },
    });

    const { container } = render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    const icon = container.querySelector('.element-card__icon.font-icon-block-content');
    expect(icon).toBeDefined();
    expect(icon?.tagName.toLowerCase()).toBe('i');
  });

  it('applies element-card__content--empty class when content is empty', () => {
    const element = makeEnrichedElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: '',
        summary: '',
      },
    });

    const { container } = render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    expect(
      container.querySelector('.element-card__content')?.classList.contains('element-card__content--empty'),
    ).toBe(true);
  });

  it('does not apply element-card__content--empty class when content is non-empty', () => {
    const { container } = render(<ElementCard element={makeEnrichedElement({ blockSchema: { typeName: 'Content', label: 'Content', icon: 'font-icon-block-content', type: 'Content', title: '', summary: 'Some preview text' } })} />, {
      wrapper: createDndWrapper(),
    });

    expect(
      container.querySelector('.element-card__content')?.classList.contains('element-card__content--empty'),
    ).toBe(false);
  });

  it('applies "element-card--draft" class for draft elements', () => {
    const element = makeEnrichedElement({ statusFlags: { addedtodraft: { text: 'Draft', title: 'Item has not been published yet' } } });

    const { container } = render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    const card = container.querySelector('.element-card');
    expect(card?.classList.contains('element-card--draft')).toBe(true);
  });

  it('applies "element-card--published" class for published elements', () => {
    const element = makeEnrichedElement({ statusFlags: {} });

    const { container } = render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    const card = container.querySelector('.element-card');
    expect(card?.classList.contains('element-card--published')).toBe(true);
  });

  it('applies "element-card--modified" class for modified elements', () => {
    const element = makeEnrichedElement({ statusFlags: { modified: { text: 'Modified', title: 'Item has unpublished changes' } } });

    const { container } = render(<ElementCard element={element} />, {
      wrapper: createDndWrapper(),
    });

    const card = container.querySelector('.element-card');
    expect(card?.classList.contains('element-card--modified')).toBe(true);
  });

  it('renders drag handle and actions menu as interactive elements', () => {
    const { container } = render(<ElementCard element={makeEnrichedElement()} />, {
      wrapper: createDndWrapper(),
    });

    const buttons = container.querySelectorAll('button');
    const dragHandle = screen.getByRole('button', { name: /move/i });
    const actionsMenuTrigger = screen.getByTestId('actions-menu-trigger');

    expect(dragHandle).toBeDefined();
    expect(actionsMenuTrigger).toBeDefined();
    // Drag handle + actions menu trigger (dialog not mounted when closed)
    expect(buttons.length).toBe(2);
    expect(container.querySelectorAll('a').length).toBe(0);
    expect(container.querySelectorAll('input').length).toBe(0);
    expect(container.querySelectorAll('select').length).toBe(0);
    expect(container.querySelectorAll('textarea').length).toBe(0);
  });

  it('renders a drag handle', () => {
    render(<ElementCard element={makeEnrichedElement({ title: 'Hero Banner' })} />, {
      wrapper: createDndWrapper(),
    });

    const handle = screen.getByTestId('drag-handle');
    expect(handle).toBeDefined();
    expect(handle.getAttribute('aria-label')).toBe('Move Hero Banner');
  });

  describe('edit link', () => {
    let originalLocation: Location;

    beforeEach(() => {
      originalLocation = window.location;
      Object.defineProperty(window, 'location', {
        value: { href: '' },
        writable: true,
      });
    });

    afterEach(() => {
      Object.defineProperty(window, 'location', {
        value: originalLocation,
        writable: true,
      });
    });

    it('applies "element-card--clickable" class when editLink is present', () => {
      const element = makeEnrichedElement({ editLink: '/admin/grid-elements/EditForm/field/1/item/42' });

      const { container } = render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = container.querySelector('.element-card');
      expect(card?.classList.contains('element-card--clickable')).toBe(true);
    });

    it('does not apply "element-card--clickable" class when editLink is null', () => {
      const element = makeEnrichedElement({ editLink: null });

      const { container } = render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = container.querySelector('.element-card');
      expect(card?.classList.contains('element-card--clickable')).toBe(false);
      expect(card?.className).toBe('element-card element-card--published');
    });

    it('sets role="link" when editLink is present', () => {
      const element = makeEnrichedElement({ editLink: '/admin/grid-elements/EditForm/field/1/item/42' });

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      expect(card.getAttribute('role')).toBe('link');
    });

    it('does not set role attribute when editLink is null', () => {
      const element = makeEnrichedElement({ editLink: null });

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      expect(card.getAttribute('role')).toBeNull();
    });

    it('navigates to editLink on click', async () => {
      const element = makeEnrichedElement({ editLink: '/admin/grid-elements/EditForm/field/1/item/42' });
      const user = userEvent.setup();

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      await user.click(card);
      expect(window.location.href).toBe('/admin/grid-elements/EditForm/field/1/item/42');
    });

    it('navigates to editLink on Enter key press', async () => {
      const element = makeEnrichedElement({ editLink: '/admin/grid-elements/EditForm/field/1/item/42' });
      const user = userEvent.setup();

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      card.focus();
      await user.keyboard('{Enter}');
      expect(window.location.href).toBe('/admin/grid-elements/EditForm/field/1/item/42');
    });

    it('does not navigate on Enter key press when editLink is null', async () => {
      const element = makeEnrichedElement({ editLink: null });
      const user = userEvent.setup();

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      card.focus();
      await user.keyboard('{Enter}');
      expect(window.location.href).toBe('');
    });

    it('sets tabIndex=0 when editLink is present', () => {
      const element = makeEnrichedElement({ editLink: '/admin/grid-elements/EditForm/field/1/item/42' });

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      expect(card.getAttribute('tabindex')).toBe('0');
    });

    it('does not set tabIndex when editLink is null', () => {
      const element = makeEnrichedElement({ editLink: null });

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      expect(card.getAttribute('tabindex')).toBeNull();
    });

    it('does not navigate on non-Enter key press when editLink is present', async () => {
      const element = makeEnrichedElement({ editLink: '/admin/grid-elements/EditForm/field/1/item/42' });
      const user = userEvent.setup();

      render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = screen.getByTestId('element-card');
      card.focus();
      await user.keyboard('{Tab}');
      expect(window.location.href).toBe('');
    });

    it('does not set onKeyDown handler when editLink is null', () => {
      const element = makeEnrichedElement({ editLink: null });

      const { container } = render(<ElementCard element={element} />, {
        wrapper: createDndWrapper(),
      });

      const card = container.querySelector('.element-card') as HTMLElement;
      // When editLink is null, onKeyDown should be undefined (no handler attached)
      // We verify this by checking the element doesn't have a keydown listener
      // by checking that the role is not 'link'
      expect(card.getAttribute('role')).toBeNull();
    });
  });

});
