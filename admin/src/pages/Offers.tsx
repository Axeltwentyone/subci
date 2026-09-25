import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useToast } from '../../../src/components/Toast'
import { api, errorText, type OfferRow } from '../api'
import { useCounts } from '../shell'
import {
  Avatar, Brandmark, Button, Confirm, Drawer, ErrorBox, Loading, OFFER_STATUS, PageHeader, Pager, Panel, SearchInput, Segments, StatusPill, Table, Td, Tr, ago, date, fcfa, phone, useAsync, useDebounced,
} from '../kit'

const DEVICE: Record<string, string> = { phone: 'Téléphone', tablet: 'Tablette', computer: 'Ordinateur', tv: 'TV' }
type Status = 'review' | 'live' | 'paused' | 'rejected' | 'closed'

export function Offers() {
  const [params, setParams] = useSearchParams()
  const status = (params.get('status') as Status) ?? 'review'
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebounced(q)
  const [openId, setOpenId] = useState<number | null>(() => Number(params.get('open')) || null)
  const { data, error, loading, reload } = useAsync(() => api.offers({ status, q: debounced, page }), [status, debounced, page])
  const counts = data?.counts ?? {}

  return (
    <>
      <PageHeader title="Offres" subtitle="Valide les preuves d’abonnement avant que les places n’apparaissent dans le catalogue." />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Segments
          value={status}
          onChange={(v) => {
            setPage(1)
            setParams({ status: v })
          }}
          options={(['review', 'live', 'paused', 'rejected', 'closed'] as Status[]).map((s) => ({ value: s, label: OFFER_STATUS[s][0], count: counts[s] ?? 0 }))}
        />
        <SearchInput value={q} onChange={setQ} placeholder="Hôte, numéro, service…" />
      </div>

      {error && <ErrorBox message={error} onRetry={reload} />}
      <Panel pad={false}>
        {loading && !data ? (
          <Loading />
        ) : (
          <>
            <Table head={['Service', 'Hôte', 'Formule', 'Places', 'Prix', 'Statut', status === 'review' ? 'Envoyée' : 'Créée']} empty={data?.data.length === 0}>
              {data?.data.map((o) => (
                <Tr key={o.id} onClick={() => setOpenId(o.id)} active={openId === o.id}>
                  <Td>
                    <span className="flex items-center gap-2.5 font-bold">
                      <Brandmark s={o.service} size={30} />
                      {o.service.name}
                    </span>
                  </Td>
                  <Td>
                    <span className="flex flex-col">
                      <span className="font-bold">{o.host.shortName}</span>
                      <span className="tabular text-[12px] font-semibold text-muted">{phone(o.host.phone)}</span>
                    </span>
                  </Td>
                  <Td>
                    <span className="font-semibold">{o.plan}</span>
                    {o.quality && <span className="ml-1.5 rounded-md bg-sand px-1.5 py-0.5 text-[11px] font-extrabold">{o.quality}</span>}
                  </Td>
                  <Td className="tabular font-semibold">
                    {o.members.length}/{o.seats}
                    {o.pendingRequests > 0 && <span className="ml-1.5 text-[12px] font-bold text-warn-ink">+{o.pendingRequests} dem.</span>}
                  </Td>
                  <Td className="tabular font-bold">{fcfa(o.price)}</Td>
                  <Td>
                    <StatusPill map={OFFER_STATUS} value={o.status} />
                  </Td>
                  <Td className="text-[13px] font-semibold text-muted">{ago(o.createdAt)}</Td>
                </Tr>
              ))}
            </Table>
            {data && <Pager page={data.meta.page} pages={data.meta.pages} onPage={setPage} />}
          </>
        )}
      </Panel>

      <OfferDrawer id={openId} onClose={() => setOpenId(null)} onChanged={reload} />
    </>
  )
}

/** Détail d'une offre + décision de modération. */
export function OfferDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const toast = useToast()
  const { refresh } = useCounts()
  const [offer, setOffer] = useState<OfferRow | null>(null)
  const [proof, setProof] = useState<string | null>(null)
  const [proofError, setProofError] = useState<string | null>(null)
  const [zoom, setZoom] = useState(false)
  const [confirm, setConfirm] = useState<'reject' | 'toggle' | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setOffer(null)
    setProof(null)
    setProofError(null)
    if (!id) return
    let url: string | null = null
    api.offer(id).then(({ data }) => {
      setOffer(data)
      if (data.hasProof)
        api
          .proof(id)
          .then((blob) => setProof((url = URL.createObjectURL(blob))))
          .catch((e) => setProofError(errorText(e)))
    })
    return () => {
      if (url) URL.revokeObjectURL(url)
    }
  }, [id])

  const done = (o: OfferRow, msg: string) => {
    setOffer(o)
    toast({ tone: 'success', text: msg })
    onChanged()
    refresh()
  }

  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      title={
        offer ? (
          <span className="flex items-center gap-3">
            <Brandmark s={offer.service} size={36} />
            <span className="flex min-w-0 flex-col">
              <span className="truncate font-display text-lg font-bold">
                {offer.service.name} · {offer.plan}
              </span>
              <span className="text-[12px] font-semibold text-muted">
                Offre #{offer.id} · envoyée le {date(offer.createdAt)}
              </span>
            </span>
          </span>
        ) : (
          'Offre'
        )
      }
    >
      {!offer ? (
        <Loading />
      ) : (
        <div className="flex flex-col gap-4">
          <div className="flex items-center gap-2">
            <StatusPill map={OFFER_STATUS} value={offer.status} />
            {offer.rejectionReason && <span className="text-[13px] font-semibold text-err-ink">Motif : {offer.rejectionReason}</span>}
          </div>

          <Panel title="Preuve d’abonnement">
            {offer.hasProof ? (
              proof ? (
                <button type="button" onClick={() => setZoom(true)} className="block w-full overflow-hidden rounded-tile border border-line bg-sand" title="Agrandir">
                  <img src={proof} alt="Capture de la page Compte envoyée par l’hôte" className="max-h-[320px] w-full object-contain" />
                </button>
              ) : proofError ? (
                <p className="text-sm font-semibold text-err-ink">{proofError}</p>
              ) : (
                <Loading />
              )
            ) : (
              <p className="text-sm font-medium text-muted">Aucune capture (offre de démonstration).</p>
            )}
            <p className="mt-3 text-[12px] leading-normal font-semibold text-muted">
              Vérifie : le nom du service, la formule ({offer.plan}), un abonnement actif, et qu’il y a bien au moins {offer.seats} place{offer.seats > 1 ? 's' : ''} à partager.
            </p>
          </Panel>

          <Panel title="Hôte" action={<Link to={`/users/${offer.host.id}`} className="text-[13px] font-bold text-brand-ink">Voir la fiche →</Link>}>
            <div className="flex items-center gap-3">
              <Avatar name={offer.host.shortName} size={42} />
              <div className="flex flex-1 flex-col">
                <span className="font-bold">{offer.host.name ?? offer.host.shortName}</span>
                <span className="tabular text-[13px] font-semibold text-muted">{phone(offer.host.phone)}</span>
              </div>
              {offer.host.suspendedAt && <span className="text-[12px] font-extrabold text-err">Compte suspendu</span>}
            </div>
            <div className="mt-3 grid grid-cols-3 gap-2 text-center">
              <Mini label="Inscrit·e" value={ago(offer.host.createdAt).replace('il y a ', '')} />
              <Mini label="Paiements" value={String(offer.host.paidCount)} />
              <Mini label="Retiré·e d’un cercle" value={String(offer.host.removalsCount)} warn={offer.host.removalsCount > 0} />
            </div>
          </Panel>

          <Panel title="Offre">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
              <Def label="Prix par place" value={`${fcfa(offer.price)} FCFA / mois`} />
              <Def label="Places" value={`${offer.members.length} membre(s) sur ${offer.seats}`} />
              <Def label="Accès" value={offer.mode === 'family' ? 'Invitation famille' : 'Identifiants (chiffrés)'} />
              <Def label="Gain hôte / mois" value={`${fcfa(offer.monthlyNet)} FCFA`} />
              <Def label="Appareils" value={offer.devices.map((d) => DEVICE[d] ?? d).join(', ') || '—'} />
              <Def label="En ligne depuis" value={offer.approvedAt ? date(offer.approvedAt) : '—'} />
            </dl>
            {offer.members.length > 0 && (
              <ul className="mt-4 flex flex-wrap gap-2">
                {offer.members.map((m) => (
                  <li key={m.id} className="flex items-center gap-1.5 rounded-full bg-sand py-1 pr-3 pl-1 text-[13px] font-bold">
                    <Avatar name={m.name} size={22} color={m.color} />
                    {m.userId ? <Link to={`/users/${m.userId}`}>{m.name}</Link> : m.name}
                    {m.invitePending && <span className="text-[11px] text-info">invitation</span>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {offer.status === 'review' && (
            <div className="sticky bottom-0 grid grid-cols-[auto_1fr] gap-2 bg-sand pt-2">
              <Button variant="outline" block={false} className="px-5" onClick={() => setConfirm('reject')}>
                Refuser
              </Button>
              <Button
                loading={busy}
                onClick={async () => {
                  setBusy(true)
                  try {
                    done((await api.approve(offer.id)).data, 'Offre validée · elle est en ligne')
                  } catch (e) {
                    toast({ tone: 'error', text: errorText(e) })
                  } finally {
                    setBusy(false)
                  }
                }}
              >
                Valider et mettre en ligne
              </Button>
            </div>
          )}
          {(offer.status === 'live' || offer.status === 'paused') && (
            <Button variant={offer.status === 'live' ? 'outline' : 'ink'} onClick={() => setConfirm('toggle')}>
              {offer.status === 'live' ? 'Suspendre l’offre' : 'Réactiver l’offre'}
            </Button>
          )}
        </div>
      )}

      {zoom && proof && (
        <div className="fixed inset-0 z-50 grid cursor-zoom-out place-items-center bg-ink/80 p-8" onClick={() => setZoom(false)}>
          <img src={proof} alt="Preuve agrandie" className="max-h-full max-w-full rounded-tile" />
        </div>
      )}
      {confirm === 'reject' && offer && (
        <Confirm
          title="Refuser cette offre ?"
          text="L’hôte reçoit une notification avec ton motif. Il pourra publier une nouvelle offre."
          confirm="Refuser"
          danger
          reason={{ label: 'Motif envoyé à l’hôte', required: true, placeholder: 'Ex. : capture illisible, formule Standard et non Premium…' }}
          onCancel={() => setConfirm(null)}
          onConfirm={async (reason) => {
            const { data } = await api.reject(offer.id, reason)
            setConfirm(null)
            done(data, 'Offre refusée · l’hôte est prévenu')
          }}
        />
      )}
      {confirm === 'toggle' && offer && (
        <Confirm
          title={offer.status === 'live' ? 'Suspendre cette offre ?' : 'Réactiver cette offre ?'}
          text={offer.status === 'live' ? 'Ses places disparaissent du catalogue. Les membres actuels gardent leur accès.' : 'Ses places libres réapparaissent dans le catalogue.'}
          confirm={offer.status === 'live' ? 'Suspendre' : 'Réactiver'}
          danger={offer.status === 'live'}
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            const { data } = await api.toggleOffer(offer.id)
            setConfirm(null)
            done(data, data.status === 'paused' ? 'Offre suspendue' : 'Offre réactivée')
          }}
        />
      )}
    </Drawer>
  )
}

const Mini = ({ label, value, warn }: { label: string; value: string; warn?: boolean }) => (
  <div className="rounded-tile bg-sand px-2 py-2">
    <div className={`tabular font-display text-lg font-extrabold ${warn ? 'text-warn-ink' : ''}`}>{value}</div>
    <div className="text-[11px] font-semibold text-muted">{label}</div>
  </div>
)

const Def = ({ label, value }: { label: string; value: string }) => (
  <div className="flex flex-col gap-0.5">
    <dt className="text-[12px] font-semibold text-muted">{label}</dt>
    <dd className="font-bold">{value}</dd>
  </div>
)
