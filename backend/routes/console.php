<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Modération : met une offre en ligne après vérification de la preuve.
Illuminate\Support\Facades\Artisan::command('offers:approve {offer : ID de l’offre}', function (int $offer) {
    $model = App\Models\HostOffer::with('service', 'user')->findOrFail($offer);
    if ($model->status !== App\Enums\OfferStatus::Review) {
        return $this->warn("Offre #{$offer} déjà traitée ({$model->status->value}).");
    }
    App\Services\SubscriptionSweeper::approve($model);
    $this->info("Offre #{$offer} ({$model->service->name}) en ligne.");
})->purpose('Valider la preuve d’une offre hôte');

// Active les accès prêts, expire les abonnements échus, valide les offres (auto en dev).
Illuminate\Support\Facades\Schedule::call(function () {
    $sweeper = app(App\Services\SubscriptionSweeper::class);
    $sweeper->run();
    $sweeper->approveOffers();
    // Demandes sans réponse de l'hôte sous 24 h → remboursées.
    app(App\Services\JoinService::class)->expireOverdue();
    // Paiements en attente : on relit la passerelle (membre jamais revenu, webhook manqué).
    app(App\Services\PaymentService::class)->reconcile();
})
    ->everyMinute()
    ->name('subscriptions:sweep')
    ->withoutOverlapping();

// Rappels d'échéance J-3 / J-1 (push + onglet Activité).
Illuminate\Support\Facades\Artisan::command('subscriptions:remind', function () {
    $this->info(app(App\Services\ExpiryReminder::class)->run().' rappel(s) envoyé(s).');
})->purpose('Envoyer les rappels d’échéance');

Illuminate\Support\Facades\Schedule::command('subscriptions:remind')
    ->hourly()
    ->between('8:00', '20:00') // pas de notification la nuit (heure d'Abidjan = UTC)
    ->withoutOverlapping();

// Clés VAPID pour le Web Push : à mettre dans .env (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY).
Illuminate\Support\Facades\Artisan::command('push:vapid', function () {
    $keys = Minishlink\WebPush\VAPID::createVapidKeys();
    $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
})->purpose('Générer une paire de clés VAPID');

// Remboursements et retraits à verser à la main (passerelle sans API de versement).
Illuminate\Support\Facades\Artisan::command('payouts:list', function () {
    $rows = App\Models\Payment::with('user')
        ->where('status', App\Enums\PaymentStatus::Pending)
        ->whereIn('type', [App\Enums\PaymentType::Refund, App\Enums\PaymentType::Withdrawal])
        ->oldest()->get();
    if ($rows->isEmpty()) {
        return $this->info('Rien à verser.');
    }
    $this->table(['Référence', 'Type', 'Bénéficiaire', 'Numéro', 'Moyen', 'Montant', 'Depuis'], $rows->map(fn ($p) => [
        $p->reference, $p->type === App\Enums\PaymentType::Refund ? 'Remboursement' : 'Retrait hôte',
        $p->user->name, $p->phone, $p->method->label(), number_format($p->amount, 0, ',', ' ').' FCFA', $p->created_at->diffForHumans(),
    ]));
})->purpose('Lister les remboursements et retraits à verser');

Illuminate\Support\Facades\Artisan::command('payouts:done {reference}', function (string $reference) {
    $p = App\Models\Payment::with('user')->where('reference', $reference)->where('status', App\Enums\PaymentStatus::Pending)
        ->whereIn('type', [App\Enums\PaymentType::Refund, App\Enums\PaymentType::Withdrawal])->first();
    if (! $p) {
        return $this->error("Aucun versement en attente avec la référence {$reference}.");
    }
    $p->update(['status' => App\Enums\PaymentStatus::Succeeded, 'confirmed_at' => now()]);
    $amount = number_format($p->amount, 0, ',', ' ').' FCFA';
    $p->user->notify($p->type === App\Enums\PaymentType::Refund
        ? new App\Notifications\AppNotification('pay', 'Remboursement reçu', "{$amount} renvoyés sur ton ".$p->method->label().'.')
        : new App\Notifications\AppNotification('host', 'Retrait envoyé', "{$amount} versés sur ton ".$p->method->label().'.'));
    $this->info("{$reference} marqué comme versé ({$amount} à {$p->user->name}).");
})->purpose('Marquer un remboursement ou retrait comme versé');

Illuminate\Support\Facades\Artisan::command('payments:reconcile', function () {
    $this->info(app(App\Services\PaymentService::class)->reconcile().' paiement(s) en attente relu(s).');
})->purpose('Relire les paiements en attente chez la passerelle');

// Créer (ou réinitialiser) un compte d'administration.
Illuminate\Support\Facades\Artisan::command('admin:create {email} {--name=}', function (string $email) {
    $password = $this->secret('Mot de passe (12 caractères minimum)');
    if (strlen((string) $password) < 12) {
        return $this->error('Mot de passe trop court (12 caractères minimum).');
    }
    $admin = App\Models\Admin::updateOrCreate(['email' => strtolower($email)], [
        'name' => $this->option('name') ?: Illuminate\Support\Str::before($email, '@'),
        'password' => $password,
    ]);
    $admin->tokens()->delete();
    $this->info("Compte admin prêt : {$admin->email}");
})->purpose('Créer un compte d’administration');
