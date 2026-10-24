import type { TicketStatus } from '../api/types';
import { STATUS_LABELS } from '../api/types';

const COLORS: Record<TicketStatus, string> = {
  OPEN: '#2563eb',
  ASSIGNED: '#7c3aed',
  IN_PROGRESS: '#d97706',
  PENDING_INFO: '#6b7280',
  RESOLVED: '#059669',
  CLOSED: '#374151',
};

export function StatusBadge({ status }: { status: TicketStatus }) {
  return (
    <span className="badge" style={{ background: COLORS[status] }}>
      {STATUS_LABELS[status]}
    </span>
  );
}

export function PriorityBadge({ name, level }: { name: string; level: number }) {
  const color = level >= 4 ? '#dc2626' : level === 3 ? '#ea580c' : level === 2 ? '#ca8a04' : '#16a34a';
  return (
    <span className="badge outline" style={{ borderColor: color, color }}>
      {name}
    </span>
  );
}
