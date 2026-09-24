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

## Paiements

Le driver `PAYMENTS_DRIVER=fake` valide une demande mobile money au bout de
`PAYMENTS_FAKE_DELAY` secondes. Pour un vrai agrégateur (CinetPay, PayDunya…),
implémenter `App\Contracts\PaymentGateway` et l'enregistrer dans `AppServiceProvider`.

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
| `GET host`, `POST host/offers`, `POST host/withdrawals` | Partager & gagner |
| `PATCH host/offers/{id}` | Modifier prix, places, identifiants (poussés dans le coffre des membres) |
| `DELETE host/offers/{id}/members/{member}`, `POST host/offers/{id}/invite` | Membres |
| `POST host/offers/{id}/pause` · `resume` · `close` | Pause, reprise, arrêt du partage |

Tâche planifiée : `subscriptions:sweep` (chaque minute) active les accès prêts et
expire les abonnements échus — lancer `php artisan schedule:work` en local.

## Tests

```bash
cd backend && php artisan test     # SQLite en mémoire, ne touche pas MySQL
```
