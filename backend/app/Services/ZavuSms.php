<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Codes de connexion par SMS via Zavu (POST /v1/messages, canal « sms_oneway »).
 * Actif dès que ZAVU_API_KEY est renseignée ; en local, seulement avec ZAVU_SEND_IN_LOCAL=true
 * (chaque SMS consomme du crédit : en local le code reste dans les logs).
 */
class ZavuSms
{
    public static function enabled(): bool
    {
        return filled(config('services.zavu.key'))
            && (! app()->environment('local', 'testing') || config('services.zavu.send_in_local'));
    }

    /** Envoie le code au numéro ivoirien à 10 chiffres. Renvoie false si Zavu refuse (solde, numéro, clé…). */
    public function send(string $phone, string $code): bool
    {
        $c = config('services.zavu');
        $minutes = (int) ceil(config('services.otp.ttl') / 60);
        $request = Http::withToken($c['key'])->acceptJson()->timeout(10)->retry(2, 300, throw: false);
        if (filled($c['sender'])) {
            $request = $request->withHeaders(['Zavu-Sender' => $c['sender']]);
        }
        // Sans accents ni emoji : un seul SMS de 160 caractères (GSM-7), donc un seul crédit.
        // « Subci » sans point : Zavu prend « Sub.ci » pour un lien et retient le SMS (pending_url_verification).
        $response = $request->post('https://api.zavu.dev/v1/messages', [
            'to' => '+225'.$phone,
            // « sms_oneway » : sans numéro attribué chez Zavu (« sms » l'exige) ; on n'attend pas de réponse.
            'channel' => 'sms_oneway',
            'text' => "Subci : ton code est {$code}. Il expire dans {$minutes} min. Ne le partage avec personne.",
        ]);

        if (! $response->successful()) {
            // Jamais le code ni la clé dans les logs.
            Log::error('Zavu : envoi du SMS refusé', [
                'status' => $response->status(),
                'error' => $response->json('code') ?? $response->json('error.code'),
                'message' => $response->json('message') ?? $response->json('error.message'),
                'phone' => substr($phone, 0, 4).'••••'.substr($phone, -2),
            ]);

            return false;
        }

        return true;
    }
}
