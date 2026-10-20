import React, { useState } from 'react';

interface Point { label: string; value: number | null }

/**
 * One metric across semesters (small multiple). Single hue, 2px line, 8px markers with a surface ring,
 * value labels on first/last points only, hover tooltip on every point, and a table for screen readers.
 */
export const TrendSparkline: React.FC<{ title: string; unit?: string; points: Point[]; max?: number; higherIsBetter?: boolean; testId?: string }> = ({ title, unit = '', points, max, higherIsBetter = true, testId }) => {
  const [hover, setHover] = useState<number | null>(null);
  const W = 260;
  const H = 96;
  const pad = { l: 10, r: 10, t: 18, b: 22 };
  const values = points.map((p) => p.value).filter((v): v is number => v !== null);
  const top = max ?? Math.max(1, ...values) * 1.15;
  const x = (i: number) => (points.length === 1 ? W / 2 : pad.l + (i * (W - pad.l - pad.r)) / (points.length - 1));
  const y = (v: number) => pad.t + (1 - v / top) * (H - pad.t - pad.b);
  const fmt = (v: number | null) => (v === null ? '—' : `${Number.isInteger(v) ? v : v.toFixed(v < 1 ? 2 : 1)}${unit}`);

  const defined = points.map((p, i) => ({ ...p, i })).filter((p) => p.value !== null) as { label: string; value: number; i: number }[];
  const path = defined.map((p, k) => `${k === 0 ? 'M' : 'L'}${x(p.i)},${y(p.value)}`).join(' ');
  const first = defined[0];
  const last = defined[defined.length - 1];
  const delta = first && last && first !== last ? last.value - first.value : null;
  const good = delta === null ? null : (delta > 0) === higherIsBetter;

  return (
    <figure className="rounded-xl border border-sage-200 bg-white p-3" data-testid={testId}>
      <figcaption className="flex items-baseline justify-between gap-2">
        <span className="text-xs font-medium text-sage-800">{title}</span>
        <span className="text-sm font-semibold tabular-nums text-sage-800">{fmt(last?.value ?? null)}
          {delta !== null && Math.abs(delta) > 0.001 && (
            <span className={`ml-1 text-[11px] font-medium ${good ? 'text-[#166534]' : 'text-[#991B1B]'}`}>{delta > 0 ? '▲' : '▼'} {fmt(Math.abs(delta))}</span>
          )}
        </span>
      </figcaption>
      <div className="relative mt-1">
        <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={`${title} by semester`}>
          <line x1={pad.l} x2={W - pad.r} y1={H - pad.b} y2={H - pad.b} className="stroke-sage-200" strokeWidth={1} />
          {path && <path d={path} fill="none" className="stroke-sage-600" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />}
          {defined.map((p) => (
            <g key={p.i}>
              <circle cx={x(p.i)} cy={y(p.value)} r={4.5} className="fill-sage-700 stroke-white" strokeWidth={2} />
              {(p === first || p === last) && (
                <text x={x(p.i)} y={y(p.value) - 8} className="fill-sage-700 text-[10px] tabular-nums"
                  textAnchor={points.length === 1 ? 'middle' : p.i === 0 ? 'start' : p.i === points.length - 1 ? 'end' : 'middle'}>{fmt(p.value)}</text>
              )}
            </g>
          ))}
          {points.map((p, i) => (
            <text key={`l${i}`} x={x(i)} y={H - 6} textAnchor={points.length === 1 ? 'middle' : i === 0 ? 'start' : i === points.length - 1 ? 'end' : 'middle'} className="fill-sage-500 text-[9px]">{p.label}</text>
          ))}
          {points.map((_, i) => (
            <rect key={`h${i}`} x={x(i) - 18} y={0} width={36} height={H} fill="transparent" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
          ))}
        </svg>
        {hover !== null && (
          <div role="tooltip" className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 whitespace-nowrap rounded-md border border-sage-200 bg-white px-2 py-1 text-[11px] shadow-card" style={{ left: `${(x(hover) / W) * 100}%` }}>
            <span className="text-sage-500">{points[hover].label}:</span> <span className="font-semibold text-sage-800">{fmt(points[hover].value)}</span>
          </div>
        )}
      </div>
      <table className="sr-only"><caption>{title}</caption><tbody>{points.map((p) => <tr key={p.label}><td>{p.label}</td><td>{fmt(p.value)}</td></tr>)}</tbody></table>
    </figure>
  );
};
