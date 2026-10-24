import { useEffect, useState, type FormEvent } from 'react';
import { api, ApiError } from '../api/client';
import type { Ticket, TicketStatus, User } from '../api/types';
import { STATUS_LABELS } from '../api/types';

interface Props {
  ticket: Ticket;
  target: TicketStatus;
  canAssign: boolean;
  onClose: () => void;
  onDone: (ticket: Ticket) => void;
}

/**
 * Ventana de "Justificación obligatoria" (CU-T02): toda transición exige
 * una razón escrita; al resolver, además, la solución detallada.
 */
export function StatusChangeModal({ ticket, target, canAssign, onClose, onDone }: Props) {
  const [justification, setJustification] = useState('');
  const [resolution, setResolution] = useState('');
  const [assignedToUserId, setAssignedToUserId] = useState<number | ''>(ticket.assignedTo?.id ?? '');
  const [technicians, setTechnicians] = useState<User[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const needsResolution = target === 'RESOLVED';
  const showAssign = canAssign && ['ASSIGNED', 'IN_PROGRESS'].includes(target);

  useEffect(() => {
    if (!showAssign) return;
    api.users
      .technicians()
      .then((res) => setTechnicians(res.data))
      .catch(() => setTechnicians([]));
  }, [showAssign]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await api.tickets.changeStatus(ticket.id, {
        status: target,
        justification,
        ...(needsResolution ? { resolution } : {}),
        ...(showAssign && assignedToUserId !== '' ? { assignedToUserId: Number(assignedToUserId) } : {}),
      });
      onDone(res.data);
    } catch (err) {
      const message =
        err instanceof ApiError
          ? Object.values(err.errors ?? {}).flat()[0] ?? err.message
          : 'Error inesperado';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <form className="modal" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h3>
          Cambiar estado: {STATUS_LABELS[ticket.status]} → {STATUS_LABELS[target]}
        </h3>

        {showAssign && technicians.length > 0 && (
          <label>
            Asignar a
            <select
              value={assignedToUserId}
              onChange={(e) => setAssignedToUserId(e.target.value === '' ? '' : Number(e.target.value))}
            >
              <option value="">(yo mismo)</option>
              {technicians.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.role})
                </option>
              ))}
            </select>
          </label>
        )}

        <label>
          Justificación <span className="required">*obligatoria</span>
          <textarea
            required
            minLength={10}
            rows={3}
            value={justification}
            onChange={(e) => setJustification(e.target.value)}
            placeholder="Explicá por qué se realiza este cambio de estado…"
          />
        </label>

        {needsResolution && (
          <label>
            Solución detallada <span className="required">*</span>
            <textarea
              required
              minLength={10}
              rows={4}
              value={resolution}
              onChange={(e) => setResolution(e.target.value)}
              placeholder="Describí la solución aplicada…"
            />
          </label>
        )}

        {error && <p className="error">{error}</p>}

        <div className="actions">
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </button>
          <button type="submit" disabled={saving}>
            {saving ? 'Guardando…' : 'Confirmar'}
          </button>
        </div>
      </form>
    </div>
  );
}
