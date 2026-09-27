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

    /** Modèle de code (nom configuré) sur le compte WhatsApp : statut par langue, ou erreur Meta. */
    public function templateStatus(): array
    {
        $c = config('services.whatsapp');
        $response = $this->graph()->get("{$c['waba_id']}/message_templates", ['name' => $c['otp_template'], 'fields' => 'name,language,status,category,rejected_reason']);
        if (! $response->successful()) {
            return ['error' => $response->json('error.message'), 'code' => $response->json('error.code')];
        }

        return ['templates' => collect($response->json('data', []))->where('name', $c['otp_template'])->values()->all()];
    }

    /** Statut du compte WhatsApp chez Meta (examen du compte, vérification de l'entreprise). */
    public function accountStatus(): array
    {
        $response = $this->graph()->get(config('services.whatsapp.waba_id'), ['fields' => 'name,account_review_status,business_verification_status,currency,timezone_id']);

        return $response->successful()
            ? $response->json()
            : ['error' => $response->json('error.message'), 'code' => $response->json('error.code')];
    }

    /** Crée le modèle « Authentification » avec bouton « Copier le code » (10 min). */
    public function createTemplate(): array
    {
        $c = config('services.whatsapp');
        $response = $this->graph()->post("{$c['waba_id']}/message_templates", [
            'name' => $c['otp_template'],
            'language' => $c['language'],
            'category' => 'AUTHENTICATION',
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copier le code']]],
            ],
        ]);
        if (! $response->successful()) {
            Log::warning('WhatsApp : création du modèle refusée', ['error' => $response->json('error.message'), 'code' => $response->json('error.code'), 'subcode' => $response->json('error.error_subcode')]);

            return ['ok' => false, 'error' => $response->json('error.error_user_msg') ?? $response->json('error.message'), 'code' => $response->json('error.code'), 'subcode' => $response->json('error.error_subcode')];
        }

        return ['ok' => true, 'status' => $response->json('status'), 'id' => $response->json('id')];
    }

    private function graph(): \Illuminate\Http\Client\PendingRequest
    {
        $c = config('services.whatsapp');

        return Http::withToken($c['token'])->acceptJson()->timeout(15)->baseUrl("https://graph.facebook.com/{$c['version']}/");
    }
}
