import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError } from '../api/client';
import type { Category, Priority } from '../api/types';

export function NewTicketPage() {
  const navigate = useNavigate();
  const [categories, setCategories] = useState<Category[]>([]);
  const [priorities, setPriorities] = useState<Priority[]>([]);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [priorityId, setPriorityId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.taxonomy.categories().then((r) => setCategories(r.data));
    api.taxonomy.priorities().then((r) => setPriorities(r.data));
  }, []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const res = await api.tickets.create({
        title,
        description,
        categoryId: Number(categoryId),
        ...(priorityId ? { priorityId: Number(priorityId) } : {}),
      });
      navigate(`/tickets/${res.data.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(err.errors);
      else setErrors({ general: ['No se pudo crear el ticket'] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <form className="card form" onSubmit={submit}>
      <h1>Nuevo incidente</h1>

      <label>
        Título
        <input value={title} onChange={(e) => setTitle(e.target.value)} required minLength={5} maxLength={255} />
        {errors.title && <small className="error">{errors.title[0]}</small>}
      </label>

      <label>
        Descripción
        <textarea
          rows={5}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          required
          minLength={10}
          placeholder="Describí el problema con el mayor detalle posible…"
        />
        {errors.description && <small className="error">{errors.description[0]}</small>}
      </label>

      <label>
        Categoría
        <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} required>
          <option value="">Seleccionar…</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
        {errors.categoryId && <small className="error">{errors.categoryId[0]}</small>}
      </label>

      <label>
        Prioridad <small className="muted">(opcional: se infiere automáticamente de la descripción)</small>
        <select value={priorityId} onChange={(e) => setPriorityId(e.target.value)}>
          <option value="">Automática</option>
          {priorities.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </label>

      {errors.general && <p className="error">{errors.general[0]}</p>}

      <div className="actions">
        <button type="button" className="secondary" onClick={() => navigate(-1)}>
          Cancelar
        </button>
        <button type="submit" disabled={saving}>
          {saving ? 'Creando…' : 'Crear ticket'}
        </button>
      </div>
    </form>
  );
}
