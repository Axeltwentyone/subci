import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, errorText, type PaymentRow, type RequestRow } from '../api'
import { useCounts } from '../shell'
import {
  Avatar, Brandmark, Button, Confirm, Copy, Drawer, ErrorBox, Loading, PAYMENT_STATUS, PAYMENT_TYPE, PageHeader, Pager, Panel, Pill, REQUEST_STATUS, SearchInput, Segments, StatusPill, Table, Td, Tr, ago, cx, date, dateTime, fcfa, phone,
  useAsync, useDebounced,
} from '../kit'

/* ---------- Versements (manuels) ---------- */

export function Payouts() {
  const toast = useToast()
  const { refresh } = useCounts()
  const { data, error, loading, reload } = useAsync(() => api.payouts(), [])
  const [confirm, setConfirm] = useState<PaymentRow | null>(null)

  return (
    <>
      <PageHeader
        title="Versements"
        subtitle="GeniusPay ne verse pas automatiquement : envoie chaque montant depuis ton compte marchand, puis marque-le comme versé. La personne est prévenue."
      />
      {error && <ErrorBox message={error} onRetry={reload} />}
      {loading && !data ? (
        <Loading />
      ) : (
        data && (
          <>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <div className="flex flex-col gap-1 rounded-card bg-ink p-5 text-sand">
                <span className="text-[13px] font-semibold text-ink-muted">À envoyer</span>
                <span className="tabular font-display text-[30px] leading-none font-extrabold">
                  {fcfa(data.total)} <span className="font-sans text-sm font-bold text-ink-muted">FCFA</span>
                </span>
                <span className="text-[12px] font-semibold text-ink-muted">{data.pending.length} versement(s)</span>
              </div>
              <div className="flex items-center lg:col-span-2 rounded-card bg-white p-5 text-[13px] leading-relaxed font-semibold text-muted">
                Les remboursements concernent des demandes refusées, expirées ou annulées : le membre a payé et n’a pas eu de place. Les retraits sont les gains que les hôtes
                demandent. Délai annoncé dans l’app : 48 h.
              </div>
            </div>

            <Panel title="À verser" pad={false}>
              <Table head={['Bénéficiaire', 'Type', 'Numéro', 'Opérateur', 'Montant', 'Depuis', '']} empty={data.pending.length === 0}>
                {data.pending.map((p) => (
                  <Tr key={p.id}>
                    <Td>
                      <Link to={`/users/${p.user?.id}`} className="flex items-center gap-2.5 font-bold hover:underline">
                        <Avatar name={p.user?.name ?? '?'} size={28} />
                        {p.user?.name}
                      </Link>
                    </Td>
                    <Td>
                      <span className={cx('font-semibold', p.type === 'refund' ? 'text-err-ink' : 'text-info')}>{PAYMENT_TYPE[p.type]}</span>
                      <span className="block text-[12px] font-semibold text-muted">{p.label}</span>
                    </Td>
                    <Td>
                      <span className="tabular font-bold">{phone(p.phone)}</span> {p.phone && <Copy text={p.phone} />}
                    </Td>
                    <Td className="font-semibold">{p.methodLabel}</Td>
                    <Td className="tabular font-display text-base font-extrabold">{fcfa(p.amount)}</Td>
                    <Td className={cx('text-[13px] font-semibold', Date.now() - Date.parse(p.createdAt) > 36 * 3600e3 ? 'text-err' : 'text-muted')}>{ago(p.createdAt)}</Td>
                    <Td>
                      <Button size="xs" block={false} onClick={() => setConfirm(p)}>
                        Marquer versé
                      </Button>
                    </Td>
                  </Tr>
                ))}
              </Table>
            </Panel>

            <Panel title="Derniers versements faits" pad={false}>
              <Table head={['Bénéficiaire', 'Type', 'Numéro', 'Montant', 'Versé']} empty={data.done.length === 0}>
                {data.done.map((p) => (
                  <Tr key={p.id}>
                    <Td className="font-bold">{p.user?.name}</Td>
                    <Td className="font-semibold">{PAYMENT_TYPE[p.type]}</Td>
                    <Td className="tabular font-semibold">{phone(p.phone)}</Td>
                    <Td className="tabular font-bold">{fcfa(p.amount)}</Td>
                    <Td className="text-[13px] font-semibold text-muted">{dateTime(p.confirmedAt)}</Td>
                  </Tr>
                ))}
              </Table>
            </Panel>
          </>
        )
      )}

      {confirm && (
        <Confirm
          title={`Versement de ${fcfa(confirm.amount)} FCFA envoyé ?`}
          text={
            <>
              Confirme uniquement après avoir envoyé l’argent à <b className="text-ink">{confirm.user?.name}</b> sur le <b className="text-ink">{phone(confirm.phone)}</b> ({confirm.methodLabel}).
            </>
          }
          confirm="Oui, c’est versé"
          reason={{ label: 'Référence du transfert (facultatif)', placeholder: 'Ex. : ID de transaction Wave' }}
          onCancel={() => setConfirm(null)}
          onConfirm={async (note) => {
            await api.markPaid(confirm.id, note || undefined)
            setConfirm(null)
            toast({ tone: 'success', text: `Versement enregistré · ${confirm.user?.name} est prévenu·e` })
            reload()
            refresh()
          }}
        />
      )}
    </>
  )
}

/* ---------- Paiements ---------- */

export function Payments() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  // Par défaut : ce que les membres ont payé (les versements aux hôtes ont leur propre onglet).
  const type = params.get('type') ?? 'subscription'
  const [q, setQ] = useState(params.get('q') ?? '')
  const [page, setPage] = useState(1)
  // Lien « Issu du paiement … » : la recherche suit l'URL.
  const urlQ = params.get('q') ?? ''
  useEffect(() => setQ(urlQ), [urlQ])
  const debounced = useDebounced(q)
  const [open, setOpen] = useState<PaymentRow | null>(null)
  const { data, error, loading, reload } = useAsync(() => api.payments({ status, type, q: debounced, page }), [status, type, debounced, page])
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params)
    if (v || k === 'type') next.set(k, v)
    else next.delete(k)
    setPage(1)
    setParams(next)
  }

  return (
    <>
      <PageHeader
        title="Paiements"
        subtitle="Payés par les membres : l’argent qui entre. Gains des hôtes : ce que Sub.ci leur reverse, mois par mois, 48 h après le début de chaque mois payé."
      />
      <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
        <Segments
          value={type as '' | 'subscription' | 'earning' | 'withdrawal' | 'refund'}
          onChange={(v) => set('type', v)}
          options={[
            { value: 'subscription', label: 'Payés par les membres' },
            { value: 'earning', label: 'Gains des hôtes' },
            { value: 'withdrawal', label: 'Retraits' },
            { value: 'refund', label: 'Remboursements' },
            { value: '', label: 'Tout' },
          ]}
        />
        <Segments
          value={status as '' | 'pending' | 'succeeded' | 'failed' | 'expired'}
          onChange={(v) => set('status', v)}
          options={[{ value: '', label: 'Tout statut' }, ...(['pending', 'succeeded', 'failed', 'expired'] as const).map((s) => ({ value: s, label: PAYMENT_STATUS[s][0] }))]}
        />
        <div className="lg:ml-auto">
          <SearchInput value={q} onChange={setQ} placeholder="Référence, numéro, nom…" />
        </div>
      </div>
      {error && <ErrorBox message={error} onRetry={reload} />}
      <Panel pad={false}>
        {loading && !data ? (
          <Loading />
        ) : (
          <>
            <Table head={['Référence', 'Personne', 'Libellé', 'Type', 'Moyen', 'Montant', 'Statut', 'Date']} empty={data?.data.length === 0}>
              {data?.data.map((p) => (
                <Tr key={p.id} onClick={() => setOpen(p)}>
                  <Td className="tabular text-[13px] font-bold">{p.ref}</Td>
                  <Td className="font-semibold">{p.user?.name}</Td>
                  <Td className="max-w-[220px] truncate font-semibold">
                    <span className="flex items-center gap-2">
                      {p.service && <Brandmark s={p.service} size={22} />}
                      <span className="flex min-w-0 flex-col">
                        <span className="truncate">{p.label}</span>
                        {p.source && <span className="text-[11px] font-semibold text-muted">payé par {p.source.user} · {p.source.ref}</span>}
                      </span>
                    </span>
                  </Td>
                  <Td desktop className="text-[13px] font-semibold text-muted">{PAYMENT_TYPE[p.type]}</Td>
                  <Td desktop className="text-[13px] font-semibold">{p.methodLabel}</Td>
                  <Td className={cx('tabular font-bold', p.direction === 'in' && p.type === 'earning' && 'text-ok-ink')}>{fcfa(p.amount)}</Td>
                  <Td>
                    <PaymentState p={p} />
                    {p.refundedAt && <span className="ml-1 text-[11px] font-extrabold text-err">remboursé</span>}
                  </Td>
                  <Td className="text-[13px] font-semibold text-muted">{dateTime(p.createdAt)}</Td>
                </Tr>
              ))}
            </Table>
            {data && <Pager page={data.meta.page} pages={data.meta.pages} onPage={setPage} />}
          </>
        )}
      </Panel>
      <PaymentDrawer payment={open} onClose={() => setOpen(null)} onChanged={(p) => (setOpen(p), reload())} />
    </>
  )
}

/** Où va l'argent payé par le membre : commission Sub.ci + un versement à l'hôte par mois. */
function Split({ id, amount }: { id: number; amount: number }) {
  const { data, loading } = useAsync(() => api.payment(id).then((r) => r.data.split), [id])
  if (loading && !data) return <Loading />
  if (!data) return null
  const toHost = data.installments.filter((i) => i.status !== 'failed').reduce((n, i) => n + i.amount, 0)

  return (
    <Panel title="Où va cet argent">
      <div className="flex flex-col gap-3 text-sm">
        <div className="flex justify-between font-semibold">
          <span className="text-muted">Payé par le membre</span>
          <span className="tabular font-bold">{fcfa(amount)} FCFA</span>
        </div>
        <div className="flex justify-between font-semibold">
          <span className="text-muted">Commission Sub.ci (10 %)</span>
          <span className="tabular font-bold">{fcfa(data.commission)} FCFA</span>
        </div>
        {data.refunded > 0 && (
          <div className="flex justify-between font-semibold text-err-ink">
            <span>Rendu au membre</span>
            <span className="tabular font-bold">{fcfa(data.refunded)} FCFA</span>
          </div>
        )}
        <div className="flex justify-between border-t border-line-soft pt-3 font-semibold">
          <span className="text-muted">Pour {data.host ? <Link to={`/users/${data.host.id}`} className="font-bold text-ink hover:underline">{data.host.name}</Link> : 'l’hôte'}</span>
          <span className="tabular font-bold">{fcfa(toHost)} FCFA</span>
        </div>
        {data.installments.length === 0 ? (
          <p className="text-[13px] font-semibold text-muted">Rien de versé à l’hôte : demande pas encore acceptée, refusée ou remboursée.</p>
        ) : (
          <ol className="flex flex-col divide-y divide-line-soft rounded-tile bg-sand">
            {data.installments.map((i, k) => (
              <li key={i.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                <span className="flex flex-col">
                  <span className="font-bold">Mois {k + 1}</span>
                  <span className="text-[12px] font-semibold text-muted">à partir du {date(i.periodStart)}</span>
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  <span className="tabular font-bold whitespace-nowrap">{fcfa(i.amount)}</span>
                  <PaymentState p={{ type: 'earning', ...i }} />
                </span>
              </li>
            ))}
          </ol>
        )}
      </div>
    </Panel>
  )
}

/** Statut : un gain d'hôte « en attente » n'est pas un paiement bloqué, c'est un versement prévu. */
function PaymentState({ p }: { p: { type: string; status: string; availableAt: string | null; heldAt: string | null } }) {
  if (p.type === 'earning') {
    if (p.status === 'succeeded') return <Pill tone="ok">Versé au solde</Pill>
    if (p.status === 'failed') return <Pill tone="muted">Rendu au membre</Pill>
    if (p.heldAt) return <Pill tone="warn">Gelé · souci</Pill>
    return <Pill tone="info">Prévu le {date(p.availableAt)}</Pill>
  }
  return <StatusPill map={PAYMENT_STATUS} value={p.status} />
}

function PaymentDrawer({ payment: p, onClose, onChanged }: { payment: PaymentRow | null; onClose: () => void; onChanged: (p: PaymentRow) => void }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  return (
    <Drawer open={!!p} onClose={onClose} title={p ? <span className="tabular font-display text-lg font-bold">{p.ref}</span> : 'Paiement'}>
      {p && (
        <div className="flex flex-col gap-4">
          <Panel>
            <div className="flex items-center justify-between">
              <span className="tabular font-display text-[32px] font-extrabold">
                {fcfa(p.amount)} <span className="font-sans text-sm font-bold text-muted">FCFA</span>
              </span>
              <PaymentState p={p} />
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
              {[
                ['Type', PAYMENT_TYPE[p.type]],
                ['Libellé', p.label],
                ['Personne', p.user ? `${p.user.name} · ${phone(p.user.phone)}` : '—'],
                ['Moyen', `${p.methodLabel}${p.phone ? ` · ${phone(p.phone)}` : ''}`],
                ['Créé', dateTime(p.createdAt)],
                ['Confirmé', dateTime(p.confirmedAt)],
                ['Réf. GeniusPay', p.providerRef ?? '—'],
                ['Demande hôte', p.joinStatus ? REQUEST_STATUS[p.joinStatus]?.[0] ?? p.joinStatus : '—'],
              ].map(([k, v]) => (
                <div key={k} className="flex flex-col gap-0.5">
                  <dt className="text-[12px] font-semibold text-muted">{k}</dt>
                  <dd className="font-bold break-words">{v}</dd>
                </div>
              ))}
            </dl>
          </Panel>
          {p.type === 'subscription' && p.status === 'succeeded' && <Split id={p.id} amount={p.amount} />}
          {p.source && (
            <Link to={`/payments?type=&q=${encodeURIComponent(p.source.ref)}`} onClick={onClose} className="rounded-card bg-white px-5 py-4 text-sm font-bold hover:bg-line">
              Issu du paiement {p.source.ref} de {p.source.user} →
            </Link>
          )}
          {p.user && (
            <Link to={`/users/${p.user.id}`} className="rounded-card bg-white px-5 py-4 text-sm font-bold hover:bg-line">
              Voir la fiche de {p.user.name} →
            </Link>
          )}
          {p.type === 'subscription' && p.status === 'pending' && (
            <Button
              variant="ink"
              loading={busy}
              onClick={async () => {
                setBusy(true)
                try {
                  const { data } = await api.reconcile(p.id)
                  onChanged(data)
                  toast({ tone: data.status === 'succeeded' ? 'success' : 'ink', text: `Statut chez GeniusPay : ${PAYMENT_STATUS[data.status][0]}` })
                } catch (e) {
                  toast({ tone: 'error', text: errorText(e) })
                } finally {
                  setBusy(false)
                }
              }}
            >
              ↻ Relire chez GeniusPay
            </Button>
          )}
        </div>
      )}
    </Drawer>
  )
}

/* ---------- Demandes ---------- */

export function Requests() {
  const toast = useToast()
  const { refresh } = useCounts()
  const [status, setStatus] = useState<RequestRow['status']>('pending')
  const { data, error, loading, reload } = useAsync(() => api.requests(status), [status])
  const [confirm, setConfirm] = useState<RequestRow | null>(null)
  const counts = data?.counts ?? {}

  return (
    <>
      <PageHeader title="Demandes" subtitle="Membres qui ont payé et attendent la réponse d’un hôte (24 h max, remboursés sinon)." />
      <Segments
        value={status}
        onChange={setStatus}
        options={(['pending', 'accepted', 'declined', 'expired', 'cancelled'] as const).map((s) => ({ value: s, label: REQUEST_STATUS[s][0], count: counts[s] ?? 0 }))}
      />
      {error && <ErrorBox message={error} onRetry={reload} />}
      <Panel pad={false}>
        {loading && !data ? (
          <Loading />
        ) : (
          <Table head={['Membre', 'Fiabilité', 'Chez', 'Montant', status === 'pending' ? 'Expire' : 'Décidée', '']} empty={data?.data.length === 0}>
            {data?.data.map((r) => {
              const soon = status === 'pending' && Date.parse(r.expiresAt) - Date.now() < 3 * 3600e3
              return (
                <Tr key={r.id}>
                  <Td>
                    <Link to={`/users/${r.member.id}`} className="flex flex-col hover:underline">
                      <span className="font-bold">{r.member.name}</span>
                      <span className="tabular text-[12px] font-semibold text-muted">{phone(r.member.phone)}</span>
                    </Link>
                  </Td>
                  <Td className="text-[13px] font-semibold text-muted">
                    {r.member.paidCount} paiement(s) · {r.member.removalsCount ? <b className="text-warn-ink">retiré·e {r.member.removalsCount}×</b> : 'jamais retiré·e'}
                  </Td>
                  <Td>
                    <span className="flex items-center gap-2">
                      <Brandmark s={r.service} size={26} />
                      <span className="flex flex-col">
                        <Link to={`/users/${r.host.id}`} className="font-bold hover:underline">
                          {r.host.name}
                        </Link>
                        <span className="text-[12px] font-semibold text-muted">{r.plan}</span>
                      </span>
                    </span>
                  </Td>
                  <Td className="tabular font-bold">
                    {fcfa(r.amount)} <span className="text-[12px] font-semibold text-muted">· {r.months} mois</span>
                  </Td>
                  <Td className={cx('text-[13px] font-semibold', soon ? 'text-err' : 'text-muted')}>{status === 'pending' ? ago(r.expiresAt) : dateTime(r.decidedAt)}</Td>
                  <Td>
                    {status === 'pending' && (
                      <Button size="xs" variant="outline" block={false} onClick={() => setConfirm(r)}>
                        Refuser et rembourser
                      </Button>
                    )}
                  </Td>
                </Tr>
              )
            })}
          </Table>
        )}
      </Panel>
      {confirm && (
        <Confirm
          title="Refuser à la place de l’hôte ?"
          text={`${confirm.member.name} sera remboursé·e de ${fcfa(confirm.amount)} FCFA (versement à faire dans Versements) et la place chez ${confirm.host.name} redevient libre.`}
          confirm="Refuser et rembourser"
          danger
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            await api.declineRequest(confirm.id)
            setConfirm(null)
            toast({ tone: 'success', text: 'Demande refusée · remboursement ajouté aux versements' })
            reload()
            refresh()
          }}
        />
      )}
    </>
  )
}
