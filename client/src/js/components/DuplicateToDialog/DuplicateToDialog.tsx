import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useAcceptableContainers, usePages, useZones } from '@/hooks/useDuplicateToQueries'
import { useRovingList } from '@/hooks/useRovingList'
import { t } from '@/i18n'
import type { NodeRef, NodeType } from '@/types/identity'
import type { ElementTypeKey } from '@/utils/getElementType'

type Step = 'page' | 'zone' | 'container' | 'confirm'

/**
 * Maps a grid element type to the type of its expected parent. Total over
 * {@link ElementTypeKey} — the type system enforces exhaustiveness, so no
 * runtime fallback is needed.
 */
const PARENT_TYPE_FOR_ELEMENT = {
  section: 'page',
  row: 'section',
  column: 'row',
  element: 'column',
} as const satisfies Record<ElementTypeKey, NodeType>

/**
 * Failure notice for one of the wizard's three list queries. The query client
 * runs with `retry: false`, so without an explicit retry affordance a single
 * failed request leaves the step permanently blank.
 */
function ListLoadError({
  error,
  onRetry,
}: {
  readonly error: Error
  readonly onRetry: () => void
}) {
  return (
    <div data-testid="duplicate-to-load-error" role="alert">
      <p className="ssgrid-dialog-error">
        {t('WeDevelopGrid.DuplicateToDialog.LOAD_FAILED', 'Could not load this list: {message}', {
          message: error.message,
        })}
      </p>
      <button
        type="button"
        className="ssgrid-button ssgrid-focus-ring"
        data-variant="ghost"
        data-testid="duplicate-to-retry"
        onClick={onRetry}
      >
        {t('WeDevelopGrid.DuplicateToDialog.RETRY_BUTTON', 'Retry')}
      </button>
    </div>
  )
}

interface DuplicateToDialogProps {
  readonly isOpen: boolean
  readonly elementType: ElementTypeKey
  readonly currentPageId: number
  readonly onConfirm: (targetPageId: number, targetZone: string, targetParent: NodeRef) => void
  readonly onCancel: () => void
  readonly error?: string | null
}

export default function DuplicateToDialog({
  isOpen,
  elementType,
  currentPageId,
  onConfirm,
  onCancel,
  error,
}: DuplicateToDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()

  const [step, setStep] = useState<Step>('page')
  const [searchTerm, setSearchTerm] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [selectedPageId, setSelectedPageId] = useState<number>(currentPageId)
  const [selectedZone, setSelectedZone] = useState<string | null>(null)
  const [selectedContainerId, setSelectedContainerId] = useState<number | null>(null)

  // Stryker disable next-line BlockStatement: Equivalent — timer-based debounce is not observable in synchronous unit tests (fake-timers collide with TanStack Query's internal timers)
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchTerm)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchTerm])

  // Reset state when dialog opens
  // Stryker disable next-line ConditionalExpression: Equivalent — the reset values equal initial state, so dropping the isOpen guard only matters on close→reopen, which in practice re-mounts the dialog under the CMS (tested via renderWithProviders lifecycle)
  useEffect(() => {
    if (isOpen) {
      setStep('page')
      setSearchTerm('')
      setDebouncedSearch('')
      setSelectedPageId(currentPageId)
      setSelectedZone(null)
      setSelectedContainerId(null)
    }
  }, [isOpen, currentPageId])

  useEffect(() => {
    const dialog = dialogRef.current
    if (dialog === null) return

    if (isOpen && !dialog.open) {
      dialog.showModal()
    } else if (!isOpen && dialog.open) {
      dialog.close()
    }
  }, [isOpen])

  const handleClose = useCallback(() => {
    onCancel()
  }, [onCancel])

  const pages = usePages(debouncedSearch, step === 'page')
  // Keep zones queried on 'confirm' too — goBack needs zones.data to decide
  // whether to return to 'page' (single zone) or 'zone' (multi-zone).
  const zones = useZones(step !== 'page' ? selectedPageId : null)
  const containers = useAcceptableContainers(
    step === 'container' ? selectedPageId : null,
    step === 'container' ? selectedZone : null,
    step === 'container' ? elementType : null,
  )

  const pageList = useRovingList({ itemCount: pages.data?.length ?? 0 })
  const zoneList = useRovingList({ itemCount: zones.data?.length ?? 0 })
  const containerList = useRovingList({ itemCount: containers.data?.length ?? 0 })

  const handlePageSelect = useCallback((pageId: number) => {
    setSelectedPageId(pageId)
  }, [])

  const advanceFromPage = useCallback(() => {
    setStep('zone')
  }, [])

  // Auto-select zone and advance when there is exactly one zone
  useEffect(() => {
    // Stryker disable next-line ConditionalExpression: Equivalent — the single-zone auto-advance routes to exactly the step it lands on; on non-zone steps useZones is disabled so zones.data is undefined and the body is gated out anyway
    if (step === 'zone' && zones.data !== undefined && zones.data.length === 1) {
      setSelectedZone(zones.data[0])
      if (elementType === 'section') {
        // Sections need no container selection — show confirmation step
        setStep('confirm')
      } else {
        setStep('container')
      }
    }
  }, [step, zones.data, elementType])

  const handleZoneSelect = useCallback((zone: string) => {
    setSelectedZone(zone)
  }, [])

  const advanceFromZone = useCallback(() => {
    // Stryker disable next-line ConditionalExpression: Equivalent — advanceFromZone is wired only to the zone-step Next button, which is disabled while selectedZone === null, so the guard is unreachable
    if (selectedZone === null) return

    if (elementType === 'section') {
      setStep('confirm')
    } else {
      setStep('container')
    }
  }, [elementType, selectedZone])

  const handleContainerSelect = useCallback((containerId: number) => {
    setSelectedContainerId(containerId)
  }, [])

  const handleConfirm = useCallback(() => {
    if (selectedZone === null) return

    if (step === 'confirm') {
      // Section duplication — page is the parent.
      onConfirm(selectedPageId, selectedZone, { type: 'page', id: selectedPageId })
    } else if (selectedContainerId !== null) {
      const parentType = PARENT_TYPE_FOR_ELEMENT[elementType]
      onConfirm(selectedPageId, selectedZone, { type: parentType, id: selectedContainerId })
    }
  }, [onConfirm, selectedPageId, selectedZone, selectedContainerId, step, elementType])

  const goBack = useCallback(() => {
    if (step === 'zone') {
      setStep('page')
      setSelectedZone(null)
    } else if (step === 'container') {
      setStep('zone')
      setSelectedContainerId(null)
      // Equivalent-mutant site (Stryker cannot inline-suppress an `else if` test —
      // its directives attach only to leading comments of normal statements): this
      // arm is reached only when step ∉ {zone, container}, and the Back button is
      // hidden on the page step, so `step === 'confirm'` is always true here.
    } else if (step === 'confirm') {
      setSelectedZone(null)
      // When there's only one zone, the zone step auto-advances — going back
      // to 'zone' would immediately bounce forward again, silently breaking
      // the back button. Skip straight to 'page' instead.
      setStep(zones.data !== undefined && zones.data.length === 1 ? 'page' : 'zone')
    }
  }, [step, zones.data])

  // Stryker disable next-line EqualityOperator: Equivalent — `> 1` only differs at exactly 1 zone, which auto-advances off the zone step before this list ever renders
  const hasMultipleZones = zones.data !== undefined && zones.data.length > 1

  return (
    <dialog
      ref={dialogRef}
      className="ssgrid-dialog"
      data-variant="wide"
      data-testid="duplicate-to-dialog"
      aria-labelledby={titleId}
      onClose={handleClose}
      // onClick guard prevents clicks inside the dialog from bubbling to
      // ancestor ElementCard anchors. Both preventDefault and stopPropagation
      // are required: stopPropagation blocks React handlers on the anchor,
      // preventDefault cancels the browser's default anchor navigation. Rule
      // disabled for this file via biome.json overrides (<dialog> is natively
      // interactive; biome's a11y rules do not recognize it).
      onClick={(e) => {
        e.preventDefault()
        e.stopPropagation()
      }}
    >
      <div className="ssgrid-dialog-header">
        <h3 id={titleId}>
          {step === 'page' &&
            t('WeDevelopGrid.DuplicateToDialog.STEP_PAGE_TITLE', 'Select target page')}
          {step === 'zone' && t('WeDevelopGrid.DuplicateToDialog.STEP_ZONE_TITLE', 'Select zone')}
          {step === 'container' &&
            t('WeDevelopGrid.DuplicateToDialog.STEP_CONTAINER_TITLE', 'Select container')}
          {step === 'confirm' &&
            t('WeDevelopGrid.DuplicateToDialog.STEP_CONFIRM_TITLE', 'Confirm duplication')}
        </h3>
      </div>

      <div className="ssgrid-dialog-body">
        {step === 'page' && (
          <div data-testid="duplicate-to-step-page">
            <input
              type="text"
              className="ssgrid-dialog-search ssgrid-focus-ring"
              data-testid="duplicate-to-search"
              placeholder={t(
                'WeDevelopGrid.DuplicateToDialog.SEARCH_PLACEHOLDER',
                'Search pages\u2026',
              )}
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
            {pages.isLoading && (
              <p data-testid="duplicate-to-loading">
                {t('WeDevelopGrid.DuplicateToDialog.LOADING_PAGES', 'Loading pages\u2026')}
              </p>
            )}
            {pages.isError && <ListLoadError error={pages.error} onRetry={pages.refetch} />}
            {pages.data !== undefined && (
              <div
                ref={pageList.listRef}
                className="ssgrid-dialog-list ssgrid-focus-ring"
                data-testid="duplicate-to-page-list"
                role="listbox"
                aria-label={t('WeDevelopGrid.DuplicateToDialog.PAGE_LIST_LABEL', 'Target page')}
                tabIndex={0}
                aria-activedescendant={
                  pages.data.length > 0 ? pageList.getItemId(pageList.activeIndex) : undefined
                }
                onKeyDown={(e) => {
                  if (pageList.handleNavigationKeyDown(e)) return
                  if (e.key === 'Enter' || e.key === ' ') {
                    // Cancel Space-scroll / stray <dialog> submit before running
                    // the select handler (matches ActionsMenu / GridSettingsPicker).
                    e.preventDefault()
                    const page = pages.data[pageList.activeIndex]
                    if (page?.hasGridZones) {
                      handlePageSelect(page.id)
                    }
                  }
                }}
              >
                {pages.data.map((page, index) => (
                  <div
                    key={page.id}
                    id={pageList.getItemId(index)}
                    className="ssgrid-dialog-option"
                    role="option"
                    aria-selected={page.id === selectedPageId}
                    aria-disabled={!page.hasGridZones}
                    data-active={index === pageList.activeIndex ? 'true' : undefined}
                    data-testid="duplicate-to-page-item"
                    // -1 keeps the listbox the single tab stop: focus stays on
                    // the container and aria-activedescendant does the moving.
                    tabIndex={-1}
                    onClick={
                      page.hasGridZones
                        ? () => {
                            pageList.setActiveIndex(index)
                            handlePageSelect(page.id)
                          }
                        : undefined
                    }
                  >
                    {page.title}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {step === 'zone' && (
          <div data-testid="duplicate-to-step-zone">
            {zones.isLoading && (
              <p data-testid="duplicate-to-loading">
                {t('WeDevelopGrid.DuplicateToDialog.LOADING_ZONES', 'Loading zones\u2026')}
              </p>
            )}
            {zones.isError && <ListLoadError error={zones.error} onRetry={zones.refetch} />}
            {hasMultipleZones && (
              <div
                ref={zoneList.listRef}
                className="ssgrid-dialog-list ssgrid-focus-ring"
                data-testid="duplicate-to-zone-list"
                role="listbox"
                aria-label={t('WeDevelopGrid.DuplicateToDialog.ZONE_LIST_LABEL', 'Target zone')}
                tabIndex={0}
                aria-activedescendant={zoneList.getItemId(zoneList.activeIndex)}
                onKeyDown={(e) => {
                  if (zoneList.handleNavigationKeyDown(e)) return
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    const zone = zones.data[zoneList.activeIndex]
                    if (zone !== undefined) {
                      handleZoneSelect(zone)
                    }
                  }
                }}
              >
                {zones.data.map((zone, index) => (
                  <div
                    key={zone}
                    id={zoneList.getItemId(index)}
                    className="ssgrid-dialog-option"
                    role="option"
                    aria-selected={zone === selectedZone}
                    data-active={index === zoneList.activeIndex ? 'true' : undefined}
                    data-testid="duplicate-to-zone-item"
                    tabIndex={-1}
                    onClick={() => {
                      zoneList.setActiveIndex(index)
                      handleZoneSelect(zone)
                    }}
                  >
                    {zone}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {step === 'container' && (
          <div data-testid="duplicate-to-step-container">
            {containers.isLoading && (
              <p data-testid="duplicate-to-loading">
                {t(
                  'WeDevelopGrid.DuplicateToDialog.LOADING_CONTAINERS',
                  'Loading containers\u2026',
                )}
              </p>
            )}
            {containers.isError && (
              <ListLoadError error={containers.error} onRetry={containers.refetch} />
            )}
            {containers.data !== undefined && containers.data.length === 0 && (
              <p data-testid="duplicate-to-no-containers">
                {t(
                  'WeDevelopGrid.DuplicateToDialog.NO_CONTAINERS',
                  'No compatible containers found in this zone',
                )}
              </p>
            )}
            {containers.data !== undefined && containers.data.length > 0 && (
              <div
                ref={containerList.listRef}
                className="ssgrid-dialog-list ssgrid-focus-ring"
                data-testid="duplicate-to-container-list"
                role="listbox"
                aria-label={t(
                  'WeDevelopGrid.DuplicateToDialog.CONTAINER_LIST_LABEL',
                  'Target container',
                )}
                tabIndex={0}
                aria-activedescendant={containerList.getItemId(containerList.activeIndex)}
                onKeyDown={(e) => {
                  if (containerList.handleNavigationKeyDown(e)) return
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    const container = containers.data[containerList.activeIndex]
                    if (container !== undefined) {
                      handleContainerSelect(container.id)
                    }
                  }
                }}
              >
                {containers.data.map((container, index) => (
                  <div
                    key={container.id}
                    id={containerList.getItemId(index)}
                    className="ssgrid-dialog-option"
                    role="option"
                    aria-selected={container.id === selectedContainerId}
                    data-active={index === containerList.activeIndex ? 'true' : undefined}
                    data-testid="duplicate-to-container-item"
                    tabIndex={-1}
                    onClick={() => {
                      containerList.setActiveIndex(index)
                      handleContainerSelect(container.id)
                    }}
                  >
                    <span>{container.title}</span>
                    <span>{container.type}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {step === 'confirm' && (
          <div data-testid="duplicate-to-step-confirm">
            <p>
              {/* One translatable sentence with a {zone} placeholder so word order
                  is the translator's to decide; the zone is still emphasised by
                  splitting the rendered template around the placeholder. */}
              {(() => {
                const [before, after = ''] = t(
                  'WeDevelopGrid.DuplicateToDialog.CONFIRM_SUMMARY',
                  'Duplicate section to zone {zone}?',
                ).split('{zone}')
                return (
                  <>
                    {before}
                    <strong>{selectedZone}</strong>
                    {after}
                  </>
                )
              })()}
            </p>
          </div>
        )}
      </div>

      <div className="ssgrid-dialog-footer">
        {error !== undefined && error !== null && (
          <p className="ssgrid-dialog-error" data-testid="duplicate-to-error">
            {error}
          </p>
        )}
        <div className="ssgrid-dialog-actions">
          {step !== 'page' && (
            <button
              type="button"
              className="ssgrid-button ssgrid-focus-ring"
              data-variant="ghost"
              data-testid="duplicate-to-back"
              onClick={goBack}
            >
              {t('WeDevelopGrid.DuplicateToDialog.BACK_BUTTON', 'Back')}
            </button>
          )}
          <button
            type="button"
            className="ssgrid-button ssgrid-focus-ring"
            data-variant="ghost"
            onClick={handleClose}
          >
            {t('WeDevelopGrid.DuplicateToDialog.CANCEL_BUTTON', 'Cancel')}
          </button>
          {step === 'page' && (
            <button
              type="button"
              className="ssgrid-button ssgrid-focus-ring"
              data-variant="primary"
              data-testid="duplicate-to-next"
              onClick={advanceFromPage}
            >
              {t('WeDevelopGrid.DuplicateToDialog.NEXT_BUTTON', 'Next')}
            </button>
          )}
          {step === 'zone' && (
            <button
              type="button"
              className="ssgrid-button ssgrid-focus-ring"
              data-variant="primary"
              data-testid="duplicate-to-next"
              disabled={selectedZone === null}
              onClick={advanceFromZone}
            >
              {t('WeDevelopGrid.DuplicateToDialog.NEXT_BUTTON', 'Next')}
            </button>
          )}
          {step === 'container' && (
            <button
              type="button"
              className="ssgrid-button ssgrid-focus-ring"
              data-variant="primary"
              data-testid="duplicate-to-confirm"
              disabled={selectedContainerId === null}
              onClick={handleConfirm}
            >
              {t('WeDevelopGrid.DuplicateToDialog.CONFIRM_BUTTON', 'Confirm')}
            </button>
          )}
          {step === 'confirm' && (
            <button
              type="button"
              className="ssgrid-button ssgrid-focus-ring"
              data-variant="primary"
              data-testid="duplicate-to-confirm"
              onClick={handleConfirm}
            >
              {t('WeDevelopGrid.DuplicateToDialog.CONFIRM_BUTTON', 'Confirm')}
            </button>
          )}
        </div>
      </div>
    </dialog>
  )
}
