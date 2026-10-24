import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { ApiError } from '../api/client';
import { useAuth } from '../hooks/useAuth';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('usuario@antibugs.local');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      await login(email, password);
      navigate('/');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo iniciar sesión');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="login-wrapper">
      <form className="card login" onSubmit={submit}>
        <h1>🐛 AntiBugs</h1>
        <p className="muted">Controlá la verdadera plaga en tu sistema.</p>

        <label>
          Email
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
        </label>
        <label>
          Contraseña
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>

        {error && <p className="error">{error}</p>}

        <button type="submit" disabled={loading}>
          {loading ? 'Ingresando…' : 'Ingresar'}
        </button>

        <details className="muted">
          <summary>Cuentas demo</summary>
          <ul>
            <li>usuario@antibugs.local</li>
            <li>tecnico@antibugs.local</li>
            <li>admin@antibugs.local</li>
          </ul>
          <small>Contraseña: password</small>
        </details>
      </form>
    </div>
  );
}
