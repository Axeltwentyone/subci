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
