import { BackButton, Screen } from '../components/ui'
import { supportWhatsApp } from '../lib/support'

/**
 * Conditions d'utilisation et confidentialité — version bêta, provisoire.
 * Décrit le fonctionnement réel de l'app ; à faire relire par un juriste avant l'ouverture au public.
 */
const SECTIONS: { title: string; body: string[] }[] = [
  {
    title: 'Ce qu’est Sub.ci',
    body: [
      'Sub.ci met en relation des personnes qui partagent les places libres de leurs abonnements (les hôtes) et des personnes qui veulent une place à prix partagé (les membres).',
      'Sub.ci n’est affilié à aucun des services proposés (Netflix, Spotify, Apple, Google…). Chaque utilisateur reste responsable du respect des conditions du service qu’il partage ou utilise.',
    ],
  },
  {
    title: 'Paiement et frais',
    body: [
      'Le membre paie sa place d’avance, en mobile money ou par carte, via notre prestataire de paiement. Des frais de service Sub.ci sont ajoutés à chaque paiement et affichés avant de payer.',
      'L’hôte reçoit 90 % du prix de son offre ; Sub.ci garde 10 % et les frais de service.',
    ],
  },
  {
    title: 'Acceptation et remboursements',
    body: [
      'Après le paiement, l’hôte a 24 h pour accepter le membre. S’il refuse ou ne répond pas, le membre est remboursé intégralement, frais compris.',
      'L’argent est versé à l’hôte mois par mois, quelques jours après le début de chaque mois payé. Si l’accès ne fonctionne pas, le membre signale un souci depuis l’app : les versements à l’hôte pour ce membre sont suspendus le temps de régler le problème, et le temps non encore versé peut être remboursé.',
      'Les remboursements et retraits sont envoyés sur le compte mobile money indiqué, sous 48 h.',
    ],
  },
  {
    title: 'Engagements de l’hôte',
    body: [
      'L’hôte garde son abonnement actif pendant toute la durée payée par ses membres, et ne modifie pas l’accès sans prévenir.',
      'Une preuve d’abonnement est demandée et vérifiée avant la mise en ligne d’une offre. Sub.ci peut suspendre une offre ou un compte en cas d’abus.',
    ],
  },
  {
    title: 'Tes données',
    body: [
      'Nous gardons ton numéro de téléphone, ton prénom et ton nom, tes paiements, et pour les hôtes les identifiants des comptes partagés. Ces identifiants sont chiffrés et ne sont visibles que par les membres acceptés.',
      'Les autres utilisateurs ne voient que ton prénom et l’initiale de ton nom. Tes données servent uniquement au fonctionnement de Sub.ci ; elles ne sont ni vendues ni partagées à des fins publicitaires.',
      'Tu peux demander la suppression de ton compte et de tes données en nous contactant.',
    ],
  },
]

export function Conditions() {
  const help = supportWhatsApp('Bonjour, j’ai une question sur les conditions de Sub.ci')
  return (
    <Screen>
      <div className="flex items-center gap-3 px-4 pt-1">
        <BackButton />
        <h1 className="font-display text-[22px] font-bold">Conditions</h1>
      </div>
      <div className="flex flex-col gap-6 px-5 pt-4 pb-12">
        <p className="rounded-tile bg-warn-soft px-3.5 py-3 text-[13px] leading-snug font-semibold text-warn-deep">
          Version bêta : ces conditions sont provisoires et peuvent évoluer avant l’ouverture au public. Tu seras prévenu·e de tout changement important.
        </p>
        {SECTIONS.map((s) => (
          <section key={s.title} className="flex flex-col gap-2">
            <h2 className="text-base font-bold">{s.title}</h2>
            {s.body.map((p) => (
              <p key={p} className="text-[15px] leading-relaxed font-medium text-body">
                {p}
              </p>
            ))}
          </section>
        ))}
        {help && (
          <a href={help} target="_blank" rel="noreferrer" className="text-sm font-bold text-brand-ink underline">
            Une question ? Écris-nous sur WhatsApp
          </a>
        )}
      </div>
    </Screen>
  )
}
