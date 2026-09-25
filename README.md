# Sub.ci

PWA mobile (React + Tailwind v4) et API Laravel 13 / MySQL.

```
.
├── src/        PWA (Vite, React Router, vite-plugin-pwa)
└── backend/    API Laravel (Sanctum, MySQL `subci_pwa`)
```

## Lancer en local

Prérequis : Node 20+, PHP 8.3+, Composer, MySQL sur `127.0.0.1:3306`.

```bash
# API
cd backend
composer install
cp .env.example .env && php artisan key:generate   # 1re fois seulement
php artisan migrate --seed                         # catalogue + compte démo
php artisan serve --port=8000

# PWA (autre terminal, à la racine)
npm install
npm run dev
```

Vite proxifie `/api` vers `http://127.0.0.1:8000` : aucune config CORS en dev.

**Compte démo** : `07 58 42 11 21`. En local, le code SMS est renvoyé par l'API et
affiché dans un toast (`OTP_EXPOSE_CODE=true`) ; il est aussi écrit dans `backend/storage/logs/laravel.log`.

Remettre la démo à zéro : `php artisan migrate:fresh --seed`.

## Paiements (GeniusPay)

`PAYMENTS_DRIVER=geniuspay` dans `backend/.env` (clés du tableau de bord GeniusPay :
`GENIUSPAY_API_KEY`, `GENIUSPAY_API_SECRET`, `GENIUSPAY_WEBHOOK_SECRET`). `PAYMENTS_DRIVER=fake`
valide automatiquement au bout de `PAYMENTS_FAKE_DELAY` secondes (démo sans réseau).

Parcours : checkout → redirection vers la page GeniusPay → retour sur `FRONTEND_URL/pay/{référence}`
→ l'app relit le statut. En production, déclarer le webhook
`https://<api>/api/v1/webhooks/geniuspay` dans GeniusPay : la signature HMAC est vérifiée et le
statut toujours relu via l'API avant confirmation.

GeniusPay n'a pas d'API de remboursement ni de versement vers un tiers : remboursements
(demandes refusées / expirées / annulées) et retraits des hôtes sont **à verser à la main** :

```bash
php artisan payouts:list                 # ce qu'il reste à verser (numéro, moyen, montant)
php artisan payouts:done SUB-XXXX-00     # après envoi : marque versé et notifie la personne
```

Sandbox : chaque paiement créé consomme un jeton de test (`tokens_remaining`). Les tests
automatisés n'appellent jamais GeniusPay (`PAYMENTS_DRIVER=fake` forcé dans `phpunit.xml`).

## API (`/api/v1`)

| | |
|---|---|
| `POST auth/otp`, `POST auth/verify`, `POST auth/logout` | Connexion par numéro + code SMS (jeton Sanctum) |
| `GET bootstrap` | Tout l'état de l'app en un appel (démarrage, pull-to-refresh) |
| `GET services`, `GET services/{slug}` | Catalogue |
| `GET/PATCH me` | Profil (prénom + nom, demandés après le code SMS), réglages |
| `GET subscriptions`, `PATCH subscriptions/{id}`, `POST …/cancel` | Abonnements (accès chiffrés en base) |
| `POST payments`, `GET payments/{ref}`, `POST …/resend`, `POST …/cancel` | Checkout et suivi du paiement |
| `GET notifications`, `POST …/read`, `…/read-all`, `…/archive`, `…/restore` | Onglet Activité |
| `GET push/key`, `POST/DELETE push/subscriptions` | Abonnement de l'appareil aux notifications push |
| `GET host`, `POST host/offers`, `POST host/withdrawals` | Partager & gagner |
| `PATCH host/offers/{id}` | Modifier prix, places, identifiants (poussés dans le coffre des membres) |
| `DELETE host/offers/{id}/members/{member}`, `POST host/offers/{id}/invite` | Membres |
| `POST host/offers/{id}/pause` · `resume` · `close` | Pause, reprise, arrêt du partage |

## Tâches de fond

En local, dans deux terminaux de plus :

```bash
cd backend && php artisan queue:work      # envoi des notifications push
cd backend && php artisan schedule:work   # tâches planifiées ci-dessous
```

| Tâche | Fréquence | Rôle |
|---|---|---|
| `subscriptions:sweep` | chaque minute | Active les accès prêts, expire les abonnements échus, met en ligne les offres validées |
| `subscriptions:remind` | toutes les heures, 8 h – 20 h | Rappels d'échéance J-3 et J-1 (push + onglet Activité), une fois chacun |
| `offers:approve {id}` | à la main | Valide la preuve d'une offre hôte (modération) |

## Notifications push

Web Push standard (VAPID), sans Firebase côté code. Les clés sont dans `backend/.env`
(`php artisan push:vapid` pour en générer). Le service worker n'existe qu'en build :
tester avec `npm run build && npm run preview`, pas `npm run dev`.

Un push est envoyé pour chaque notification de l'onglet Activité si l'appareil est abonné
et que le réglage correspondant est actif (Échéances & paiements, Places libérées, Bons plans).

## Tests

```bash
cd backend && php artisan test     # SQLite en mémoire, ne touche pas MySQL
```

## Administration

Tableau de bord séparé de la PWA (`admin/`), servi sur http://localhost:5174.

```bash
cd backend && php artisan admin:create toi@exemple.ci   # demande le mot de passe (12 caractères min.)
npm run admin:dev                                        # dev
npm run admin:build                                      # build dans dist-admin/
```

Sur téléphone : ouvre l'admin, puis « Ajouter à l'écran d'accueil » (icône orange). Dans **Plus → Notifications**, active les alertes sur l'appareil : paiement reçu, offre à valider, versement à faire, nouvelles inscriptions (au choix). Sur iPhone, les notifications ne marchent que depuis l'app installée, en HTTPS. Les alertes partent via la file d'attente : `php artisan queue:work` doit tourner (comme pour les notifications des membres).

Offres à valider (preuve d'abonnement), versements à faire à la main (remboursements et retraits : GeniusPay n'a pas d'API de virement), demandes en attente, paiements, utilisateurs (suspension), catalogue (prix, visibilité) et journal de toutes les actions. Session de 12 h, rangée dans l'onglet uniquement.

## Déploiement (Vercel + Render)

- **API** : Render, image Docker (`backend/Dockerfile`) décrite dans `render.yaml` avec sa base Postgres. Nginx, PHP-FPM, la file d'attente et le planificateur tournent dans le même conteneur. Au démarrage : migrations puis catalogue des services (jamais les données de démo).
- **PWA** et **admin** : deux projets Vercel sur ce dépôt, qui partagent `vercel.json` (réécriture SPA, en-têtes de sécurité).
- **Preuves d'abonnement** : Cloudflare R2 (bucket privé, compatible S3).

| Projet Vercel | Commande de build | Dossier de sortie | Variable |
|---|---|---|---|
| PWA | `npm run build` | `dist` | `VITE_API_URL=https://<api>.onrender.com/api/v1` |
| Admin | `npm run admin:build` | `dist-admin` | `VITE_API_URL=https://<api>.onrender.com/api/v1` |

Après le premier déploiement de l'API (Render → Shell) :

```bash
php artisan admin:create ton@email.ci
```

Webhook GeniusPay à déclarer : `https://<api>.onrender.com/api/v1/webhooks/geniuspay`.
