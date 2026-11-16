import { useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, errorText, type JekoLedger, type Treasury as Data, type TreasuryPeriod, type TreasuryWithdrawal } from '../api'
import { Button, Confirm, Drawer, ErrorBox, Loading, PageHeader, Pager, Panel, Pill, Segments, Stat, Table, Td, Tr, cx, dateTime, fcfa, useAsync } from '../kit'

/* Bénéfices & trésorerie Sub.ci.
   Deux chiffres à ne jamais confondre : le bénéfice net (ce que Sub.ci a gagné, frais déduits)
   et la trésorerie disponible (ce qui est réellement sur le compte Jèko et n'appartient qu'à Sub.ci). */

type Query = { period: TreasuryPeriod; from?: string; to?: string }

const PERIODS: { value: TreasuryPeriod; label: string }[] = [
  { value: 'today', label: 'Aujourd’hui' },
  { value: 'week', label: 'Cette semaine' },
  { value: 'month', label: 'Ce mois' },
  { value: 'year', label: 'Cette année' },
  { value: 'custom', label: 'Personnalisée' },
]

const pct = (n: number) => `${String(n).replace('.', ',')} %`
const signed = (n: number) => (n > 0 ? `+${fcfa(n)}` : n < 0 ? `−${fcfa(-n)}` : '0')
const today = () => new Date().toISOString().slice(0, 10)
const nowLocal = () => {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 16)
}
/** Champ datetime-local → ISO (le serveur compare à « maintenant » en UTC). */
const iso = (local: string) => new Date(local).toISOString()

export function Treasury() {
  const [query, setQuery] = useState<Query>({ period: 'month' })
  const [custom, setCustom] = useState({ from: today().slice(0, 8) + '01', to: today() })
  const [tab, setTab] = useState<'overview' | 'journal' | 'withdrawals' | 'charges' | 'jeko'>('overview')
  const { data, error, loading, reload } = useAsync(() => api.treasury(query), [query.period, query.from, query.to])
  const [syncing, setSyncing] = useState(false)
  const toast = useToast()

  const sync = async () => {
    setSyncing(true)
    try {
      const r = await api.syncJeko()
      toast({ tone: 'success', text: `Relevé Jèko à jour · solde ${fcfa(r.balance ?? 0)} F · ${r.linked ?? 0} rattachement(s)` })
      reload()
    } catch (e) {
      toast({ tone: 'error', text: errorText(e) })
    } finally {
      setSyncing(false)
    }
  }

  return (
    <>
      <PageHeader
        title="Bénéfices & trésorerie"
        subtitle="Ce que Sub.ci a gagné, ce qui appartient aux hôtes et aux membres, et ce qui peut être retiré."
        actions={
          <>
            {data?.config.jeko && (
              <button type="button" onClick={sync} disabled={syncing} className="rounded-[10px] bg-surface px-3 py-2 text-sm font-bold hover:bg-line disabled:opacity-60">
                {syncing ? 'Relevé…' : '⇣ Relever Jèko'}
              </button>
            )}
            <button type="button" onClick={reload} className="rounded-[10px] px-3 py-2 text-sm font-bold text-muted hover:bg-surface">
              ↻ Actualiser
            </button>
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Segments value={query.period} options={PERIODS} onChange={(p) => setQuery(p === 'custom' ? { period: p, ...custom } : { period: p })} />
        {query.period === 'custom' && (
          <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              setQuery({ period: 'custom', ...custom })
            }}
          >
            <input type="date" aria-label="Du" value={custom.from} max={custom.to} onChange={(e) => setCustom({ ...custom, from: e.target.value })} className={inputCls} />
            <span className="text-sm font-semibold text-muted">au</span>
            <input type="date" aria-label="Au" value={custom.to} min={custom.from} max={today()} onChange={(e) => setCustom({ ...custom, to: e.target.value })} className={inputCls} />
            <button type="submit" className="rounded-[10px] bg-ink px-3 py-2 text-sm font-bold text-sand">
              Afficher
            </button>
          </form>
        )}
      </div>

      {error && <ErrorBox message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (
        <>
          <Answers data={data} />
          <Segments
            value={tab}
            onChange={setTab}
            options={[
              { value: 'overview', label: 'Détail' },
              { value: 'journal', label: 'Journal' },
              { value: 'withdrawals', label: 'Retraits de bénéfices' },
              { value: 'charges', label: 'Charges & ajustements' },
              { value: 'jeko', label: 'Relevé Jèko', count: data.reconciliation.unclassified || undefined },
            ]}
          />
          {tab === 'overview' && <Detail data={data} onJeko={() => setTab('jeko')} />}
          {tab === 'journal' && <Journal query={query} />}
          {tab === 'withdrawals' && <Withdrawals data={data} onChange={reload} />}
          {tab === 'charges' && <Entries onChange={reload} />}
          {tab === 'jeko' && <Ledger data={data} onChange={reload} />}
        </>
      )}
    </>
  )
}

const inputCls = 'h-10 rounded-[10px] border-[1.5px] border-line bg-surface px-3 text-sm font-bold outline-none focus:border-ink'

/* ---------- Les six réponses ---------- */

function Answers({ data }: { data: Data }) {
  const p = data.profit
  const spent = p.feesIn + p.feesOut + p.withdrawalFees + p.charges + p.referral
  const estimated = p.feesInEstimated + p.feesOutEstimated
  const real = data.cash.real
  const periodLabel = PERIODS.find((x) => x.value === data.preset)?.label.toLowerCase() ?? ''

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 lg:gap-4 xl:grid-cols-6 [&>:first-child]:max-lg:col-span-2 [&>:last-child]:max-lg:col-span-2">
      <Stat tone="ink" label={`Gagné · ${periodLabel}`} value={signed(p.net)} unit="FCFA" hint="bénéfice net, frais déduits" />
      <Stat label={`Dépensé en frais · ${periodLabel}`} value={fcfa(spent)} unit="FCFA" hint={estimated ? `dont ${fcfa(estimated)} F estimés` : 'frais réels'} />
      <Stat
        label="Argent des hôtes et membres"
        value={fcfa(data.liabilities.ownersTotal + data.liabilities.membersTotal)}
        unit="FCFA"
        hint={`${fcfa(data.liabilities.ownersTotal)} F hôtes · ${fcfa(data.liabilities.membersTotal)} F membres`}
      />
      <Stat
        label="Trésorerie disponible"
        value={data.available.confirmed === null ? '—' : signed(data.available.confirmed)}
        unit={data.available.confirmed === null ? undefined : 'FCFA'}
        hint={
          real ? (
            <span className={cx(real.stale && 'text-warn-ink')}>
              sur le solde {real.source === 'jeko' ? 'Jèko' : 'saisi'} du {dateTime(real.at)}
              {real.stale ? ' · à relever' : ''}
            </span>
          ) : (
            `aucun solde réel · estimation ${signed(data.available.estimated)} F`
          )
        }
      />
      <Stat label="Bénéfices déjà retirés" value={fcfa(data.allTime.withdrawn)} unit="FCFA" hint={`${fcfa(p.withdrawn)} F ${periodLabel}`} />
      <Stat
        label="Retirable maintenant"
        value={data.withdrawable === null ? '—' : fcfa(data.withdrawable)}
        unit={data.withdrawable === null ? undefined : 'FCFA'}
        hint={data.withdrawable === null ? 'relève le solde Jèko d’abord' : `sans toucher à l’argent des hôtes · ${fcfa(data.profitLeft)} F de bénéfice non retiré`}
      />
    </div>
  )
}

/* ---------- Détail ---------- */

function Line({ label, value, hint, strong, minus, tone }: { label: ReactNode; value: number | null; hint?: ReactNode; strong?: boolean; minus?: boolean; tone?: 'ok' | 'err' }) {
  return (
    <div className={cx('flex items-baseline justify-between gap-3 py-2.5', strong && 'border-t-[1.5px] border-ink pt-3')}>
      <span className="flex min-w-0 flex-col">
        <span className={cx('text-sm', strong ? 'font-extrabold' : 'font-semibold')}>{label}</span>
        {hint && <span className="text-[12px] font-medium text-muted">{hint}</span>}
      </span>
      <span className={cx('tabular shrink-0 text-sm', strong ? 'font-display text-[18px] font-extrabold' : 'font-bold', tone === 'ok' && 'text-ok-ink', tone === 'err' && 'text-err-ink')}>
        {value === null ? '—' : minus && value ? `−${fcfa(value)}` : signed(value)} {value !== null && <span className="text-[12px] text-muted">F</span>}
      </span>
    </div>
  )
}

function Detail({ data, onJeko }: { data: Data; onJeko: () => void }) {
  const p = data.profit
  const l = data.liabilities
  const real = data.cash.real
  const r = data.reconciliation
  const est = (n: number) => (n ? `dont ${fcfa(n)} F estimés (${pct(data.config.feeInPercent)})` : 'montant réel facturé par Jèko')

  return (
    <>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title="1 · Bénéfice net Sub.ci" action={<span className="text-[12px] font-semibold text-muted">sur la période</span>}>
          <p className="mb-2 text-[13px] font-medium text-muted">
            {fcfa(p.gross)} F encaissés auprès des membres. Seule la part de Sub.ci compte ici : le prix des places reste aux hôtes.
          </p>
          <div className="flex flex-col divide-y divide-line-soft">
            <Line label="Frais de service collectés" value={p.serviceFees} hint="payés par les membres, hors paiements remboursés ou en attente de l’hôte" />
            <Line label="Commission sur les gains des hôtes" value={p.commission} />
            <Line label="Frais de retrait facturés aux hôtes" value={p.hostWithdrawalFees} />
            <Line label="Revenus Sub.ci" value={p.revenue} strong />
            <Line label="Commissions Jèko à l’encaissement" value={p.feesIn} minus hint={est(p.feesInEstimated)} />
            <Line label="Commissions Jèko à l’envoi" value={p.feesOut} minus hint={p.feesOutEstimated ? `dont ${fcfa(p.feesOutEstimated)} F estimés (${pct(data.config.feeOutPercent)})` : 'reversements aux hôtes et remboursements'} />
            <Line label="Frais de retrait des bénéfices" value={p.withdrawalFees} minus />
            <Line label="Autres charges" value={p.charges} minus />
            <Line label="Crédits de parrainage offerts" value={p.referral} minus />
            <Line label="Bénéfice net" value={p.net} strong tone={p.net < 0 ? 'err' : 'ok'} />
          </div>
          <p className="mt-2 text-[12px] font-medium text-muted">Un retrait de bénéfices n’est pas une charge : seuls ses frais le sont.</p>
        </Panel>

        <Panel title="2 · Trésorerie disponible Sub.ci" action={<span className="text-[12px] font-semibold text-muted">aujourd’hui</span>}>
          <div className="flex flex-col divide-y divide-line-soft">
            <Line
              label="Solde réel du compte Jèko"
              value={real?.amount ?? null}
              hint={real ? `${real.source === 'jeko' ? 'lu via l’API Jèko' : 'saisi à la main'} · ${dateTime(real.at)}${real.stale ? ' · plus de 24 h' : ''}` : 'aucun solde relevé : rien n’est inventé'}
            />
            <Line label="Dû aux hôtes" value={l.ownersTotal} minus hint={`soldes ${fcfa(l.owners.hostBalances)} · séquestre ${fcfa(l.owners.escrow)} · retraits à envoyer ${fcfa(l.owners.hostWithdrawalsToSend)}`} />
            <Line
              label="Dû aux membres"
              value={l.membersTotal}
              minus
              hint={`remboursements ${fcfa(l.members.refundsToSend + l.members.refundsAwaitingChoice)} · en attente de l’hôte ${fcfa(l.members.held)} · crédits ${fcfa(l.members.memberCredit)}`}
            />
            <Line label="Frais d’envoi à prévoir" value={l.sendingFees} minus hint="sur les remboursements et retraits à envoyer" />
            <Line label="Retraits de bénéfices en attente" value={l.pendingWithdrawals} minus />
            <Line label="Trésorerie disponible" value={data.available.confirmed} strong tone={data.available.confirmed !== null && data.available.confirmed < 0 ? 'err' : 'ok'} />
          </div>
          <div className="mt-3 rounded-tile bg-sand px-3 py-2.5 text-[13px] font-semibold text-muted">
            Estimation sur le solde calculé par Sub.ci ({fcfa(data.cash.theoretical)} F) : <b className="tabular text-ink">{signed(data.available.estimated)} F</b>. Ce n’est pas un solde confirmé
            par Jèko.
          </div>
        </Panel>
      </div>

      <Panel title="Rapprochement du compte Jèko" action={r.unclassified ? <button type="button" onClick={onJeko} className="text-[13px] font-bold text-warn-ink">{r.unclassified} transaction(s) à classer →</button> : null}>
        <Table head={['', 'Calculé par Sub.ci', 'Relevé Jèko']}>
          <Tr>
            <Td>Encaissements</Td>
            <Td>{fcfa(r.subci.collections)} F</Td>
            <Td>{r.jeko.synced ? `${fcfa(r.jeko.collections)} F` : '—'}</Td>
          </Tr>
          <Tr>
            <Td>Commissions prélevées</Td>
            <Td>
              {fcfa(r.subci.fees)} F{r.subci.feesEstimated ? <span className="text-[12px] text-muted"> · {fcfa(r.subci.feesEstimated)} estimés</span> : null}
            </Td>
            <Td>{r.jeko.synced ? `${fcfa(r.jeko.feesIn + r.jeko.feesOut)} F` : '—'}</Td>
          </Tr>
          <Tr>
            <Td>Reversements (hôtes, remboursements)</Td>
            <Td>{fcfa(r.subci.payouts)} F</Td>
            <Td>{r.jeko.synced ? `${fcfa(r.jeko.transfers)} F envoyés au total` : '—'}</Td>
          </Tr>
          <Tr>
            <Td>Retraits de bénéfices</Td>
            <Td>{fcfa(r.subci.withdrawals)} F</Td>
            <Td>—</Td>
          </Tr>
          <Tr>
            <Td>Charges payées / ajustements</Td>
            <Td>
              {r.subci.charges ? `−${fcfa(r.subci.charges)}` : '0'} / {signed(r.subci.adjustments)} F
            </Td>
            <Td>—</Td>
          </Tr>
          <Tr>
            <Td>
              <b>Solde</b>
            </Td>
            <Td>
              <b>{fcfa(r.subci.balance)} F</b> <span className="text-[12px] text-muted">calculé</span>
            </Td>
            <Td>{r.jeko.balance !== null ? <b>{fcfa(r.jeko.balance)} F</b> : <span className="text-muted">non relevé</span>}</Td>
          </Tr>
        </Table>
        {real && (
          <p className={cx('mt-3 text-[13px] font-semibold', real.gap === 0 ? 'text-ok-ink' : 'text-warn-ink')}>
            {real.gap === 0
              ? `Aucun écart au ${dateTime(real.at)}.`
              : `Écart de ${signed(real.gap)} F au ${dateTime(real.at)} (réel ${fcfa(real.amount)} − calculé ${fcfa(real.theoreticalThen)}). Classe les transactions Jèko ou enregistre un ajustement motivé.`}
          </p>
        )}
        {r.unmatchedPayments > 0 && r.jeko.synced && (
          <p className="mt-1 text-[12px] font-medium text-muted">{r.unmatchedPayments} paiement(s) Sub.ci sans transaction Jèko rattachée : leur commission est estimée.</p>
        )}
      </Panel>

      <Charts series={data.series} />
    </>
  )
}

/* ---------- Graphiques ---------- */

type Serie = Data['series'][number]
const CHARTS: { value: string; label: string; keys: (keyof Serie)[]; names: string[] }[] = [
  { value: 'net', label: 'Bénéfice net', keys: ['net'], names: ['Bénéfice net'] },
  { value: 'fees', label: 'Frais Jèko', keys: ['fees'], names: ['Frais'] },
  { value: 'balance', label: 'Trésorerie', keys: ['balance'], names: ['Solde calculé'] },
  { value: 'gross', label: 'Brut vs net', keys: ['gross', 'revenue'], names: ['Encaissé', 'Revenu Sub.ci'] },
  { value: 'withdrawn', label: 'Retraits', keys: ['withdrawn'], names: ['Retiré'] },
]
const COLORS = ['bg-ink', 'bg-brand']

function Charts({ series }: { series: Serie[] }) {
  const [chart, setChart] = useState('net')
  const [hover, setHover] = useState<number | null>(null)
  const c = CHARTS.find((x) => x.value === chart)!
  const values = series.flatMap((s) => c.keys.map((k) => Number(s[k])))
  const max = Math.max(1, ...values)
  const min = Math.min(0, ...values)
  const span = max - min
  const zero = (max / span) * 100
  const daily = series.length > 1 && series[1].date.slice(0, 7) === series[0].date.slice(0, 7)
  const fmt = new Intl.DateTimeFormat('fr-FR', daily ? { day: 'numeric', month: 'short' } : { month: 'short', year: '2-digit' })
  const h = hover !== null ? series[hover] : null

  return (
    <Panel title="Évolution" action={<Segments value={chart} onChange={setChart} options={CHARTS.map(({ value, label }) => ({ value, label }))} />}>
      {c.keys.length > 1 && (
        <div className="mb-2 flex gap-4 text-[12px] font-semibold text-muted">
          {c.names.map((n, i) => (
            <span key={n} className="flex items-center gap-1.5">
              <span className={cx('size-2.5 rounded-sm', COLORS[i])} aria-hidden /> {n}
            </span>
          ))}
        </div>
      )}
      <div className="relative" onMouseLeave={() => setHover(null)}>
        <div className="relative flex h-[200px] items-stretch gap-[3px]">
          <span className="pointer-events-none absolute inset-x-0 border-t border-line" style={{ top: `${zero}%` }} aria-hidden />
          {series.map((s, i) => (
            <div
              key={s.date}
              className="relative flex flex-1 gap-[2px]"
              onMouseEnter={() => setHover(i)}
              aria-label={`${s.date} : ${c.keys.map((k, j) => `${c.names[j]} ${fcfa(Number(s[k]))} F`).join(', ')}`}
            >
              {c.keys.map((k, j) => {
                const v = Number(s[k])
                const height = (Math.abs(v) / span) * 100
                return (
                  <span key={k} className="relative flex-1">
                    <span
                      className={cx('absolute inset-x-0 rounded-[3px]', v < 0 ? 'bg-err' : COLORS[j], hover === i && 'opacity-80')}
                      style={v >= 0 ? { bottom: `${100 - zero}%`, height: `${height}%` } : { top: `${zero}%`, height: `${height}%` }}
                    />
                  </span>
                )
              })}
            </div>
          ))}
        </div>
        <div className="mt-1.5 flex justify-between text-[11px] font-semibold text-subtle">
          <span>{series[0] && fmt.format(new Date(`${series[0].date}T12:00:00`))}</span>
          <span>{series.length > 1 && fmt.format(new Date(`${series[series.length - 1].date}T12:00:00`))}</span>
        </div>
        {h && hover !== null && (
          <div
            className="pointer-events-none absolute top-0 z-10 flex -translate-x-1/2 flex-col gap-0.5 scheme-card rounded-tile bg-ink px-3 py-2 text-[12px] font-semibold text-sand shadow-lg"
            style={{ left: `clamp(90px, ${((hover + 0.5) / series.length) * 100}%, calc(100% - 90px))` }}
          >
            <span className="text-ink-muted">{fmt.format(new Date(`${h.date}T12:00:00`))}</span>
            {c.keys.map((k, j) => (
              <span key={k} className="tabular font-extrabold">
                {c.names[j]} : {signed(Number(h[k]))} F
              </span>
            ))}
          </div>
        )}
      </div>
      {chart === 'balance' && <p className="mt-2 text-[12px] font-medium text-muted">Solde calculé par Sub.ci en fin de période, pas un solde confirmé par Jèko.</p>}
    </Panel>
  )
}

/* ---------- Journal ---------- */

const KINDS: Record<string, string> = {
  collection: 'Encaissement client',
  duplicate: 'Encaissement en double',
  fee_in: 'Commission Jèko',
  payout: 'Reversement propriétaire',
  refund: 'Remboursement',
  fee_out: 'Frais de reversement',
  withdrawal: 'Retrait Sub.ci',
  withdrawal_fee: 'Frais de retrait',
  charge: 'Charge',
  adjustment: 'Ajustement manuel',
}

function Journal({ query }: { query: Query }) {
  const [page, setPage] = useState(1)
  const [kind, setKind] = useState('')
  const { data, error, loading, reload } = useAsync(() => api.treasuryJournal({ ...query, page, kind: kind || undefined }), [query.period, query.from, query.to, page, kind])

  return (
    <Panel
      title="Journal de trésorerie"
      pad={false}
      action={
        <select aria-label="Type de mouvement" value={kind} onChange={(e) => (setKind(e.target.value), setPage(1))} className={inputCls}>
          <option value="">Tous les mouvements</option>
          {Object.entries(KINDS).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </select>
      }
    >
      {error && <ErrorBox message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (
        <>
          <Table head={['Mouvement', 'Date', 'Compte Jèko', 'Bénéfice', 'Transaction']} empty={data.data.length === 0}>
            {data.data.map((e, i) => (
              <Tr key={`${e.kind}-${e.at}-${i}`}>
                <Td>
                  <span className="flex flex-col">
                    <span className="font-bold">{KINDS[e.kind] ?? e.kind}</span>
                    <span className="text-[12px] font-medium text-muted">{e.label}</span>
                  </span>
                </Td>
                <Td>{dateTime(e.at)}</Td>
                <Td className="tabular font-bold">
                  {e.cash ? `${signed(e.cash)} F` : '—'} {e.estimated && <Pill tone="warn">estimé</Pill>}
                </Td>
                <Td className="tabular">{e.profit ? `${signed(e.profit)} F` : '—'}</Td>
                <Td>{e.reference ? <Link to={`/payments?q=${e.reference}&type=`} className="font-bold underline">{e.reference}</Link> : '—'}</Td>
              </Tr>
            ))}
          </Table>
          <div className="px-5 pb-4">
            <Pager page={data.meta.page} pages={data.meta.pages} onPage={setPage} />
          </div>
        </>
      )}
    </Panel>
  )
}

/* ---------- Retraits de bénéfices ---------- */

const W_STATUS: Record<TreasuryWithdrawal['status'], [string, 'warn' | 'ok' | 'err']> = { pending: ['En attente', 'warn'], confirmed: ['Confirmé', 'ok'], failed: ['Échoué', 'err'] }

function Withdrawals({ data: summary, onChange }: { data: Data; onChange: () => void }) {
  const { data, error, loading, reload } = useAsync(() => api.treasuryWithdrawals(), [])
  const [open, setOpen] = useState(false)
  const [decide, setDecide] = useState<{ w: TreasuryWithdrawal; status: 'confirmed' | 'failed' } | null>(null)
  const toast = useToast()
  const refresh = () => (reload(), onChange())

  return (
    <>
      <Panel
        title="Retraits de bénéfices"
        pad={false}
        action={
          <Button size="xs" variant="ink" block={false} onClick={() => setOpen(true)}>
            + Enregistrer un retrait
          </Button>
        }
      >
        <p className="px-5 pb-3 text-[13px] font-medium text-muted">
          {summary.withdrawable === null ? 'Relève le solde Jèko pour savoir combien retirer.' : `Retirable maintenant : ${fcfa(summary.withdrawable)} F.`} Un retrait ne compte qu’une fois confirmé.
        </p>
        {error && <ErrorBox message={error} onRetry={reload} />}
        {loading && !data && <Loading />}
        {data && (
          <Table head={['Montant retiré', 'Date', 'Vers', 'Frais', 'Net reçu', 'Référence', 'Statut']} empty={data.data.length === 0}>
            {data.data.map((w) => (
              <Tr key={w.id}>
                <Td className="tabular font-bold">{fcfa(w.amount)} F</Td>
                <Td>{dateTime(w.at)}</Td>
                <Td>{w.destination}</Td>
                <Td className="tabular">{fcfa(w.fee)} F</Td>
                <Td className="tabular">{fcfa(w.net)} F</Td>
                <Td>
                  {w.reference ?? '—'} {w.fromJeko && <Pill tone="info">relevé Jèko</Pill>}
                </Td>
                <Td>
                  <span className="flex items-center gap-2">
                    <Pill tone={W_STATUS[w.status][1]}>{W_STATUS[w.status][0]}</Pill>
                    {w.status === 'pending' && (
                      <>
                        <button type="button" onClick={() => setDecide({ w, status: 'confirmed' })} className="text-[13px] font-bold text-ok-ink">
                          Confirmer
                        </button>
                        <button type="button" onClick={() => setDecide({ w, status: 'failed' })} className="text-[13px] font-bold text-err-ink">
                          Échoué
                        </button>
                      </>
                    )}
                  </span>
                </Td>
              </Tr>
            ))}
          </Table>
        )}
      </Panel>
      <WithdrawalForm
        open={open}
        onClose={() => setOpen(false)}
        onDone={() => {
          setOpen(false)
          toast({ tone: 'success', text: 'Retrait enregistré' })
          refresh()
        }}
      />
      {decide && (
        <Confirm
          title={decide.status === 'confirmed' ? 'Confirmer ce retrait ?' : 'Marquer comme échoué ?'}
          text={`${fcfa(decide.w.amount)} F vers ${decide.w.destination}. Ce statut est définitif.`}
          confirm={decide.status === 'confirmed' ? 'Confirmer' : 'Échoué'}
          danger={decide.status === 'failed'}
          onCancel={() => setDecide(null)}
          onConfirm={async () => {
            await api.decideTreasuryWithdrawal(decide.w.id, decide.status)
            setDecide(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-[13px] font-bold text-muted">{label}</span>
      {children}
      {hint && <span className="text-[12px] font-medium text-subtle">{hint}</span>}
    </label>
  )
}
const fieldCls = 'h-12 rounded-tile border-[1.5px] border-line bg-surface px-3 text-[15px] font-semibold outline-none focus:border-ink'

function WithdrawalForm({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ amount: '', fee: '0', net: '', destination: '', reference: '', status: 'pending', at: nowLocal(), note: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const amount = Number(f.amount) || 0
  const fee = Number(f.fee) || 0
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })

  return (
    <Drawer open={open} onClose={onClose} title={<b className="font-display text-[20px]">Retrait de bénéfices</b>}>
      <form
        className="flex flex-col gap-4"
        onSubmit={async (e) => {
          e.preventDefault()
          setBusy(true)
          setErr(null)
          try {
            await api.addTreasuryWithdrawal({
              amount,
              fee,
              net: f.net ? Number(f.net) : undefined,
              destination: f.destination,
              reference: f.reference || undefined,
              status: f.status,
              withdrawnAt: iso(f.at),
              note: f.note || undefined,
            })
            setF({ amount: '', fee: '0', net: '', destination: '', reference: '', status: 'pending', at: nowLocal(), note: '' })
            onDone()
          } catch (x) {
            setErr(errorText(x))
          } finally {
            setBusy(false)
          }
        }}
      >
        <Field label="Montant retiré du compte Jèko (FCFA)" hint="Débité du compte marchand, frais compris.">
          <input required inputMode="numeric" value={f.amount} onChange={set('amount')} className={fieldCls} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Frais de retrait">
            <input required inputMode="numeric" value={f.fee} onChange={set('fee')} className={fieldCls} />
          </Field>
          <Field label="Net reçu" hint={`Par défaut ${fcfa(Math.max(0, amount - fee))} F`}>
            <input inputMode="numeric" value={f.net} onChange={set('net')} placeholder={String(Math.max(0, amount - fee))} className={fieldCls} />
          </Field>
        </div>
        <Field label="Compte ou moyen de réception">
          <input required value={f.destination} onChange={set('destination')} placeholder="Ex. : Wave 07 00 00 00 00" className={fieldCls} />
        </Field>
        <Field label="Référence de l’opération">
          <input value={f.reference} onChange={set('reference')} className={fieldCls} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Date du retrait">
            <input required type="datetime-local" value={f.at} max={nowLocal()} onChange={set('at')} className={fieldCls} />
          </Field>
          <Field label="Statut">
            <select value={f.status} onChange={set('status')} className={fieldCls}>
              <option value="pending">En attente</option>
              <option value="confirmed">Confirmé</option>
              <option value="failed">Échoué</option>
            </select>
          </Field>
        </div>
        <Field label="Note (facultatif)">
          <input value={f.note} onChange={set('note')} className={fieldCls} />
        </Field>
        {err && <p className="text-[13px] font-semibold text-err-ink">{err}</p>}
        <Button type="submit" variant="ink" size="md" loading={busy} disabled={!amount || !f.destination}>
          Enregistrer
        </Button>
      </form>
    </Drawer>
  )
}

/* ---------- Charges & ajustements ---------- */

function Entries({ onChange }: { onChange: () => void }) {
  const { data, error, loading, reload } = useAsync(() => api.treasuryEntries(), [])
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ kind: 'charge' as 'charge' | 'adjustment', amount: '', label: '', fromMerchant: true, at: nowLocal() })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [reverse, setReverse] = useState<number | null>(null)
  const refresh = () => (reload(), onChange())

  return (
    <>
      <Panel
        title="Charges & ajustements"
        pad={false}
        action={
          <Button size="xs" variant="ink" block={false} onClick={() => setOpen(true)}>
            + Ajouter
          </Button>
        }
      >
        <p className="px-5 pb-3 text-[13px] font-medium text-muted">Écritures définitives : une erreur se corrige par une annulation, qui reste visible.</p>
        {error && <ErrorBox message={error} onRetry={reload} />}
        {loading && !data && <Loading />}
        {data && (
          <Table head={['Libellé', 'Date', 'Type', 'Montant', '']} empty={data.data.length === 0}>
            {data.data.map((e) => (
              <Tr key={e.id}>
                <Td>
                  <span className="flex flex-col">
                    <span className="font-bold">{e.label}</span>
                    <span className="text-[12px] font-medium text-muted">{e.by ? `par ${e.by}` : ''}</span>
                  </span>
                </Td>
                <Td>{dateTime(e.at)}</Td>
                <Td>
                  {e.kind === 'charge' ? <Pill tone="err">Charge{e.fromMerchant ? '' : ' · hors compte'}</Pill> : <Pill tone="info">Ajustement</Pill>}
                </Td>
                <Td className="tabular font-bold">{e.kind === 'charge' ? `−${fcfa(e.amount)}` : signed(e.amount)} F</Td>
                <Td>
                  {e.reversesId ? (
                    <span className="text-[12px] font-semibold text-muted">annulation</span>
                  ) : e.reversed ? (
                    <span className="text-[12px] font-semibold text-muted">annulée</span>
                  ) : (
                    <button type="button" onClick={() => setReverse(e.id)} className="text-[13px] font-bold text-err-ink">
                      Annuler
                    </button>
                  )}
                </Td>
              </Tr>
            ))}
          </Table>
        )}
      </Panel>
      <Drawer open={open} onClose={() => setOpen(false)} title={<b className="font-display text-[20px]">Charge ou ajustement</b>}>
        <form
          className="flex flex-col gap-4"
          onSubmit={async (e) => {
            e.preventDefault()
            setBusy(true)
            setErr(null)
            try {
              await api.addTreasuryEntry({ kind: f.kind, amount: Number(f.amount), label: f.label, fromMerchant: f.fromMerchant, occurredAt: iso(f.at) })
              setOpen(false)
              setF({ ...f, amount: '', label: '' })
              refresh()
            } catch (x) {
              setErr(errorText(x))
            } finally {
              setBusy(false)
            }
          }}
        >
          <Segments
            value={f.kind}
            onChange={(kind) => setF({ ...f, kind })}
            options={[
              { value: 'charge', label: 'Charge opérationnelle' },
              { value: 'adjustment', label: 'Ajustement de trésorerie' },
            ]}
          />
          <p className="text-[13px] font-medium text-muted">
            {f.kind === 'charge'
              ? 'Réduit le bénéfice net (hébergement, SMS, pub…).'
              : 'Corrige le solde calculé sans toucher au bénéfice (écart de rapprochement expliqué). Positif = argent en plus sur le compte.'}
          </p>
          <Field label="Montant (FCFA)">
            <input required inputMode="numeric" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value.replace(/[^\d-]/g, '') })} className={fieldCls} />
          </Field>
          <Field label="Libellé">
            <input required value={f.label} onChange={(e) => setF({ ...f, label: e.target.value })} className={fieldCls} />
          </Field>
          <Field label="Date">
            <input required type="datetime-local" value={f.at} max={nowLocal()} onChange={(e) => setF({ ...f, at: e.target.value })} className={fieldCls} />
          </Field>
          {f.kind === 'charge' && (
            <label className="flex items-center gap-2 text-sm font-semibold">
              <input type="checkbox" checked={f.fromMerchant} onChange={(e) => setF({ ...f, fromMerchant: e.target.checked })} /> Payée depuis le compte Jèko
            </label>
          )}
          {err && <p className="text-[13px] font-semibold text-err-ink">{err}</p>}
          <Button type="submit" variant="ink" size="md" loading={busy} disabled={!f.amount || !f.label}>
            Enregistrer
          </Button>
        </form>
      </Drawer>
      {reverse !== null && (
        <Confirm
          title="Annuler cette écriture ?"
          text="Une écriture inverse est ajoutée au journal. L’originale reste visible."
          confirm="Annuler l’écriture"
          danger
          onCancel={() => setReverse(null)}
          onConfirm={async () => {
            await api.addTreasuryEntry({ kind: 'charge', amount: 1, label: 'annulation', occurredAt: new Date().toISOString(), reversesId: reverse })
            setReverse(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

/* ---------- Relevé Jèko ---------- */

type Txn = JekoLedger['transactions'][number]

function Ledger({ data: summary, onChange }: { data: Data; onChange: () => void }) {
  const { data, error, loading, reload } = useAsync(() => api.jekoLedger(), [])
  const [classify, setClassify] = useState<{ t: Txn; as: 'payment' | 'withdrawal' | 'ignore' } | null>(null)
  const [balance, setBalance] = useState({ amount: '', at: nowLocal(), note: '' })
  const [err, setErr] = useState<string | null>(null)
  const refresh = () => (reload(), onChange())

  return (
    <>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_360px]">
        <Panel title="Transactions Jèko" pad={false}>
          {!summary.config.jeko && <p className="px-5 pb-3 text-[13px] font-medium text-muted">L’API Jèko n’est pas configurée sur ce serveur : saisis le solde à la main.</p>}
          {error && <ErrorBox message={error} onRetry={reload} />}
          {loading && !data && <Loading />}
          {data && (
            <Table head={['Transaction', 'Date', 'Montant', 'Frais', 'Rattachée à']} empty={data.transactions.length === 0}>
              {data.transactions.map((t) => {
                const open = t.status === 'success' && !t.payment && !t.withdrawalId && !t.ignored
                return (
                  <Tr key={t.id}>
                    <Td>
                      <span className="flex flex-col">
                        <span className="font-bold">
                          {t.type === 'payment' ? 'Encaissement' : 'Envoi'} · {t.method ?? '—'}
                        </span>
                        <span className="text-[12px] font-medium text-muted">
                          {t.counterpart ?? ''} {t.status !== 'success' && `· ${t.status}`}
                        </span>
                      </span>
                    </Td>
                    <Td>{dateTime(t.at)}</Td>
                    <Td className="tabular font-bold">
                      {t.type === 'payment' ? '+' : '−'}
                      {fcfa(t.amount)} F
                    </Td>
                    <Td className="tabular">{fcfa(t.fee)} F</Td>
                    <Td>
                      {t.payment ? (
                        <Link to={`/payments?q=${t.payment.reference}&type=`} className="font-bold underline">
                          {t.payment.reference}
                        </Link>
                      ) : t.withdrawalId ? (
                        <Pill tone="ok">Retrait Sub.ci</Pill>
                      ) : t.ignored ? (
                        <span className="text-[12px] font-semibold text-muted">Hors Sub.ci · {t.ignored}</span>
                      ) : open ? (
                        <span className="flex flex-wrap items-center gap-2">
                          <Pill tone="warn">À classer</Pill>
                          <button type="button" className="text-[13px] font-bold underline" onClick={() => setClassify({ t, as: 'payment' })}>
                            Paiement Sub.ci
                          </button>
                          {t.type !== 'payment' && (
                            <button type="button" className="text-[13px] font-bold underline" onClick={() => setClassify({ t, as: 'withdrawal' })}>
                              Retrait de bénéfices
                            </button>
                          )}
                          <button type="button" className="text-[13px] font-bold text-muted underline" onClick={() => setClassify({ t, as: 'ignore' })}>
                            Hors Sub.ci
                          </button>
                        </span>
                      ) : (
                        '—'
                      )}
                    </Td>
                  </Tr>
                )
              })}
            </Table>
          )}
        </Panel>

        <Panel title="Soldes relevés">
          <form
            className="mb-4 flex flex-col gap-3"
            onSubmit={async (e) => {
              e.preventDefault()
              setErr(null)
              try {
                await api.addMerchantBalance({ amount: Number(balance.amount), recordedAt: iso(balance.at), note: balance.note || undefined })
                setBalance({ amount: '', at: nowLocal(), note: '' })
                refresh()
              } catch (x) {
                setErr(errorText(x))
              }
            }}
          >
            <Field label="Saisir le solde communiqué par Jèko (FCFA)">
              <input required inputMode="numeric" value={balance.amount} onChange={(e) => setBalance({ ...balance, amount: e.target.value.replace(/[^\d-]/g, '') })} className={fieldCls} />
            </Field>
            <input type="datetime-local" aria-label="Date du solde" value={balance.at} max={nowLocal()} onChange={(e) => setBalance({ ...balance, at: e.target.value })} className={fieldCls} />
            <input placeholder="Source (capture, relevé…)" value={balance.note} onChange={(e) => setBalance({ ...balance, note: e.target.value })} className={fieldCls} />
            {err && <p className="text-[13px] font-semibold text-err-ink">{err}</p>}
            <Button type="submit" variant="ink" size="sm" disabled={!balance.amount}>
              Enregistrer le solde
            </Button>
          </form>
          <ul className="flex flex-col divide-y divide-line-soft">
            {data?.balances.map((b) => (
              <li key={b.id} className="flex items-baseline justify-between gap-3 py-2.5">
                <span className="flex flex-col">
                  <span className="tabular text-sm font-bold">{fcfa(b.amount)} F</span>
                  <span className="text-[12px] font-medium text-muted">
                    {b.source === 'jeko' ? 'API Jèko' : `saisi${b.by ? ` par ${b.by}` : ''}`} · {dateTime(b.at)}
                  </span>
                </span>
                <span className={cx('tabular text-[12px] font-bold', b.amount === b.theoretical ? 'text-ok-ink' : 'text-warn-ink')}>
                  {b.amount === b.theoretical ? 'aucun écart' : `écart ${signed(b.amount - b.theoretical)}`}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      </div>
      {classify && <ClassifyDialog {...classify} onClose={() => setClassify(null)} onDone={() => (setClassify(null), refresh())} />}
    </>
  )
}

function ClassifyDialog({ t, as, onClose, onDone }: { t: Txn; as: 'payment' | 'withdrawal' | 'ignore'; onClose: () => void; onDone: () => void }) {
  const amount = `${fcfa(t.amount)} F (+${fcfa(t.fee)} F de frais)`
  return (
    <Confirm
      title={as === 'payment' ? 'Rattacher à un paiement Sub.ci' : as === 'withdrawal' ? 'C’est un retrait de bénéfices ?' : 'Transaction hors Sub.ci'}
      text={
        as === 'payment'
          ? `${amount}. Indique la référence du paiement Sub.ci (SUB-XXXX-00) : ${t.type === 'payment' ? 'un abonnement' : 'un retrait d’hôte ou un remboursement'}.`
          : as === 'withdrawal'
            ? `${amount} vers ${t.counterpart ?? '—'}. Un retrait confirmé est créé avec les frais réels de Jèko.`
            : `${amount}. Elle sera exclue du rapprochement (test, espèces…).`
      }
      confirm="Valider"
      reason={as === 'payment' ? { label: 'Référence du paiement', required: true, placeholder: 'SUB-8F2K-19' } : as === 'ignore' ? { label: 'Raison', required: true, placeholder: 'Ex. : paiement de test' } : undefined}
      onCancel={onClose}
      onConfirm={async (v) => {
        await api.classifyJeko(t.id, as === 'payment' ? { as, reference: v } : as === 'ignore' ? { as, reason: v } : { as })
        onDone()
      }}
    />
  )
}
