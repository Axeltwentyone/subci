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
