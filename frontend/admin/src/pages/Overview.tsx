import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { api, type Overview as Data } from '../api'
import { ErrorBox, Loading, PageHeader, Panel, Stat, ago, cx, fcfa, useAsync } from '../kit'

export function Overview() {
  const { data, error, loading, reload } = useAsync(() => api.overview(), [])
  const navigate = useNavigate()

  if (error) return <ErrorBox message={error} onRetry={reload} />
  if (loading && !data) return <Loading />
  if (!data) return null
  const { kpis, money, todo } = data
  const nothingToDo = todo.offersToReview + todo.payouts + todo.requests + todo.disputes === 0

  return (
    <>
      <PageHeader
        title="Vue d’ensemble"
        subtitle="Ce mois-ci, comparé au mois dernier à la même date."
        actions={
          <button type="button" onClick={reload} className="rounded-[10px] px-3 py-2 text-sm font-bold text-muted hover:bg-white">
            ↻ Actualiser
          </button>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4 [&>:first-child]:max-lg:col-span-2">
        <Stat tone="ink" label="Encaissé ce mois" value={fcfa(kpis.gmv.value)} unit="FCFA" current={kpis.gmv.value} previous={kpis.gmv.previous} hint="vs mois dernier" />
        <Stat
          label="Revenu Sub.ci"
          value={fcfa(kpis.commission.value)}
          unit="FCFA"
          current={kpis.commission.value}
          previous={kpis.commission.previous}
          hint={kpis.fees ? `10 % + ${fcfa(kpis.fees.value)} F de frais de service` : '10 % des paiements reversés'}
        />
        <Stat label="Membres actifs" value={fcfa(kpis.members.value)} hint="avec un abonnement en cours" />
        <Stat label="Hôtes actifs" value={fcfa(kpis.hosts.value)} hint="au moins une offre en ligne" />
        <Stat label="Inscriptions" value={fcfa(kpis.newUsers.value)} current={kpis.newUsers.value} previous={kpis.newUsers.previous} hint="ce mois" />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1.35fr_1fr]">
        <Panel title="À traiter" action={nothingToDo ? <span className="text-[13px] font-bold text-ok-ink">Tout est à jour ✓</span> : null}>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <TodoCard to="/offers" count={todo.offersToReview} label="Offres à valider" hint="Preuves d’abonnement en attente" urgent />
            <TodoCard to="/disputes" count={todo.disputes} label="Soucis signalés" hint="Membres sans accès · gains gelés" urgent />
            <TodoCard to="/payouts" count={todo.payouts} label="Versements à faire" hint={`${fcfa(money.payoutsPending)} FCFA à envoyer`} urgent />
            <TodoCard
              to="/requests"
              count={todo.requests}
              label="Demandes en cours"
              hint={todo.requestsExpiringSoon ? `${todo.requestsExpiringSoon} expirent dans moins de 3 h` : 'Réponse des hôtes attendue'}
              warn={todo.requestsExpiringSoon > 0}
            />
          </div>
          {todo.paymentsPending > 0 && (
            <button type="button" onClick={() => navigate('/payments?status=pending&type=subscription')} className="mt-3 w-full rounded-tile bg-sand px-4 py-2.5 text-left text-[13px] font-semibold text-muted hover:bg-line">
              {todo.paymentsPending} paiement{todo.paymentsPending > 1 ? 's' : ''} mobile money en attente de validation chez GeniusPay →
            </button>
          )}
        </Panel>

        <Panel title="Argent en circulation">
          <div className="flex flex-col divide-y divide-line-soft">
            <MoneyRow label="Payé, en attente de l’hôte" value={money.held} hint="remboursé si refus ou sans réponse" />
            <MoneyRow
              label="Séquestre hôtes"
              value={money.escrow}
              hint={money.escrowHeld ? `versé mois par mois · dont ${fcfa(money.escrowHeld)} gelés` : 'versé au solde mois par mois, 72 h après'}
            />
            <MoneyRow label="Soldes des hôtes" value={money.hostBalances} hint="dû aux hôtes, retirable" />
            <MoneyRow label="Versements à faire" value={money.payoutsPending} hint="remboursements + retraits demandés" strong />
            {money.referralCredit !== undefined && (
              <MoneyRow label="Crédits parrainage" value={money.referralCredit} hint={`non utilisés · ${money.referralsMonth ?? 0} parrainage(s) ce mois`} />
            )}
          </div>
        </Panel>
      </div>

      <Panel title="Encaissements · 30 derniers jours" action={<span className="text-[13px] font-semibold text-muted">Total {fcfa(data.series.reduce((a, d) => a + d.gmv, 0))} FCFA</span>}>
        <GmvChart series={data.series} />
      </Panel>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="Par service · ce mois">
          <ServiceBars services={data.services} />
        </Panel>
        <Panel title="Activité récente" pad={false}>
          <ul className="flex flex-col">
            {data.activity.map((a, i) => (
              <li key={i}>
                <Link to={`/users/${a.userId}`} className="flex items-center gap-3 border-t border-line-soft px-5 py-3 hover:bg-sand/60">
                  <span className={cx('size-2 shrink-0 rounded-full', DOT[a.kind] ?? 'bg-radio')} aria-hidden />
                  <span className="min-w-0 flex-1 truncate text-sm font-semibold">{a.title}</span>
                  {a.amount !== null && <span className="tabular text-sm font-bold">{fcfa(a.amount)}</span>}
                  {a.status === 'pending' && <span className="text-[11px] font-extrabold text-warn-ink">en attente</span>}
                  <span className="w-16 shrink-0 text-right text-[12px] font-semibold text-muted lg:w-20">{ago(a.at)}</span>
                </Link>
              </li>
            ))}
          </ul>
        </Panel>
      </div>
    </>
  )
}

const DOT: Record<string, string> = {
  subscription: 'bg-ok',
  withdrawal: 'bg-warn',
  refund: 'bg-err',
  signup: 'bg-info',
  offer: 'bg-brand',
}

function TodoCard({ to, count, label, hint, urgent, warn }: { to: string; count: number; label: string; hint: string; urgent?: boolean; warn?: boolean }) {
  const hot = count > 0 && urgent
  return (
    <Link to={to} className={cx('flex flex-col gap-1.5 rounded-btn p-4 transition-colors', hot ? 'bg-brand-tint ring-2 ring-brand hover:bg-brand-soft' : 'bg-sand hover:bg-line')}>
      <span className="tabular font-display text-[34px] leading-none font-extrabold">{count}</span>
      <span className="text-sm font-bold">{label}</span>
      <span className={cx('text-[12px] font-semibold', warn ? 'text-warn-ink' : 'text-muted')}>{hint}</span>
    </Link>
  )
}

function MoneyRow({ label, value, hint, strong }: { label: string; value: number; hint: string; strong?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
      <span className="flex flex-col gap-0.5">
        <span className="text-sm font-bold">{label}</span>
        <span className="text-[12px] font-semibold text-muted">{hint}</span>
      </span>
      <span className={cx('tabular font-display text-xl font-extrabold', strong && value > 0 && 'text-brand-ink')}>
        {fcfa(value)} <span className="font-sans text-[12px] font-bold text-muted">FCFA</span>
      </span>
    </div>
  )
}

/** Barres journalières (une série, une teinte), info-bulle au survol, repères discrets. */
function GmvChart({ series }: { series: Data['series'] }) {
  const [hover, setHover] = useState<number | null>(null)
  // Largeur réelle : les libellés gardent leur taille, sur mobile comme sur grand écran.
  const box = useRef<HTMLDivElement>(null)
  const [W, setW] = useState(1000)
  useEffect(() => {
    const el = box.current
    if (!el) return
    const ro = new ResizeObserver(([e]) => setW(Math.max(280, Math.round(e.contentRect.width))))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  const narrow = W < 640
  const H = narrow ? 180 : 240
  const pad = { top: 12, bottom: 26, left: 0, right: 0 }
  const max = Math.max(1, ...series.map((d) => d.gmv))
  const nice = niceMax(max)
  const bw = (W - pad.left - pad.right) / series.length
  const y = (v: number) => pad.top + (H - pad.top - pad.bottom) * (1 - v / nice)
  const ticks = [0, nice / 2, nice]
  const dayFmt = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' })
  const d = hover !== null ? series[hover] : null

  return (
    <div className="relative" ref={box}>
      <svg viewBox={`0 0 ${W} ${H}`} width={W} height={H} className="block w-full" role="img" aria-label="Encaissements par jour sur 30 jours" onMouseLeave={() => setHover(null)}>
        {ticks.map((t) => (
          <line key={t} x1={0} x2={W} y1={y(t)} y2={y(t)} stroke="var(--color-line)" strokeWidth={1} />
        ))}
        {series.map((p, i) => {
          const x = pad.left + i * bw
          const h = Math.max(p.gmv > 0 ? 3 : 0, y(0) - y(p.gmv))
          return (
            <g key={p.date} onMouseEnter={() => setHover(i)}>
              {/* zone de survol plus grande que la barre */}
              <rect x={x} y={pad.top} width={bw} height={H - pad.top - pad.bottom} fill="transparent" />
              <rect
                x={x + (narrow ? 1 : 2)}
                y={y(0) - h}
                width={Math.max(1, bw - (narrow ? 2 : 4))}
                height={h}
                rx={narrow ? 2 : 4}
                fill={hover === i ? 'var(--color-brand)' : 'var(--color-ink)'}
                opacity={hover === null || hover === i ? 1 : 0.55}
              />
              {i % (narrow ? 10 : 5) === 4 && (
                <text x={x + bw / 2} y={H - 8} textAnchor="middle" className="fill-subtle text-[11px] font-semibold">
                  {dayFmt.format(new Date(p.date))}
                </text>
              )}
            </g>
          )
        })}
        {ticks.map((t) =>
          t === 0 ? null : (
            <text key={t} x={W} y={y(t) - 5} textAnchor="end" stroke="white" strokeWidth={4} paintOrder="stroke" className="fill-subtle text-[11px] font-semibold">
              {fcfa(t)}
            </text>
          ),
        )}
      </svg>
      {d && hover !== null && (
        <div
          className="pointer-events-none absolute top-0 z-10 flex -translate-x-1/2 flex-col gap-0.5 rounded-tile bg-ink px-3 py-2 text-[12px] font-semibold text-sand shadow-lg"
          style={{ left: `clamp(90px, ${((hover + 0.5) / series.length) * 100}%, calc(100% - 90px))` }}
        >
          <span className="text-ink-muted">{new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(d.date))}</span>
          <span className="tabular text-sm font-extrabold">{fcfa(d.gmv)} FCFA</span>
          <span className="text-ink-muted">
            {d.payments} paiement{d.payments > 1 ? 's' : ''} · {d.users} inscription{d.users > 1 ? 's' : ''}
          </span>
        </div>
      )}
    </div>
  )
}

function niceMax(v: number) {
  const p = 10 ** Math.floor(Math.log10(v))
  return Math.ceil(v / p) * p
}

/** Barres horizontales par service : logo pour l'identité, valeur en texte. */
function ServiceBars({ services }: { services: Data['services'] }) {
  const max = Math.max(1, ...services.map((s) => s.gmv))
  return (
    <ul className="flex flex-col gap-3">
      {services.map((s) => (
        <li key={s.id} className="grid grid-cols-[96px_1fr_auto] items-center gap-3 lg:grid-cols-[132px_1fr_auto]" title={`${s.members} membres · ${s.offers} offre(s) en ligne`}>
          <span className="flex items-center gap-2 truncate text-[13px] font-bold">
            <span className="size-2.5 shrink-0 rounded-sm" style={{ background: s.color }} aria-hidden />
            {s.name}
          </span>
          <span className="h-2.5 overflow-hidden rounded-full bg-sand">
            <span className="block h-full rounded-full bg-ink" style={{ width: `${(s.gmv / max) * 100}%` }} />
          </span>
          <span className="tabular text-right text-[13px] font-bold lg:w-28">
            {fcfa(s.gmv)} <span className="text-[11px] font-semibold text-muted">· {s.members} mb</span>
          </span>
        </li>
      ))}
    </ul>
  )
}
