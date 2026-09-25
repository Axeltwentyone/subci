import { useState } from 'react'
import { Link } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, type DisputeRow } from '../api'
import { useCounts } from '../shell'
import { Brandmark, Button, Confirm, ErrorBox, Loading, PageHeader, Panel, Pill, Segments, ago, cx, date, dateTime, fcfa, phone, useAsync } from '../kit'

type Status = DisputeRow['status']

const STATUS: Record<Status, string> = {
  open: 'Ouverts',
  solved: 'Réglés par le membre',
  refunded: 'Remboursés',
  rejected: 'Clos sans remboursement',
}

/**
 * Soucis signalés par les membres (« Un souci ? »).
 * Tant que c'est ouvert, les gains de l'hôte pour ce membre sont gelés.
 */
export function Disputes() {
  const toast = useToast()
  const { refresh } = useCounts()
  const [status, setStatus] = useState<Status>('open')
  const { data, error, loading, reload } = useAsync(() => api.disputes(status), [status])
  const [decide, setDecide] = useState<{ d: DisputeRow; refund: boolean } | null>(null)
  const counts = data?.counts ?? {}

  return (
    <>
      <PageHeader
        title="Soucis signalés"
        subtitle="Un membre n’a plus accès : les gains de l’hôte pour lui sont gelés. Rembourse le temps pas encore versé à l’hôte, ou clos si l’accès fonctionne."
      />
      <Segments value={status} onChange={setStatus} options={(Object.keys(STATUS) as Status[]).map((s) => ({ value: s, label: STATUS[s], count: counts[s] ?? 0 }))} />
      {error && <ErrorBox message={error} onRetry={reload} />}
      {loading && !data ? (
        <Loading />
      ) : data?.data.length === 0 ? (
        <Panel>
          <p className="py-6 text-center text-sm font-medium text-muted">{status === 'open' ? 'Aucun souci en cours.' : 'Rien à afficher.'}</p>
        </Panel>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {data?.data.map((d) => (
            <DisputeCard key={d.id} d={d} onDecide={(refund) => setDecide({ d, refund })} />
          ))}
        </div>
      )}

      {decide && (
        <Confirm
          title={decide.refund ? `Rembourser ${fcfa(decide.d.refundable)} FCFA ?` : 'Clore sans remboursement ?'}
          text={
            decide.refund
              ? `${decide.d.member.name} est remboursé·e du temps pas encore versé à ${decide.d.host?.name ?? 'l’hôte'} et sort du cercle. Le versement apparaîtra dans Versements.`
              : `Les gains de ${decide.d.host?.name ?? 'l’hôte'} reprennent. ${decide.d.member.name} est prévenu·e avec ta note.`
          }
          confirm={decide.refund ? 'Rembourser' : 'Clore'}
          danger={decide.refund}
          reason={{ label: decide.refund ? 'Note (facultatif, visible dans le journal)' : 'Message au membre', placeholder: decide.refund ? 'Ex. : hôte injoignable, mot de passe changé' : 'Ex. : l’accès fonctionne, vérifié avec l’hôte' }}
          onCancel={() => setDecide(null)}
          onConfirm={async (note) => {
            await api.resolveDispute(decide.d.id, decide.refund ? 'refund' : 'reject', note || undefined)
            setDecide(null)
            toast({ tone: 'success', text: decide.refund ? 'Remboursement ajouté aux versements' : 'Souci clos · gains de l’hôte libérés' })
            reload()
            refresh()
          }}
        />
      )}
    </>
  )
}

function DisputeCard({ d, onDecide }: { d: DisputeRow; onDecide: (refund: boolean) => void }) {
  const open = d.status === 'open'
  return (
    <Panel
      title={
        <span className="flex items-center gap-2.5">
          <Brandmark s={d.service} size={28} />
          <span className="flex flex-col">
            <span>{d.reasonLabel}</span>
            <span className="text-[12px] font-semibold text-muted">
              {d.service.name} · signalé {ago(d.createdAt)}
            </span>
          </span>
        </span>
      }
      action={open ? <Pill tone="warn">Gains gelés</Pill> : <Pill tone={d.status === 'refunded' ? 'err' : 'ok'}>{STATUS[d.status]}</Pill>}
    >
      <div className="flex flex-col gap-4">
        {d.message && <blockquote className="rounded-tile bg-sand px-4 py-3 text-sm font-medium">« {d.message} »</blockquote>}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Party
            role="Membre"
            to={`/users/${d.member.id}`}
            name={d.member.name}
            tel={d.member.phone}
            facts={[`${d.member.paidCount} paiement(s)`, d.member.removalsCount ? `retiré·e ${d.member.removalsCount}×` : 'jamais retiré·e']}
          />
          {d.host ? (
            <Party
              role="Hôte"
              to={`/users/${d.host.id}`}
              name={d.host.name}
              tel={d.host.phone}
              facts={[`${d.hostDisputes} souci(s) signalé(s) au total`, d.host.removalsCount ? `a retiré ${d.host.removalsCount} membre(s)` : '']}
              warn={d.hostDisputes > 1}
            />
          ) : (
            <div className="rounded-tile bg-sand p-3 text-[13px] font-semibold text-muted">Plus rattaché à un hôte.</div>
          )}
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <dt className="text-[12px] font-semibold text-muted">Abonnement</dt>
          <dd className="text-right font-bold">
            {date(d.subscription.startsAt)} → {date(d.subscription.endsAt)}
          </dd>
          <dt className="text-[12px] font-semibold text-muted">Remboursable</dt>
          <dd className="tabular text-right font-display text-lg font-extrabold">{fcfa(d.refundable)} FCFA</dd>
        </dl>
        {!open && d.resolution && <p className="text-[13px] font-semibold text-muted">Note : {d.resolution}</p>}
        {!open && d.resolvedAt && <p className="text-[12px] font-semibold text-subtle">Clos le {dateTime(d.resolvedAt)}</p>}

        {open && (
          <div className="grid grid-cols-2 gap-2">
            <Button variant="outline" size="md" onClick={() => onDecide(false)}>
              L’accès marche
            </Button>
            <Button variant="danger" size="md" disabled={d.refundable === 0} onClick={() => onDecide(true)}>
              Rembourser
            </Button>
          </div>
        )}
        {open && d.refundable === 0 && <p className="text-[12px] font-semibold text-muted">Tout a déjà été versé à l’hôte : rien à reprendre automatiquement.</p>}
      </div>
    </Panel>
  )
}

function Party({ role, to, name, tel, facts, warn }: { role: string; to: string; name: string; tel: string; facts: string[]; warn?: boolean }) {
  return (
    <Link to={to} className={cx('flex flex-col gap-0.5 rounded-tile p-3 hover:bg-line', warn ? 'bg-warn-soft' : 'bg-sand')}>
      <span className="text-[11px] font-extrabold tracking-wider text-muted uppercase">{role}</span>
      <span className="font-bold">{name}</span>
      <span className="tabular text-[13px] font-semibold text-muted">{phone(tel)}</span>
      <span className={cx('text-[12px] font-semibold', warn ? 'text-warn-ink' : 'text-muted')}>{facts.filter(Boolean).join(' · ')}</span>
    </Link>
  )
}
