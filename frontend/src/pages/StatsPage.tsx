import { useEffect, useState } from 'react';
import {
  Area,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { api } from '../api/client';
import type { Kpis, Timeline, TicketStatus } from '../api/types';
import { STATUS_LABELS } from '../api/types';

const RANGES = [7, 30, 90] as const;

const STATUS_COLORS: Record<TicketStatus, string> = {
  OPEN: '#2563eb',
  ASSIGNED: '#7c3aed',
  IN_PROGRESS: '#d97706',
  PENDING_INFO: '#6b7280',
  RESOLVED: '#059669',
  CLOSED: '#374151',
};

const PRIORITY_COLORS: Record<string, string> = {
  CRITICAL: '#dc2626',
  HIGH: '#ea580c',
  MEDIUM: '#ca8a04',
  LOW: '#16a34a',
};

const PALETTE = ['#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626', '#0891b2', '#6b7280'];

/**
 * CU-A03: métricas del servicio y historia de resolución (sólo ADMIN).
 */
export function StatsPage() {
  const [kpis, setKpis] = useState<Kpis | null>(null);
  const [timeline, setTimeline] = useState<Timeline | null>(null);
  const [days, setDays] = useState<(typeof RANGES)[number]>(30);
  const [error, setError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    api.reports
      .kpis()
      .then((r) => setKpis(r.data))
      .catch((e) => setError(e.message));
  }, []);

  useEffect(() => {
    api.reports
      .timeline(days)
      .then((r) => setTimeline(r.data))
      .catch((e) => setError(e.message));
  }, [days]);

  async function exportCsv() {
    setExporting(true);
    try {
      await api.reports.exportCsv();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo exportar');
    } finally {
      setExporting(false);
    }
  }

  if (error) return <p className="error">{error}</p>;
  if (!kpis || !timeline) return <p className="muted">Cargando estadísticas…</p>;

  const byStatus = (Object.keys(STATUS_LABELS) as TicketStatus[])
    .map((s) => ({ status: s, label: STATUS_LABELS[s], total: kpis.byStatus[s] ?? 0 }))
    .filter((d) => d.total > 0);
  const byCategory = Object.entries(kpis.byCategory).map(([name, total]) => ({ name, total }));
  const byPriority = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']
    .filter((p) => p in kpis.byPriority)
    .map((p) => ({ name: p, total: kpis.byPriority[p] }));
  const series = timeline.series.map((p) => ({ ...p, label: shortDate(p.date) }));
  const resolutionRate = timeline.summary.created > 0
    ? Math.round((timeline.summary.resolved / timeline.summary.created) * 100)
    : null;

  return (
    <>
      <div className="page-header">
        <h1>Estadísticas del servicio</h1>
        <button className="secondary" onClick={exportCsv} disabled={exporting}>
          {exporting ? 'Exportando…' : '⬇ Exportar CSV'}
        </button>
      </div>

      <div className="kpi-grid">
        <Kpi label="Tickets totales" value={kpis.total} />
        <Kpi label="Abiertos ahora" value={kpis.open} accent="#d97706" />
        <Kpi label="Creados últimas 24 h" value={kpis.createdLast24h} />
        <Kpi label="Tiempo promedio de resolución" value={formatHours(kpis.avgResolutionHours)} accent="#059669" />
      </div>

      <section className="card">
        <div className="chart-header">
          <div>
            <h2>Historia de resolución</h2>
            <p className="muted">
              Últimos {timeline.days} días · {timeline.summary.created} creados · {timeline.summary.resolved} resueltos
              {resolutionRate !== null && <> · tasa de resolución {resolutionRate}%</>}
              {timeline.summary.avgResolutionHours !== null && (
                <> · promedio {formatHours(timeline.summary.avgResolutionHours)}</>
              )}
            </p>
          </div>
          <div className="segmented">
            {RANGES.map((r) => (
              <button key={r} className={r === days ? 'active' : ''} onClick={() => setDays(r)}>
                {r} días
              </button>
            ))}
          </div>
        </div>
        <ResponsiveContainer width="100%" height={320}>
          <ComposedChart data={series} margin={{ top: 10, right: 20, left: 0, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
            <XAxis dataKey="label" tick={{ fontSize: 12 }} minTickGap={24} />
            <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
            <Tooltip labelFormatter={(_, payload) => payload?.[0]?.payload?.date ?? ''} />
            <Legend />
            <Area
              type="monotone"
              dataKey="backlog"
              name="Backlog (abiertos)"
              fill="#fde68a"
              stroke="#d97706"
              fillOpacity={0.5}
            />
            <Bar dataKey="created" name="Creados" fill="#2563eb" radius={[3, 3, 0, 0]} />
            <Bar dataKey="resolved" name="Resueltos" fill="#059669" radius={[3, 3, 0, 0]} />
            <Line type="monotone" dataKey="closed" name="Cerrados" stroke="#374151" strokeWidth={2} dot={false} />
          </ComposedChart>
        </ResponsiveContainer>
      </section>

      <div className="grid-2">
        <section className="card">
          <h2>Tickets por estado</h2>
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={byStatus} layout="vertical" margin={{ left: 40, right: 20 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" horizontal={false} />
              <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12 }} />
              <YAxis type="category" dataKey="label" width={140} tick={{ fontSize: 12 }} />
              <Tooltip />
              <Bar dataKey="total" name="Tickets" radius={[0, 3, 3, 0]}>
                {byStatus.map((d) => (
                  <Cell key={d.status} fill={STATUS_COLORS[d.status]} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </section>

        <section className="card">
          <h2>Volumen por categoría</h2>
          <ResponsiveContainer width="100%" height={260}>
            <PieChart>
              <Pie
                data={byCategory}
                dataKey="total"
                nameKey="name"
                innerRadius={50}
                outerRadius={85}
                paddingAngle={2}
                label={({ percent }) => `${Math.round((percent ?? 0) * 100)}%`}
              >
                {byCategory.map((_, i) => (
                  <Cell key={i} fill={PALETTE[i % PALETTE.length]} />
                ))}
              </Pie>
              <Tooltip />
              <Legend layout="vertical" align="right" verticalAlign="middle" />
            </PieChart>
          </ResponsiveContainer>
        </section>

        <section className="card">
          <h2>Tickets por prioridad</h2>
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={byPriority} margin={{ right: 20 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
              <XAxis dataKey="name" tick={{ fontSize: 12 }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
              <Tooltip />
              <Bar dataKey="total" name="Tickets" radius={[3, 3, 0, 0]}>
                {byPriority.map((d) => (
                  <Cell key={d.name} fill={PRIORITY_COLORS[d.name] ?? '#6b7280'} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </section>

        <section className="card">
          <h2>Rendimiento por técnico</h2>
          {kpis.byTechnician.length === 0 ? (
            <p className="muted">Todavía no hay tickets asignados.</p>
          ) : (
            <>
              <ResponsiveContainer width="100%" height={180}>
                <BarChart data={kpis.byTechnician} margin={{ right: 20 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                  <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                  <Tooltip />
                  <Legend />
                  <Bar dataKey="resolved" name="Resueltos" stackId="a" fill="#059669" />
                  <Bar dataKey="open" name="En curso" stackId="a" fill="#d97706" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
              <table className="compact">
                <thead>
                  <tr>
                    <th>Técnico</th>
                    <th>Resueltos</th>
                    <th>En curso</th>
                    <th>Promedio</th>
                  </tr>
                </thead>
                <tbody>
                  {kpis.byTechnician.map((t) => (
                    <tr key={t.id}>
                      <td>{t.name}</td>
                      <td>{t.resolved}</td>
                      <td>{t.open}</td>
                      <td>{formatHours(t.avgResolutionHours)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </section>
      </div>
    </>
  );
}

function Kpi({ label, value, accent }: { label: string; value: number | string; accent?: string }) {
  return (
    <div className="card kpi">
      <span className="muted">{label}</span>
      <strong style={accent ? { color: accent } : undefined}>{value}</strong>
    </div>
  );
}

function formatHours(hours: number | null): string {
  if (hours === null) return '—';
  if (hours < 1) return `${Math.round(hours * 60)} min`;
  if (hours < 48) return `${hours.toFixed(1)} h`;
  return `${(hours / 24).toFixed(1)} días`;
}

function shortDate(iso: string): string {
  const [, m, d] = iso.split('-');
  return `${d}/${m}`;
}
