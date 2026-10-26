export type Role = 'USER' | 'TECHNICIAN' | 'ADMIN';

export type TicketStatus =
  | 'OPEN'
  | 'ASSIGNED'
  | 'IN_PROGRESS'
  | 'PENDING_INFO'
  | 'RESOLVED'
  | 'CLOSED';

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  createdAt?: string;
}

export interface UserInput {
  name: string;
  email: string;
  role: Role;
  password?: string;
}

export const ROLE_LABELS: Record<Role, string> = {
  USER: 'Usuario',
  TECHNICIAN: 'Técnico',
  ADMIN: 'Administrador',
};

export interface Category {
  id: number;
  name: string;
  description?: string | null;
}

export interface Priority {
  id: number;
  name: string;
  level: number;
}

export interface Comment {
  id: number;
  body: string;
  attachmentUrl: string | null;
  author: User;
  createdAt: string;
}

export interface HistoryEntry {
  id: number;
  from: TicketStatus | null;
  to: TicketStatus;
  justification: string;
  actor: User;
  assignedTo?: User | null;
  createdAt: string;
}

export interface Ticket {
  id: number;
  title: string;
  description: string;
  status: TicketStatus;
  statusLabel: string;
  summary: string;
  allowedTransitions: TicketStatus[];
  category: Category;
  priority: Priority;
  createdBy: User;
  assignedTo: User | null;
  resolution: string | null;
  resolvedAt: string | null;
  closedAt: string | null;
  comments?: Comment[];
  history?: HistoryEntry[];
  createdAt: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
}

export interface LoginResponse {
  accessToken: string;
  tokenType: string;
  expiresIn: number;
  user: User;
}

export const STATUS_LABELS: Record<TicketStatus, string> = {
  OPEN: 'Abierto',
  ASSIGNED: 'Asignado',
  IN_PROGRESS: 'En proceso',
  PENDING_INFO: 'Pendiente de información',
  RESOLVED: 'Resuelto',
  CLOSED: 'Cerrado',
};
