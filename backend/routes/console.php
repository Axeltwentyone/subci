<?php

use App\Enums\OfferStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Admin;
use App\Models\HostOffer;
use App\Models\Payment;
use App\Notifications\AppNotification;
use App\Services\EarningService;
use App\Services\ExpiryReminder;
use App\Services\JoinService;
use App\Services\PaymentService;
use App\Services\SubscriptionSweeper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Modération : met une offre en ligne après vérification de la preuve.
Artisan::command('offers:approve {offer : ID de l’offre}', function (int $offer) {
    $model = HostOffer::with('service', 'user')->findOrFail($offer);
    if ($model->status !== OfferStatus::Review) {
        return $this->warn("Offre #{$offer} déjà traitée ({$model->status->value}).");
    }
    SubscriptionSweeper::approve($model);
    $this->info("Offre #{$offer} ({$model->service->name}) en ligne.");
})->purpose('Valider la preuve d’une offre hôte');

// Active les accès prêts, expire les abonnements échus, valide les offres (auto en dev).
Schedule::call(function () {
    $sweeper = app(SubscriptionSweeper::class);
    $sweeper->run();
    $sweeper->approveOffers();
    // Demandes sans réponse de l'hôte sous 24 h → remboursées.
    app(JoinService::class)->expireOverdue();
    // Paiements en attente : on relit la passerelle (membre jamais revenu, webhook manqué).
    app(PaymentService::class)->reconcile();
    // Séquestre : gains d'hôte arrivés à échéance → solde retirable.
    app(EarningService::class)->release();
})
    ->everyMinute()
    ->name('subscriptions:sweep')
    ->withoutOverlapping();

// Rappels d'échéance J-3 / J-1 (push + onglet Activité).
Artisan::command('subscriptions:remind', function () {
    $this->info(app(ExpiryReminder::class)->run().' rappel(s) envoyé(s).');
})->purpose('Envoyer les rappels d’échéance');

Schedule::command('subscriptions:remind')
    ->hourly()
    ->between('8:00', '20:00') // pas de notification la nuit (heure d'Abidjan = UTC)
    ->withoutOverlapping();

// Clés VAPID pour le Web Push : à mettre dans .env (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY).
Artisan::command('push:vapid', function () {
    $keys = VAPID::createVapidKeys();
    $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
})->purpose('Générer une paire de clés VAPID');

// Remboursements et retraits à verser à la main (passerelle sans API de versement).
Artisan::command('payouts:list', function () {
    $rows = Payment::with('user')
        ->where('status', PaymentStatus::Pending)
        ->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal])
        ->oldest()->get();
    if ($rows->isEmpty()) {
        return $this->info('Rien à verser.');
    }
    $this->table(['Référence', 'Type', 'Bénéficiaire', 'Numéro', 'Moyen', 'Montant', 'Depuis'], $rows->map(fn ($p) => [
        $p->reference, $p->type === PaymentType::Refund ? 'Remboursement' : 'Retrait hôte',
        $p->user->name, $p->phone, $p->method->label(), number_format($p->amount, 0, ',', ' ').' FCFA', $p->created_at->diffForHumans(),
    ]));
})->purpose('Lister les remboursements et retraits à verser');

Artisan::command('payouts:done {reference}', function (string $reference) {
    $p = Payment::with('user')->where('reference', $reference)->where('status', PaymentStatus::Pending)
        ->whereIn('type', [PaymentType::Refund, PaymentType::Withdrawal])->first();
    if (! $p) {
        return $this->error("Aucun versement en attente avec la référence {$reference}.");
    }
    // Mise à jour conditionnelle : jamais marqué deux fois (admin web + console).
    if (! Payment::whereKey($p->id)->where('status', PaymentStatus::Pending)->update(['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()])) {
        return $this->error("{$reference} vient déjà d’être traité.");
    }
    $amount = number_format($p->amount, 0, ',', ' ').' FCFA';
    $p->user->notify($p->type === PaymentType::Refund
        ? new AppNotification('pay', 'Remboursement reçu', "{$amount} renvoyés sur ton ".$p->method->label().'.')
        : new AppNotification('host', 'Retrait envoyé', "{$amount} versés sur ton ".$p->method->label().'.'));
    $this->info("{$reference} marqué comme versé ({$amount} à {$p->user->name}).");
})->purpose('Marquer un remboursement ou retrait comme versé');

Artisan::command('payments:reconcile', function () {
    $this->info(app(PaymentService::class)->reconcile().' paiement(s) en attente relu(s).');
})->purpose('Relire les paiements en attente chez la passerelle');

Artisan::command('earnings:release', function () {
    $this->info(app(EarningService::class)->release().' gain(s) versé(s) au solde des hôtes.');
})->purpose('Verser au solde les gains d’hôte sortis du séquestre');

// Téléphone perdu : désactive la double authentification (à reconfigurer à la prochaine connexion).
Artisan::command('admin:2fa-reset {email}', function (string $email) {
    $admin = Admin::where('email', strtolower($email))->first();
    if (! $admin) {
        return $this->error("Aucun compte admin {$email}.");
    }
    if (! $this->confirm("Désactiver la double authentification de {$admin->email} et fermer ses sessions ?")) {
        return;
    }
    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    $admin->tokens()->delete();
    $admin->log('2fa.reset');
    $this->info("Double authentification réinitialisée pour {$admin->email}.");
})->purpose('Réinitialiser la double authentification d’un admin');

// Créer (ou réinitialiser) un compte d'administration.
Artisan::command('admin:create {email} {--name=}', function (string $email) {
    $password = $this->secret('Mot de passe (12 caractères minimum)');
    if (strlen((string) $password) < 12) {
        return $this->error('Mot de passe trop court (12 caractères minimum).');
    }
    $admin = Admin::updateOrCreate(['email' => strtolower($email)], [
        'name' => $this->option('name') ?: Str::before($email, '@'),
        'password' => $password,
    ]);
    $admin->tokens()->delete();
    $this->info("Compte admin prêt : {$admin->email}");
})->purpose('Créer un compte d’administration');
