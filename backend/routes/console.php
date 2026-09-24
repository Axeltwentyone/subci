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
