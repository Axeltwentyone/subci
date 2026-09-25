import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, errorText, type PaymentRow, type RequestRow } from '../api'
import { useCounts } from '../shell'
import {
  Avatar, Brandmark, Button, Confirm, Copy, Drawer, ErrorBox, Loading, PAYMENT_STATUS, PAYMENT_TYPE, PageHeader, Pager, Panel, REQUEST_STATUS, SearchInput, Segments, StatusPill, Table, Td, Tr, ago, cx, dateTime, fcfa, phone,
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
            <div className="grid grid-cols-3 gap-4">
              <div className="flex flex-col gap-1 rounded-card bg-ink p-5 text-sand">
                <span className="text-[13px] font-semibold text-ink-muted">À envoyer</span>
                <span className="tabular font-display text-[30px] leading-none font-extrabold">
                  {fcfa(data.total)} <span className="font-sans text-sm font-bold text-ink-muted">FCFA</span>
                </span>
                <span className="text-[12px] font-semibold text-ink-muted">{data.pending.length} versement(s)</span>
              </div>
              <div className="col-span-2 flex items-center rounded-card bg-white p-5 text-[13px] leading-relaxed font-semibold text-muted">
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
  const type = params.get('type') ?? ''
  const [q, setQ] = useState(params.get('q') ?? '')
  const [page, setPage] = useState(1)
  const debounced = useDebounced(q)
  const [open, setOpen] = useState<PaymentRow | null>(null)
  const { data, error, loading, reload } = useAsync(() => api.payments({ status, type, q: debounced, page }), [status, type, debounced, page])
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params)
    if (v) next.set(k, v)
    else next.delete(k)
    setPage(1)
    setParams(next)
  }

  return (
    <>
      <PageHeader title="Paiements" subtitle="Tous les mouvements d’argent : abonnements, gains des hôtes, retraits et remboursements." />
      <div className="flex flex-wrap items-center gap-3">
        <Segments
          value={type as '' | 'subscription' | 'earning' | 'withdrawal' | 'refund'}
          onChange={(v) => set('type', v)}
          options={[{ value: '', label: 'Tous' }, ...(['subscription', 'earning', 'withdrawal', 'refund'] as const).map((t) => ({ value: t, label: PAYMENT_TYPE[t] }))]}
        />
        <Segments
          value={status as '' | 'pending' | 'succeeded' | 'failed' | 'expired'}
          onChange={(v) => set('status', v)}
          options={[{ value: '', label: 'Tout statut' }, ...(['pending', 'succeeded', 'failed', 'expired'] as const).map((s) => ({ value: s, label: PAYMENT_STATUS[s][0] }))]}
        />
        <div className="ml-auto">
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
                      <span className="truncate">{p.label}</span>
                    </span>
                  </Td>
                  <Td className="text-[13px] font-semibold text-muted">{PAYMENT_TYPE[p.type]}</Td>
                  <Td className="text-[13px] font-semibold">{p.methodLabel}</Td>
                  <Td className={cx('tabular font-bold', p.direction === 'in' && p.type === 'earning' && 'text-ok-ink')}>{fcfa(p.amount)}</Td>
                  <Td>
                    <StatusPill map={PAYMENT_STATUS} value={p.status} />
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
              <StatusPill map={PAYMENT_STATUS} value={p.status} />
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
