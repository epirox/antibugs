import type {
  Category,
  Comment,
  LoginResponse,
  Paginated,
  Priority,
  Ticket,
  TicketStatus,
  User,
  UserInput,
} from './types';

const BASE_URL = import.meta.env.VITE_API_URL ?? '/api/v1';
const TOKEN_KEY = 'antibugs.token';

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(status: number, message: string, errors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

export const tokenStorage = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(init.headers as Record<string, string> | undefined),
  };

  if (!(init.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  const token = tokenStorage.get();
  if (token) headers.Authorization = `Bearer ${token}`;

  const response = await fetch(`${BASE_URL}${path}`, { ...init, headers });

  if (response.status === 204) return undefined as T;

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    if (response.status === 401) tokenStorage.clear();
    throw new ApiError(response.status, payload.message ?? 'Error inesperado', payload.errors);
  }

  return payload as T;
}

export const api = {
  auth: {
    login: (email: string, password: string) =>
      request<LoginResponse>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      }),
    me: () => request<{ data: User }>('/auth/me'),
    logout: () => request<{ message: string }>('/auth/logout', { method: 'POST' }),
  },

  taxonomy: {
    categories: () => request<{ data: Category[] }>('/categories'),
    priorities: () => request<{ data: Priority[] }>('/priorities'),
  },

  tickets: {
    list: (params: Record<string, string> = {}) =>
      request<Paginated<Ticket>>(`/tickets?${new URLSearchParams(params)}`),
    get: (id: number) => request<{ data: Ticket }>(`/tickets/${id}`),
    create: (body: { title: string; description: string; categoryId: number; priorityId?: number }) =>
      request<{ data: Ticket }>('/tickets', { method: 'POST', body: JSON.stringify(body) }),
    changeStatus: (
      id: number,
      body: { status: TicketStatus; justification: string; assignedToUserId?: number; resolution?: string },
    ) => request<{ data: Ticket }>(`/tickets/${id}/status`, { method: 'PATCH', body: JSON.stringify(body) }),
    comment: (id: number, body: string) =>
      request<{ data: Comment }>(`/tickets/${id}/comments`, { method: 'POST', body: JSON.stringify({ body }) }),
  },

  users: {
    list: (params: Record<string, string> = {}) =>
      request<Paginated<User>>(`/users?${new URLSearchParams(params)}`),
    technicians: () => request<{ data: User[] }>('/technicians'),
    create: (body: UserInput) => request<{ data: User }>('/users', { method: 'POST', body: JSON.stringify(body) }),
    update: (id: number, body: Partial<UserInput>) =>
      request<{ data: User }>(`/users/${id}`, { method: 'PATCH', body: JSON.stringify(body) }),
    remove: (id: number) => request<void>(`/users/${id}`, { method: 'DELETE' }),
  },
};
