<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Offres créées avant les formules : on retrouve la formule par son libellé, tous appareils cochés. */
    public function up(): void
    {
        DB::table('host_offers')->whereNull('plan')->orderBy('id')->each(function ($offer) {
            $slug = DB::table('services')->where('id', $offer->service_id)->value('slug');
            foreach (config("plans.{$slug}", []) as $key => $plan) {
                if ($plan['label'] === $offer->plan_label) {
                    DB::table('host_offers')->where('id', $offer->id)->update([
                        'plan' => $key,
                        'devices' => json_encode($plan['devices']),
                        'quality' => $plan['quality'],
                    ]);

                    return;
                }
            }
        });
    }

    public function down(): void
    {
        // Données complétées : rien à annuler.
    }
};
