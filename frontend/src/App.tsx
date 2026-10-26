import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom';
import type { Role } from './api/types';
import { Layout } from './components/Layout';
import { AuthProvider, useAuth } from './hooks/useAuth';
import { DashboardPage } from './pages/DashboardPage';
import { LoginPage } from './pages/LoginPage';
import { NewTicketPage } from './pages/NewTicketPage';
import { TicketDetailPage } from './pages/TicketDetailPage';
import { UsersPage } from './pages/UsersPage';

function RequireAuth() {
  const { user, loading } = useAuth();
  if (loading) return <p className="muted center">Cargando sesión…</p>;
  return user ? <Outlet /> : <Navigate to="/login" replace />;
}

function RequireRole({ roles }: { roles: Role[] }) {
  const { user } = useAuth();
  return user && roles.includes(user.role) ? <Outlet /> : <Navigate to="/" replace />;
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireAuth />}>
            <Route element={<Layout />}>
              <Route index element={<DashboardPage />} />
              <Route path="tickets/new" element={<NewTicketPage />} />
              <Route path="tickets/:id" element={<TicketDetailPage />} />
              <Route element={<RequireRole roles={['ADMIN']} />}>
                <Route path="admin/users" element={<UsersPage />} />
              </Route>
            </Route>
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
