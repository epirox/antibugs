import { Link, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

export function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  async function handleLogout() {
    await logout();
    navigate('/login');
  }

  return (
    <>
      <header className="topbar">
        <Link to="/" className="brand">
          🐛 AntiBugs
        </Link>
        <nav>
          <Link to="/">Dashboard</Link>
          {user?.role === 'USER' && <Link to="/tickets/new">Nuevo ticket</Link>}
          {user?.role === 'ADMIN' && <Link to="/admin/users">Usuarios</Link>}
        </nav>
        <div className="userbox">
          <span>
            {user?.name} <small>({user?.role})</small>
          </span>
          <button className="secondary" onClick={handleLogout}>
            Salir
          </button>
        </div>
      </header>
      <main className="container">
        <Outlet />
      </main>
    </>
  );
}
