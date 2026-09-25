import { useState } from 'react'
import { Link } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, errorText, type AuditRow, type ServiceRow } from '../api'
import { Brandmark, ErrorBox, Loading, PageHeader, Pager, Panel, Table, Td, Tr, cx, dateTime, fcfa, useAsync } from '../kit'

/* ---------- Catalogue ---------- */

export function Catalog() {
  const { data, error, loading, reload, setData } = useAsync(() => api.services().then((r) => r.data), [])
  const toast = useToast()

  const save = async (s: ServiceRow, patch: Parameters<typeof api.updateService>[1]) => {
    const before = data
    setData((list) => list?.map((x) => (x.id === s.id ? { ...x, ...patch } : x)) ?? null)
    try {
      await api.updateService(s.id, patch)
      toast({ tone: 'success', text: `${s.name} mis à jour`, duration: 1800 })
    } catch (e) {
      setData(before)
      toast({ tone: 'error', text: errorText(e) })
    }
  }

  return (
    <>
      <PageHeader
        title="Catalogue"
        subtitle="Prix de référence par place affichés dans l’app, services visibles et mis en avant. Les hôtes fixent leur prix dans les limites de leur formule."
      />
      {error && <ErrorBox message={error} onRetry={reload} />}
      <Panel pad={false}>
        {loading && !data ? (
          <Loading />
        ) : (
          <Table head={['Service', 'Prix / place', 'Prix officiel', 'Offres en ligne', 'Places libres', 'Membres', 'Encaissé ce mois', 'Visible', 'En avant']} empty={data?.length === 0}>
            {data?.map((s) => (
              <Tr key={s.id}>
                <Td>
                  <span className={cx('flex items-center gap-2.5', !s.isActive && 'opacity-50')}>
                    <Brandmark s={{ id: s.slug, name: s.name, color: s.color, fg: s.fg, mono: s.mono }} size={30} />
                    <span className="flex flex-col">
                      <span className="font-bold">{s.name}</span>
                      <span className="text-[12px] font-semibold text-muted">{s.meta}</span>
                    </span>
                  </span>
                </Td>
                <Td>
                  <MoneyInput value={s.price} onSave={(v) => save(s, { price: v })} />
                </Td>
                <Td>
                  <MoneyInput value={s.fullPrice} onSave={(v) => save(s, { fullPrice: v })} />
                </Td>
                <Td desktop className="tabular font-semibold">{s.liveOffers}</Td>
                <Td className={cx('tabular font-semibold', s.freeSeats === 0 && s.isActive && 'text-err')}>{s.freeSeats}</Td>
                <Td desktop className="tabular font-semibold">{s.members}</Td>
                <Td className="tabular font-bold">{fcfa(s.gmvMonth)}</Td>
                <Td>
                  <Toggle on={s.isActive} label={`${s.name} visible`} onChange={(v) => save(s, { isActive: v })} />
                </Td>
                <Td>
                  <Toggle on={s.isPopular} label={`${s.name} en avant`} onChange={(v) => save(s, { isPopular: v })} />
                </Td>
              </Tr>
            ))}
          </Table>
        )}
      </Panel>
      <p className="text-[13px] font-medium text-muted">Un service masqué disparaît de Découvrir et du partage ; les abonnements en cours continuent. « Places libres » à 0 : les membres ne peuvent rien rejoindre.</p>
    </>
  )
}

function MoneyInput({ value, onSave }: { value: number; onSave: (v: number) => void }) {
  const [draft, setDraft] = useState<string | null>(null)
  const commit = () => {
    if (draft === null) return
    const v = parseInt(draft.replace(/\D/g, ''), 10)
    setDraft(null)
    if (Number.isFinite(v) && v > 0 && v !== value) onSave(v)
  }
  return (
    <input
      inputMode="numeric"
      value={draft ?? fcfa(value)}
      onFocus={(e) => (setDraft(String(value)), requestAnimationFrame(() => e.target.select()))}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => {
        if (e.key === 'Enter') e.currentTarget.blur()
        if (e.key === 'Escape') (setDraft(null), e.currentTarget.blur())
      }}
      className="tabular h-9 w-24 rounded-[10px] border-[1.5px] border-transparent bg-sand px-2.5 font-bold outline-none hover:border-line focus:border-ink focus:bg-white"
    />
  )
}

function Toggle({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      onClick={() => onChange(!on)}
      className={cx('relative h-6 w-10 rounded-full transition-colors', on ? 'bg-ok' : 'bg-line')}
    >
      <span className={cx('absolute top-0.5 size-5 rounded-full bg-white shadow transition-[left]', on ? 'left-[18px]' : 'left-0.5')} />
    </button>
  )
}

/* ---------- Journal ---------- */

const ACTIONS: Record<string, string> = {
  login: 'Connexion',
  'offer.approve': 'Offre validée',
  'offer.reject': 'Offre refusée',
  'offer.suspend': 'Offre suspendue',
  'offer.resume': 'Offre relancée',
  'payout.paid': 'Versement fait',
  'payment.reconcile': 'Paiement relu',
  'user.suspend': 'Compte suspendu',
  'user.unsuspend': 'Compte réactivé',
  'request.decline': 'Demande refusée',
  'service.update': 'Catalogue modifié',
}

export function Audit() {
  const [page, setPage] = useState(1)
  const { data, error, loading, reload } = useAsync(() => api.audit(page), [page])

  return (
    <>
      <PageHeader title="Journal" subtitle="Chaque action faite depuis l’administration, avec son auteur. Rien ne peut être effacé." />
      {error && <ErrorBox message={error} onRetry={reload} />}
      <Panel pad={false}>
        {loading && !data ? (
          <Loading />
        ) : (
          <>
            <Table head={['Quand', 'Qui', 'Action', 'Sur', 'Détails', 'IP']} empty={data?.data.length === 0}>
              {data?.data.map((a) => (
                <Tr key={a.id}>
                  <Td className="text-[13px] font-semibold whitespace-nowrap text-muted">{dateTime(a.at)}</Td>
                  <Td className="font-bold">{a.admin}</Td>
                  <Td className="font-semibold">{ACTIONS[a.action] ?? a.action}</Td>
                  <Td>
                    <Subject a={a} />
                  </Td>
                  <Td className="max-w-[360px] text-[13px] font-medium text-muted">
                    <Meta meta={a.meta} />
                  </Td>
                  <Td desktop className="tabular text-[12px] font-semibold text-subtle">{a.ip ?? '—'}</Td>
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

function Subject({ a }: { a: AuditRow }) {
  if (!a.subjectType || !a.subjectId) return <span className="text-muted">—</span>
  const type = a.subjectType.split('\\').pop()
  const to = type === 'User' ? `/users/${a.subjectId}` : type === 'HostOffer' ? `/offers?open=${a.subjectId}` : null
  const label = { User: 'Utilisateur', HostOffer: 'Offre', Payment: 'Paiement', JoinRequest: 'Demande', Service: 'Service' }[type ?? ''] ?? type
  const text = `${label} #${a.subjectId}`
  return to ? (
    <Link to={to} className="text-[13px] font-bold hover:underline">
      {text}
    </Link>
  ) : (
    <span className="text-[13px] font-semibold">{text}</span>
  )
}

function Meta({ meta }: { meta: AuditRow['meta'] }) {
  if (!meta) return null
  const parts = Object.entries(meta).map(([k, v]) => {
    const val = typeof v === 'object' && v !== null ? Object.entries(v).map(([a, b]) => `${a}: ${String(b)}`).join(', ') : String(v)
    return `${k} · ${val}`
  })
  return <span className="line-clamp-2">{parts.join(' — ')}</span>
}
