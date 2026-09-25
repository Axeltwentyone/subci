import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, type UserDetail } from '../api'
import {
  Avatar, Brandmark, Button, Confirm, Copy, ErrorBox, Loading, OFFER_STATUS, PAYMENT_STATUS, PAYMENT_TYPE, PageHeader, Pager, Panel, Pill, REQUEST_STATUS, SearchInput, Segments, Stat, StatusPill, Table, Td, Tr, ago, cx, date, dateTime, fcfa,
  phone, useAsync, useDebounced,
} from '../kit'

type Filter = '' | 'members' | 'hosts' | 'suspended'

export function Users() {
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<Filter>('')
  const [page, setPage] = useState(1)
  const debounced = useDebounced(q)
  const { data, error, loading, reload } = useAsync(() => api.users({ q: debounced, filter, page }), [debounced, filter, page])

  return (
    <>
      <PageHeader title="Utilisateurs" subtitle={data ? `${fcfa(data.meta.total)} compte(s)` : undefined} />
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
        <Segments
          value={filter}
          onChange={(v) => (setFilter(v), setPage(1))}
          options={[
            { value: '', label: 'Tous' },
            { value: 'members', label: 'Membres' },
            { value: 'hosts', label: 'Hôtes' },
            { value: 'suspended', label: 'Suspendus' },
          ]}
        />
        <div className="lg:ml-auto">
          <SearchInput value={q} onChange={(v) => (setQ(v), setPage(1))} placeholder="Nom, numéro, code parrain…" />
        </div>
      </div>
      {error && <ErrorBox message={error} onRetry={reload} />}
      <Panel pad={false}>
        {loading && !data ? (
          <Loading />
        ) : (
          <>
            <Table head={['Nom', 'Numéro', 'Abonnements', 'Offres en ligne', 'Total payé', 'Solde hôte', 'Inscrit']} empty={data?.data.length === 0}>
              {data?.data.map((u) => (
                <Tr key={u.id}>
                  <Td>
                    <Link to={`/users/${u.id}`} className="flex items-center gap-2.5 font-bold hover:underline">
                      <Avatar name={u.name ?? '?'} size={30} />
                      <span className="flex flex-col">
                        {u.name ?? <span className="text-muted">Sans nom</span>}
                        {u.suspendedAt && <span className="text-[11px] font-extrabold text-err">Suspendu</span>}
                      </span>
                    </Link>
                  </Td>
                  <Td className="tabular font-semibold">{phone(u.phone)}</Td>
                  <Td desktop className="tabular font-semibold">{u.activeSubs ?? 0}</Td>
                  <Td desktop className="tabular font-semibold">{u.liveOffers ?? 0}</Td>
                  <Td className="tabular font-bold">{fcfa(u.totalPaid ?? 0)}</Td>
                  <Td className={cx('tabular font-bold', u.balance > 0 ? 'text-ok-ink' : 'text-muted')}>{fcfa(u.balance)}</Td>
                  <Td desktop className="text-[13px] font-semibold text-muted">{date(u.createdAt)}</Td>
                </Tr>
              ))}
            </Table>
            {data && <Pager page={data.meta.page} pages={data.meta.pages} onPage={setPage} />}
          </>
        )}
      </Panel>
    </>
  )
}

export function UserPage() {
  const id = Number(useParams().id)
  const toast = useToast()
  const { data, error, loading, reload, setData } = useAsync(() => api.user(id).then((r) => r.data), [id])
  const [confirm, setConfirm] = useState<'suspend' | 'unsuspend' | null>(null)

  if (error) return <ErrorBox message={error} onRetry={reload} />
  if (loading && !data) return <Loading />
  if (!data) return null
  const u: UserDetail = data

  return (
    <>
      <Link to="/users" className="-mb-3 text-[13px] font-bold text-muted hover:text-ink">
        ← Utilisateurs
      </Link>
      <div className="flex flex-wrap items-center gap-4">
        <Avatar name={u.name ?? '?'} size={60} />
        <div className="flex min-w-0 flex-1 basis-60 flex-col gap-1">
          <h1 className="flex items-center gap-3 font-display text-[26px] leading-none font-extrabold lg:text-[30px] tracking-[-0.02em]">
            {u.name ?? 'Sans nom'}
            {u.suspendedAt && <Pill tone="err">Suspendu</Pill>}
          </h1>
          <p className="flex flex-wrap items-center gap-x-3 text-sm font-semibold text-muted">
            <span className="tabular">{phone(u.phone)}</span> <Copy text={u.phone} />
            <span>· Code {u.referralCode}</span>
            <span>· Inscrit le {date(u.createdAt)}</span>
            <span>· {u.devices} appareil(s) avec notifications</span>
            {u.referral && (
              <span>
                · {u.referral.friends} filleul(s){u.referral.credit ? ` · ${fcfa(u.referral.credit)} F de crédit` : ''}
                {u.referral.referredBy ? ` · parrainé·e par ${u.referral.referredBy}` : ''}
              </span>
            )}
          </p>
        </div>
        {u.suspendedAt ? (
          <Button variant="outline" size="sm" block={false} onClick={() => setConfirm('unsuspend')}>
            Réactiver le compte
          </Button>
        ) : (
          <Button variant="danger" size="sm" block={false} onClick={() => setConfirm('suspend')}>
            Suspendre
          </Button>
        )}
      </div>

      {u.suspendedAt && (
        <div className="rounded-card bg-err-soft px-5 py-4 text-sm font-semibold text-err-ink">
          Suspendu {ago(u.suspendedAt)} · « {u.suspensionReason} ». Il ne peut plus se connecter ni payer ; ses offres sont en pause.
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4 [&>:nth-child(4)]:max-lg:col-span-2 [&>:nth-child(4)]:max-lg:order-first">
        <Stat label="Total payé" value={fcfa(u.stats.totalPaid)} unit="FCFA" />
        <Stat label="Gagné comme hôte" value={fcfa(u.stats.totalEarned)} unit="FCFA" />
        <Stat label="Retiré" value={fcfa(u.stats.totalWithdrawn)} unit="FCFA" />
        <Stat tone="ink" label="Solde actuel" value={fcfa(u.balance)} unit="FCFA" hint={u.payout.method ? `Retrait : ${u.payout.method} · ${phone(u.payout.phone)}` : 'Pas de moyen de retrait'} />
        <Stat
          label="Fiabilité"
          value={`${u.paidCount} paiement${u.paidCount > 1 ? 's' : ''}`}
          hint={u.removalsCount ? <span className="text-warn-ink">Retiré·e {u.removalsCount}× d’un cercle</span> : 'Jamais retiré·e d’un cercle'}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Panel title={`Abonnements (${u.subscriptions.length})`} pad={false}>
          <Table head={['Service', 'Chez', 'Statut', 'Jusqu’au']} empty={u.subscriptions.length === 0}>
            {u.subscriptions.map((s) => (
              <Tr key={s.id}>
                <Td>
                  <span className="flex items-center gap-2 font-bold">
                    <Brandmark s={s.service} size={26} />
                    {s.service.name}
                  </span>
                </Td>
                <Td className="font-semibold">{s.host ? <Link to={`/users/${s.host.id}`} className="hover:underline">{s.host.name}</Link> : '—'}</Td>
                <Td>
                  <Pill tone={s.status === 'active' ? 'ok' : s.status === 'expiring' ? 'warn' : 'muted'}>{SUB_STATUS[s.status] ?? s.status}</Pill>
                </Td>
                <Td className="text-[13px] font-semibold text-muted">
                  {date(s.endsAt)} {s.autoRenew && <span title="Renouvellement automatique">↻</span>}
                </Td>
              </Tr>
            ))}
          </Table>
        </Panel>

        <Panel title={`Offres partagées (${u.offers.length})`} pad={false}>
          <Table head={['Service', 'Places', 'Prix', 'Statut']} empty={u.offers.length === 0}>
            {u.offers.map((o) => (
              <Tr key={o.id}>
                <Td>
                  <Link to={`/offers?open=${o.id}`} className="flex items-center gap-2 font-bold hover:underline">
                    <Brandmark s={o.service} size={26} />
                    <span className="flex flex-col">
                      {o.service.name}
                      <span className="text-[12px] font-semibold text-muted">{o.plan}</span>
                    </span>
                  </Link>
                </Td>
                <Td className="tabular font-semibold">
                  {o.members.length}/{o.seats}
                  {o.pendingRequests > 0 && <span className="ml-1 text-[12px] font-bold text-warn-ink">+{o.pendingRequests} en attente</span>}
                </Td>
                <Td className="tabular font-bold">{fcfa(o.price)}</Td>
                <Td>
                  <StatusPill map={OFFER_STATUS} value={o.status} />
                </Td>
              </Tr>
            ))}
          </Table>
        </Panel>
      </div>

      {u.requests.length > 0 && (
        <Panel title="Demandes pour rejoindre" pad={false}>
          <Table head={['Service', 'Hôte', 'Montant', 'Statut', 'Date']}>
            {u.requests.map((r) => (
              <Tr key={r.id}>
                <Td>
                  <span className="flex items-center gap-2 font-bold">
                    <Brandmark s={r.service} size={26} />
                    {r.service.name} <span className="text-[12px] font-semibold text-muted">{r.plan}</span>
                  </span>
                </Td>
                <Td className="font-semibold">
                  <Link to={`/users/${r.host.id}`} className="hover:underline">
                    {r.host.name}
                  </Link>
                </Td>
                <Td className="tabular font-bold">{fcfa(r.amount)}</Td>
                <Td>
                  <StatusPill map={REQUEST_STATUS} value={r.status} />
                </Td>
                <Td className="text-[13px] font-semibold text-muted">{dateTime(r.createdAt)}</Td>
              </Tr>
            ))}
          </Table>
        </Panel>
      )}

      <Panel title="Paiements" action={<Link to={`/payments?q=${encodeURIComponent(u.phone)}`} className="text-[13px] font-bold text-muted hover:text-ink">Tout voir →</Link>} pad={false}>
        <Table head={['Référence', 'Libellé', 'Type', 'Montant', 'Statut', 'Date']} empty={u.payments.length === 0}>
          {u.payments.map((p) => (
            <Tr key={p.id}>
              <Td className="tabular text-[13px] font-bold">{p.ref}</Td>
              <Td className="font-semibold">{p.label}</Td>
              <Td desktop className="text-[13px] font-semibold text-muted">{PAYMENT_TYPE[p.type]}</Td>
              <Td className="tabular font-bold">{fcfa(p.amount)}</Td>
              <Td>
                <StatusPill map={PAYMENT_STATUS} value={p.status} />
              </Td>
              <Td className="text-[13px] font-semibold text-muted">{dateTime(p.createdAt)}</Td>
            </Tr>
          ))}
        </Table>
      </Panel>

      {confirm === 'suspend' && (
        <Confirm
          title={`Suspendre ${u.name ?? 'ce compte'} ?`}
          text="Déconnexion immédiate, plus de paiement ni de connexion. Ses offres passent en pause ; ses membres gardent leur accès en cours."
          confirm="Suspendre"
          danger
          reason={{ label: 'Motif (visible dans le journal)', required: true, placeholder: 'Ex. : identifiants faux signalés par 3 membres' }}
          onCancel={() => setConfirm(null)}
          onConfirm={async (reason) => {
            const r = await api.suspend(u.id, reason)
            setData(r.data)
            setConfirm(null)
            toast({ tone: 'success', text: 'Compte suspendu' })
          }}
        />
      )}
      {confirm === 'unsuspend' && (
        <Confirm
          title="Réactiver ce compte ?"
          text="La personne pourra de nouveau se connecter. Ses offres restent en pause : elle les relance elle-même."
          confirm="Réactiver"
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            const r = await api.unsuspend(u.id)
            setData(r.data)
            setConfirm(null)
            toast({ tone: 'success', text: 'Compte réactivé' })
          }}
        />
      )}
    </>
  )
}

const SUB_STATUS: Record<string, string> = { active: 'Actif', expiring: 'Bientôt fini', expired: 'Terminé', cancelled: 'Annulé', pending: 'En attente' }
