import { useCallback, useEffect, useRef, useState } from 'react';
import { usePages, useZones, useAcceptableContainers } from '@/hooks/useDuplicateToQueries';
import './DuplicateToDialog.scss';

type Step = 'page' | 'zone' | 'container' | 'confirm';

interface DuplicateToDialogProps {
  readonly isOpen: boolean;
  readonly elementType: string;
  readonly currentPageId: number;
  readonly onConfirm: (targetPageId: number, targetZone: string, targetParentId: number) => void;
  readonly onCancel: () => void;
  readonly error?: string | null;
}

export default function DuplicateToDialog({
  isOpen,
  elementType,
  currentPageId,
  onConfirm,
  onCancel,
  error,
}: DuplicateToDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);

  const [step, setStep] = useState<Step>('page');
  const [searchTerm, setSearchTerm] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [selectedPageId, setSelectedPageId] = useState<number>(currentPageId);
  const [selectedZone, setSelectedZone] = useState<string | null>(null);
  const [selectedContainerId, setSelectedContainerId] = useState<number | null>(null);

  // Debounce search input to avoid excessive API calls
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchTerm);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  // Reset state when dialog opens
  useEffect(() => {
    if (isOpen) {
      setStep('page');
      setSearchTerm('');
      setDebouncedSearch('');
      setSelectedPageId(currentPageId);
      setSelectedZone(null);
      setSelectedContainerId(null);
    }
  }, [isOpen, currentPageId]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog === null) return;

    if (isOpen && !dialog.open) {
      dialog.showModal();
    } else if (!isOpen && dialog.open) {
      dialog.close();
    }
  }, [isOpen]);

  const handleClose = useCallback(() => {
    onCancel();
  }, [onCancel]);

  const pages = usePages(debouncedSearch, step === 'page');
  const zones = useZones(step === 'zone' || step === 'container' ? selectedPageId : null);
  const containers = useAcceptableContainers(
    step === 'container' ? selectedPageId : null,
    step === 'container' ? selectedZone : null,
    step === 'container' ? elementType : null,
  );

  const handlePageSelect = useCallback((pageId: number) => {
    setSelectedPageId(pageId);
  }, []);

  const advanceFromPage = useCallback(() => {
    setStep('zone');
  }, []);

  // Auto-select zone and advance when there is exactly one zone
  useEffect(() => {
    if (step === 'zone' && zones.data !== undefined && zones.data.length === 1) {
      setSelectedZone(zones.data[0]);
      if (elementType === 'section') {
        // Sections need no container selection — show confirmation step
        setStep('confirm');
      } else {
        setStep('container');
      }
    }
  }, [step, zones.data, elementType]);

  const handleZoneSelect = useCallback((zone: string) => {
    setSelectedZone(zone);
  }, []);

  const advanceFromZone = useCallback(() => {
    if (selectedZone === null) return;

    if (elementType === 'section') {
      setStep('confirm');
    } else {
      setStep('container');
    }
  }, [elementType, selectedZone]);

  const handleContainerSelect = useCallback((containerId: number) => {
    setSelectedContainerId(containerId);
  }, []);

  const handleConfirm = useCallback(() => {
    if (selectedZone === null) return;

    if (step === 'confirm') {
      // Section duplication — page is the parent
      onConfirm(selectedPageId, selectedZone, selectedPageId);
    } else if (selectedContainerId !== null) {
      onConfirm(selectedPageId, selectedZone, selectedContainerId);
    }
  }, [onConfirm, selectedPageId, selectedZone, selectedContainerId, step]);

  const goBack = useCallback(() => {
    if (step === 'zone') {
      setStep('page');
      setSelectedZone(null);
    } else if (step === 'container') {
      setStep('zone');
      setSelectedContainerId(null);
    } else if (step === 'confirm') {
      setStep('zone');
      setSelectedZone(null);
    }
  }, [step]);

  return (
    <dialog
      ref={dialogRef}
      className="duplicate-to-dialog"
      data-testid="duplicate-to-dialog"
      onClose={handleClose}
      onClick={(e) => e.stopPropagation()}
    >
      <div className="duplicate-to-dialog__header">
        <h3 className="duplicate-to-dialog__title">
          {step === 'page' && 'Select target page'}
          {step === 'zone' && 'Select zone'}
          {step === 'container' && 'Select container'}
          {step === 'confirm' && 'Confirm duplication'}
        </h3>
      </div>

      <div className="duplicate-to-dialog__body">
        {step === 'page' && (
          <div data-testid="duplicate-to-step-page">
            <input
              type="text"
              className="duplicate-to-dialog__search"
              data-testid="duplicate-to-search"
              placeholder="Search pages\u2026"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
            {pages.isLoading && (
              <p className="duplicate-to-dialog__loading">Loading pages\u2026</p>
            )}
            {pages.data !== undefined && (
              <ul className="duplicate-to-dialog__list" data-testid="duplicate-to-page-list" role="listbox">
                {pages.data.map((page) => (
                  <li
                    key={page.id}
                    className={`duplicate-to-dialog__item${page.id === selectedPageId ? ' duplicate-to-dialog__item--selected' : ''}${!page.hasGridZones ? ' duplicate-to-dialog__item--disabled' : ''}`}
                    role="option"
                    aria-selected={page.id === selectedPageId}
                    aria-disabled={!page.hasGridZones}
                    data-testid="duplicate-to-page-item"
                    onClick={page.hasGridZones ? () => handlePageSelect(page.id) : undefined}
                    onKeyDown={page.hasGridZones
                      ? (e) => { if (e.key === 'Enter' || e.key === ' ') handlePageSelect(page.id); }
                      : undefined}
                    tabIndex={page.hasGridZones ? 0 : -1}
                  >
                    {page.title}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {step === 'zone' && (
          <div data-testid="duplicate-to-step-zone">
            {zones.isLoading && (
              <p className="duplicate-to-dialog__loading">Loading zones\u2026</p>
            )}
            {zones.data !== undefined && zones.data.length > 1 && (
              <ul className="duplicate-to-dialog__list" data-testid="duplicate-to-zone-list" role="listbox">
                {zones.data.map((zone) => (
                  <li
                    key={zone}
                    className={`duplicate-to-dialog__item${zone === selectedZone ? ' duplicate-to-dialog__item--selected' : ''}`}
                    role="option"
                    aria-selected={zone === selectedZone}
                    data-testid="duplicate-to-zone-item"
                    onClick={() => handleZoneSelect(zone)}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') handleZoneSelect(zone); }}
                    tabIndex={0}
                  >
                    {zone}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {step === 'container' && (
          <div data-testid="duplicate-to-step-container">
            {containers.isLoading && (
              <p className="duplicate-to-dialog__loading">Loading containers\u2026</p>
            )}
            {containers.data !== undefined && containers.data.length === 0 && (
              <p className="duplicate-to-dialog__empty" data-testid="duplicate-to-no-containers">
                No compatible containers found in this zone
              </p>
            )}
            {containers.data !== undefined && containers.data.length > 0 && (
              <ul className="duplicate-to-dialog__list" data-testid="duplicate-to-container-list" role="listbox">
                {containers.data.map((container) => (
                  <li
                    key={container.id}
                    className={`duplicate-to-dialog__item${container.id === selectedContainerId ? ' duplicate-to-dialog__item--selected' : ''}`}
                    role="option"
                    aria-selected={container.id === selectedContainerId}
                    data-testid="duplicate-to-container-item"
                    onClick={() => handleContainerSelect(container.id)}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') handleContainerSelect(container.id); }}
                    tabIndex={0}
                  >
                    <span className="duplicate-to-dialog__item-title">{container.title}</span>
                    <span className="duplicate-to-dialog__item-type">{container.type}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {step === 'confirm' && (
          <div data-testid="duplicate-to-step-confirm">
            <p className="duplicate-to-dialog__summary">
              Duplicate section to zone <strong>{selectedZone}</strong>?
            </p>
          </div>
        )}
      </div>

      <div className="duplicate-to-dialog__footer">
        {error !== undefined && error !== null && (
          <p className="duplicate-to-dialog__error" data-testid="duplicate-to-error">{error}</p>
        )}
        <div className="duplicate-to-dialog__actions">
          {step !== 'page' && (
            <button
              type="button"
              className="duplicate-to-dialog__button duplicate-to-dialog__button--back"
              data-testid="duplicate-to-back"
              onClick={goBack}
            >
              Back
            </button>
          )}
          <button
            type="button"
            className="duplicate-to-dialog__button duplicate-to-dialog__button--cancel"
            onClick={handleClose}
          >
            Cancel
          </button>
          {step === 'page' && (
            <button
              type="button"
              className="duplicate-to-dialog__button duplicate-to-dialog__button--next"
              data-testid="duplicate-to-next"
              disabled={selectedPageId === 0}
              onClick={advanceFromPage}
            >
              Next
            </button>
          )}
          {step === 'zone' && (
            <button
              type="button"
              className="duplicate-to-dialog__button duplicate-to-dialog__button--next"
              data-testid="duplicate-to-next"
              disabled={selectedZone === null}
              onClick={advanceFromZone}
            >
              Next
            </button>
          )}
          {step === 'container' && (
            <button
              type="button"
              className="duplicate-to-dialog__button duplicate-to-dialog__button--confirm"
              data-testid="duplicate-to-confirm"
              disabled={selectedContainerId === null}
              onClick={handleConfirm}
            >
              Confirm
            </button>
          )}
          {step === 'confirm' && (
            <button
              type="button"
              className="duplicate-to-dialog__button duplicate-to-dialog__button--confirm"
              data-testid="duplicate-to-confirm"
              onClick={handleConfirm}
            >
              Confirm
            </button>
          )}
        </div>
      </div>
    </dialog>
  );
}
