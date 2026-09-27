<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Codes de connexion par WhatsApp (API Cloud de Meta, modèle « Authentification » avec bouton « Copier le code »).
 * Actif dès que WHATSAPP_TOKEN et WHATSAPP_PHONE_NUMBER_ID sont renseignés.
 */
class WhatsAppOtp
{
    public static function enabled(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /** Envoie le code au numéro ivoirien à 10 chiffres. Renvoie false si Meta refuse (numéro sans WhatsApp, modèle, jeton…). */
    public function send(string $phone, string $code): bool
    {
        $c = config('services.whatsapp');
        $response = Http::withToken($c['token'])->acceptJson()->timeout(10)->retry(2, 300, throw: false)
            ->post("https://graph.facebook.com/{$c['version']}/{$c['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => '225'.$phone,
                'type' => 'template',
                'template' => [
                    'name' => $c['otp_template'],
                    'language' => ['code' => $c['language']],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        // Bouton « Copier le code » du modèle d'authentification.
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            // Jamais le code ni le jeton dans les logs.
            Log::error('WhatsApp : envoi du code refusé', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
                'code' => $response->json('error.code'),
                'phone' => substr($phone, 0, 4).'••••'.substr($phone, -2),
            ]);

            return false;
        }

        return true;
    }
}
