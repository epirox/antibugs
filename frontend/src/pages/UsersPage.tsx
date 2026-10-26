import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { api, ApiError } from '../api/client';
import type { Role, User, UserInput } from '../api/types';
import { ROLE_LABELS } from '../api/types';
import { useAuth } from '../hooks/useAuth';

const ROLES = Object.keys(ROLE_LABELS) as Role[];

/**
 * CU-A01: gestión de usuarios y asignación de roles (sólo ADMIN).
 */
export function UsersPage() {
  const { user: me } = useAuth();
  const [users, setUsers] = useState<User[]>([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [role, setRole] = useState<Role | ''>('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<User | 'new' | null>(null);
  const [deleting, setDeleting] = useState<User | null>(null);

  const load = useCallback(() => {
    const params: Record<string, string> = { page: String(page) };
    if (role) params.role = role;
    api.users
      .list(params)
      .then((res) => {
        setUsers(res.data);
        setMeta(res.meta);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, role]);

  useEffect(load, [load]);

  async function confirmDelete() {
    if (!deleting) return;
    try {
      await api.users.remove(deleting.id);
      setDeleting(null);
      load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'No se pudo eliminar el usuario');
      setDeleting(null);
    }
  }

  return (
    <>
      <div className="page-header">
        <h1>Usuarios y roles</h1>
        <button onClick={() => setEditing('new')}>+ Nuevo usuario</button>
      </div>

      <div className="filters card">
        <label>
          Rol
          <select
            value={role}
            onChange={(e) => {
              setRole(e.target.value as Role | '');
              setPage(1);
            }}
          >
            <option value="">Todos</option>
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {ROLE_LABELS[r]}
              </option>
            ))}
          </select>
        </label>
        <span className="muted">{meta.total} usuario(s)</span>
      </div>

      {error && <p className="error">{error}</p>}
      {loading && <p className="muted">Cargando…</p>}

      {!loading && (
        <table className="card">
          <thead>
            <tr>
              <th>#</th>
              <th>Nombre</th>
              <th>Email</th>
              <th>Rol</th>
              <th>Alta</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id}>
                <td>{u.id}</td>
                <td>
                  {u.name} {u.id === me?.id && <small className="muted">(vos)</small>}
                </td>
                <td>{u.email}</td>
                <td>
                  <RoleBadge role={u.role} />
                </td>
                <td>{u.createdAt ? new Date(u.createdAt).toLocaleDateString() : '—'}</td>
                <td className="row-actions">
                  <button className="secondary small" onClick={() => setEditing(u)}>
                    Editar
                  </button>
                  <button
                    className="secondary small danger"
                    onClick={() => setDeleting(u)}
                    disabled={u.id === me?.id}
                    title={u.id === me?.id ? 'No podés eliminar tu propio usuario' : undefined}
                  >
                    Eliminar
                  </button>
                </td>
              </tr>
            ))}
            {users.length === 0 && (
              <tr>
                <td colSpan={6} className="muted">
                  No hay usuarios para mostrar.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      )}

      {meta.last_page > 1 && (
        <div className="pagination">
          <button className="secondary small" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            ← Anterior
          </button>
          <span className="muted">
            Página {meta.current_page} de {meta.last_page}
          </span>
          <button
            className="secondary small"
            disabled={page >= meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Siguiente →
          </button>
        </div>
      )}

      {editing && (
        <UserFormModal
          user={editing === 'new' ? null : editing}
          isSelf={editing !== 'new' && editing.id === me?.id}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            load();
          }}
        />
      )}

      {deleting && (
        <div className="modal-backdrop" onClick={() => setDeleting(null)}>
          <div className="modal" onClick={(e) => e.stopPropagation()}>
            <h3>Eliminar usuario</h3>
            <p>
              ¿Seguro que querés eliminar a <strong>{deleting.name}</strong> ({deleting.email})?
            </p>
            <p className="muted">
              Los tickets que creó se eliminarán y los que tenía asignados quedarán sin técnico.
            </p>
            <div className="actions">
              <button className="secondary" onClick={() => setDeleting(null)}>
                Cancelar
              </button>
              <button className="danger" onClick={confirmDelete}>
                Eliminar
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export function RoleBadge({ role }: { role: Role }) {
  const color = role === 'ADMIN' ? '#7c3aed' : role === 'TECHNICIAN' ? '#0891b2' : '#6b7280';
  return (
    <span className="badge outline" style={{ borderColor: color, color }}>
      {ROLE_LABELS[role]}
    </span>
  );
}

interface FormProps {
  user: User | null;
  isSelf: boolean;
  onClose: () => void;
  onSaved: () => void;
}

function UserFormModal({ user, isSelf, onClose, onSaved }: FormProps) {
  const isNew = user === null;
  const [name, setName] = useState(user?.name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [role, setRole] = useState<Role>(user?.role ?? 'USER');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      if (isNew) {
        await api.users.create({ name, email, role, password });
      } else {
        const body: Partial<UserInput> = { name, email, role };
        if (password) body.password = password;
        await api.users.update(user.id, body);
      }
      onSaved();
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(err.errors);
      else setErrors({ general: [err instanceof Error ? err.message : 'Error inesperado'] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <form className="modal" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h3>{isNew ? 'Nuevo usuario' : `Editar: ${user.name}`}</h3>

        <label>
          Nombre
          <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} autoFocus />
          {errors.name && <small className="error">{errors.name[0]}</small>}
        </label>

        <label>
          Email
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          {errors.email && <small className="error">{errors.email[0]}</small>}
        </label>

        <label>
          Rol
          <select value={role} onChange={(e) => setRole(e.target.value as Role)} disabled={isSelf}>
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {ROLE_LABELS[r]}
              </option>
            ))}
          </select>
          {isSelf && <small className="muted">No podés cambiar tu propio rol.</small>}
          {errors.role && <small className="error">{errors.role[0]}</small>}
        </label>

        <label>
          {isNew ? 'Contraseña' : 'Nueva contraseña'}{' '}
          {!isNew && <small className="muted">(dejar vacío para no cambiarla)</small>}
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required={isNew}
            minLength={8}
            autoComplete="new-password"
          />
          {errors.password && <small className="error">{errors.password[0]}</small>}
        </label>

        {errors.general && <p className="error">{errors.general[0]}</p>}

        <div className="actions">
          <button type="button" className="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </button>
          <button type="submit" disabled={saving}>
            {saving ? 'Guardando…' : isNew ? 'Crear usuario' : 'Guardar cambios'}
          </button>
        </div>
      </form>
    </div>
  );
}
