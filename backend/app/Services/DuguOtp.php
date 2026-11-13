<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Codes de connexion par WhatsApp via DUGU Sender (numéro officiel partagé, modèle Meta « Authentication »).
 * DUGU génère et vérifie le code : on ne garde que son identifiant.
 * Actif dès que DUGU_API_KEY est renseignée ; en local, seulement avec DUGU_SEND_IN_LOCAL=true.
 */
class DuguOtp
{
    public static function enabled(): bool
    {
        $key = (string) config('services.dugu.key');
        if (app()->environment('local', 'testing')) {
            return $key !== '' && (bool) config('services.dugu.send_in_local');
        }

        // Une clé sandbox n'envoie rien : en production, personne ne recevrait son code.
        return $key !== '' && ! str_starts_with($key, 'dsk_test_');
    }

    /** Demande l'envoi au numéro ivoirien à 10 chiffres. Renvoie null si DUGU refuse (solde, tarif, réseau…). */
    public function request(string $phone): ?array
    {
        try {
            $response = $this->http()->post('otp/request', [
                'subject' => '+225'.$phone,
                'purpose' => 'otp',
                'purpose_type' => 'verification_phone',
                'channel' => 'whatsapp',
                'whatsapp_mode' => 'shared',
            ]);
        } catch (ConnectionException) {
            Log::error('DUGU : API injoignable (envoi du code)');

            return null;
        }

        if (! $response->successful() || ! filled($response->json('data.otp_id'))) {
            // Jamais le code ni la clé dans les logs.
            Log::error('DUGU : envoi du code refusé', [
                'status' => $response->status(),
                'error' => $response->json('error.code'),
                'phone' => substr($phone, 0, 4).'••••'.substr($phone, -2),
            ]);

            return null;
        }

        // test_code : présent uniquement avec une clé sandbox.
        return ['id' => (string) $response->json('data.otp_id'), 'testCode' => $response->json('data.sandbox.test_code')];
    }

    /** Vérifie le code. false si DUGU le refuse ; 503 si DUGU ne peut pas répondre (on ne compte pas d'échec à l'utilisateur). */
    public function verify(string $otpId, string $code): bool
    {
        try {
            $response = $this->http()->post('otp/verify', ['otp_id' => $otpId, 'code' => $code]);
        } catch (ConnectionException) {
            Log::error('DUGU : API injoignable (vérification du code)');
            throw new HttpException(503, 'Impossible de vérifier le code pour l’instant. Réessaie dans un instant.');
        }

        if (in_array($response->status(), [404, 409, 410, 422, 429], true)) {
            return false;
        }
        if (! $response->successful()) {
            Log::error('DUGU : vérification du code impossible', ['status' => $response->status(), 'error' => $response->json('error.code')]);
            throw new HttpException(503, 'Impossible de vérifier le code pour l’instant. Réessaie dans un instant.');
        }

        return $response->json('data.status') === 'verified';
    }

    private function http(): PendingRequest
    {
        return Http::withToken(config('services.dugu.key'))->acceptJson()->asJson()->timeout(30)
            ->baseUrl('https://dugusender.com/api/v1/');
    }
}
