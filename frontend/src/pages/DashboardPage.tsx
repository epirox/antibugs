import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import type { Category, Ticket, TicketStatus } from '../api/types';
import { STATUS_LABELS } from '../api/types';
import { PriorityBadge, StatusBadge } from '../components/Badges';
import { useAuth } from '../hooks/useAuth';

export function DashboardPage() {
  const { user } = useAuth();
  const isStaff = user?.role !== 'USER';

  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [status, setStatus] = useState<TicketStatus | ''>('');
  const [categoryId, setCategoryId] = useState('');
  const [onlyMine, setOnlyMine] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    const params: Record<string, string> = {};
    if (status) params.status = status;
    if (categoryId) params.categoryId = categoryId;
    if (onlyMine) params.mine = '1';

    api.tickets
      .list(params)
      .then((res) => setTickets(res.data))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status, categoryId, onlyMine]);

  useEffect(load, [load]);
  useEffect(() => {
    api.taxonomy.categories().then((r) => setCategories(r.data)).catch(() => undefined);
  }, []);

  // Vista técnico: cola agrupada por prioridad (el backend ya la ordena por nivel desc).
  const grouped = tickets.reduce<Record<string, Ticket[]>>((acc, t) => {
    (acc[t.priority.name] ??= []).push(t);
    return acc;
  }, {});

  return (
    <>
      <div className="page-header">
        <h1>{isStaff ? 'Cola de incidentes' : 'Mis tickets'}</h1>
        {!isStaff && (
          <Link to="/tickets/new" className="button">
            + Nuevo ticket
          </Link>
        )}
      </div>

      <div className="filters card">
        <label>
          Estado
          <select value={status} onChange={(e) => setStatus(e.target.value as TicketStatus | '')}>
            <option value="">Todos</option>
            {Object.entries(STATUS_LABELS).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </label>
        <label>
          Categoría
          <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
            <option value="">Todas</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
        {isStaff && (
          <label className="checkbox">
            <input type="checkbox" checked={onlyMine} onChange={(e) => setOnlyMine(e.target.checked)} />
            Asignados a mí
          </label>
        )}
      </div>

      {error && <p className="error">{error}</p>}
      {loading && <p className="muted">Cargando…</p>}
      {!loading && tickets.length === 0 && <p className="muted">No hay tickets para mostrar.</p>}

      {isStaff
        ? Object.entries(grouped).map(([priority, items]) => (
            <section key={priority} className="priority-group">
              <h2>
                <PriorityBadge name={priority} level={items[0].priority.level} /> {items.length} ticket(s)
              </h2>
              <TicketTable tickets={items} showCreator />
            </section>
          ))
        : tickets.length > 0 && <TicketTable tickets={tickets} />}
    </>
  );
}

function TicketTable({ tickets, showCreator = false }: { tickets: Ticket[]; showCreator?: boolean }) {
  return (
    <table className="card">
      <thead>
        <tr>
          <th>#</th>
          <th>Título</th>
          <th>Categoría</th>
          <th>Prioridad</th>
          <th>Estado</th>
          {showCreator && <th>Solicitante</th>}
          <th>Técnico</th>
          <th>Creado</th>
        </tr>
      </thead>
      <tbody>
        {tickets.map((t) => (
          <tr key={t.id}>
            <td>{t.id}</td>
            <td>
              <Link to={`/tickets/${t.id}`}>{t.title}</Link>
            </td>
            <td>{t.category.name}</td>
            <td>
              <PriorityBadge name={t.priority.name} level={t.priority.level} />
            </td>
            <td>
              <StatusBadge status={t.status} />
            </td>
            {showCreator && <td>{t.createdBy.name}</td>}
            <td>{t.assignedTo?.name ?? <span className="muted">—</span>}</td>
            <td>{new Date(t.createdAt).toLocaleDateString()}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
