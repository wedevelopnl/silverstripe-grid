interface EmptyStateProps {
  readonly message: string
  readonly variant?: 'centered'
}

export default function EmptyState({ message, variant }: EmptyStateProps) {
  return (
    <div
      className="ssgrid-empty-state"
      data-testid="empty-state"
      data-variant={variant ?? undefined}
    >
      {message}
    </div>
  )
}
