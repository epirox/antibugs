import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, ApiError } from '../api/client';
import type { Ticket, TicketStatus } from '../api/types';
import { STATUS_LABELS } from '../api/types';
import { PriorityBadge, StatusBadge } from '../components/Badges';
import { StatusChangeModal } from '../components/StatusChangeModal';
import { useAuth } from '../hooks/useAuth';

export function TicketDetailPage() {
  const { id } = useParams();
  const { user } = useAuth();
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [target, setTarget] = useState<TicketStatus | null>(null);
  const [comment, setComment] = useState('');
  const [sending, setSending] = useState(false);

  const load = useCallback(() => {
    api.tickets
      .get(Number(id))
      .then((r) => setTicket(r.data))
      .catch((e: ApiError) => setError(e.status === 404 ? 'El ticket no existe.' : e.message));
  }, [id]);

  useEffect(load, [load]);

  async function sendComment(e: FormEvent) {
    e.preventDefault();
    if (!ticket) return;
    setSending(true);
    try {
      await api.tickets.comment(ticket.id, comment);
      setComment('');
      load();
    } finally {
      setSending(false);
    }
  }

  if (error) {
    return (
      <div className="card">
        <p className="error">{error}</p>
        <Link to="/">← Volver</Link>
      </div>
    );
  }
  if (!ticket || !user) return <p className="muted">Cargando…</p>;

  const isStaff = user.role !== 'USER';
  // Un usuario común sólo puede confirmar el cierre (CU-T03); el staff maneja el resto.
  const transitions = ticket.allowedTransitions.filter((s) => (isStaff ? s !== 'CLOSED' || user.role === 'ADMIN' : s === 'CLOSED'));

  return (
    <>
      <Link to="/" className="muted">
        ← Volver al dashboard
      </Link>

      <div className="card ticket-head">
        <div>
          <h1>
            #{ticket.id} · {ticket.title}
          </h1>
          <p className="muted">{ticket.summary}</p>
          <div className="badges">
            <StatusBadge status={ticket.status} />
            <PriorityBadge name={ticket.priority.name} level={ticket.priority.level} />
            <span className="badge outline">{ticket.category.name}</span>
          </div>
        </div>
        <dl>
          <dt>Solicitante</dt>
          <dd>{ticket.createdBy.name}</dd>
          <dt>Técnico</dt>
          <dd>{ticket.assignedTo?.name ?? '—'}</dd>
          <dt>Creado</dt>
          <dd>{new Date(ticket.createdAt).toLocaleString()}</dd>
        </dl>
      </div>

      <div className="card">
        <h2>Descripción</h2>
        <p className="prewrap">{ticket.description}</p>
        {ticket.resolution && (
          <>
            <h2>Solución</h2>
            <p className="prewrap success">{ticket.resolution}</p>
          </>
        )}
      </div>

      {transitions.length > 0 && (
        <div className="card">
          <h2>Cambiar estado</h2>
          <div className="actions start">
            {transitions.map((s) => (
              <button key={s} onClick={() => setTarget(s)}>
                → {STATUS_LABELS[s]}
              </button>
            ))}
          </div>
        </div>
      )}

      <div className="grid-2">
        <section className="card">
          <h2>Comentarios</h2>
          <form onSubmit={sendComment} className="comment-form">
            <textarea
              rows={3}
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="Agregar información adicional…"
              required
              minLength={2}
            />
            <button type="submit" disabled={sending}>
              Enviar
            </button>
          </form>
          <ul className="timeline">
            {ticket.comments?.map((c) => (
              <li key={c.id}>
                <strong>{c.author.name}</strong> <small className="muted">{new Date(c.createdAt).toLocaleString()}</small>
                <p className="prewrap">{c.body}</p>
              </li>
            ))}
            {ticket.comments?.length === 0 && <li className="muted">Sin comentarios aún.</li>}
          </ul>
        </section>

        <section className="card">
          <h2>Historial</h2>
          <ul className="timeline">
            {ticket.history?.map((h) => (
              <li key={h.id}>
                <div>
                  {h.from ? <StatusBadge status={h.from} /> : <span className="badge outline">Nuevo</span>} →{' '}
                  <StatusBadge status={h.to} />
                </div>
                <small className="muted">
                  {h.actor.name} · {new Date(h.createdAt).toLocaleString()}
                  {h.assignedTo && <> · asignado a {h.assignedTo.name}</>}
                </small>
                <p className="prewrap">{h.justification}</p>
              </li>
            ))}
          </ul>
        </section>
      </div>

      {target && (
        <StatusChangeModal
          ticket={ticket}
          target={target}
          canAssign={isStaff}
          onClose={() => setTarget(null)}
          onDone={(updated) => {
            setTarget(null);
            setTicket(updated);
            load();
          }}
        />
      )}
    </>
  );
}
