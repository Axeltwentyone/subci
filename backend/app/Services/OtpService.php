<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Codes SMS à 6 chiffres.
 *
 * Contre la devinette : 5 essais par code, et par numéro au plus
 * `per_hour` / `per_day` codes envoyés et `max_failures` échecs par jour.
 */
class OtpService
{
    /** Génère et « envoie » un code. Renvoie le code (à n'exposer qu'en local). */
    public function send(string $phone, string $purpose = 'login'): string
    {
        foreach (['hour' => 3600, 'day' => 86400] as $window => $seconds) {
            $key = "otp-send-{$window}:{$phone}";
            if (RateLimiter::tooManyAttempts($key, (int) config("services.otp.per_{$window}"))) {
                throw new ThrottleRequestsException($window === 'hour'
                    ? 'Trop de codes demandés pour ce numéro. Réessaie dans une heure.'
                    : 'Trop de codes demandés pour ce numéro aujourd’hui. Réessaie demain.');
            }
        }
        $this->ensureNotLocked($phone);
        RateLimiter::hit("otp-send-hour:{$phone}", 3600);
        RateLimiter::hit("otp-send-day:{$phone}", 86400);

        OtpCode::where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->delete();

        // Numéro de test déclaré sur le serveur, ou code commun de la bêta : code fixe, aucun SMS.
        $test = config('services.otp.test_codes')[$phone] ?? self::betaCode();
        $code = $test ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if ($test) {
            Log::notice('OTP : numéro de test utilisé', ['phone' => substr($phone, 0, 4).'••••'.substr($phone, -2)]);
        }
        OtpCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(config('services.otp.ttl')),
        ]);

        // TODO passerelle SMS (Orange SMS API, Twilio…). Le format « @sub.ci #code » active WebOTP.
        // Code en clair dans les logs : uniquement en local (en prod, quiconque lit les logs pourrait se connecter).
        if (app()->environment('local', 'testing')) {
            Log::info("OTP Sub.ci ({$purpose}) pour {$phone} : {$code}");
        }

        return $code;
    }

    public function verify(string $phone, string $code, string $purpose = 'login'): bool
    {
        $this->ensureNotLocked($phone);

        $otp = OtpCode::where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')
            ->where('expires_at', '>', now())->latest()->first();
        if (! $otp || $otp->attempts >= config('services.otp.max_attempts')) {
            return false;
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            RateLimiter::hit("otp-fail:{$phone}", 86400);

            return false;
        }
        $otp->update(['consumed_at' => now()]);

        return true;
    }

    /** Code commun de la bêta, seulement avant la date de fin (OTP_BETA_UNTIL, obligatoire). */
    public static function betaCode(): ?string
    {
        $code = config('services.otp.beta_code');
        $until = config('services.otp.beta_until');
        if (! $code || ! $until) {
            return null;
        }
        try {
            return now()->lte(Carbon::parse($until)->endOfDay()) ? $code : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Trop d'échecs sur 24 h pour ce numéro : plus aucun code accepté (ni envoyé) jusqu'à demain. */
    private function ensureNotLocked(string $phone): void
    {
        if (RateLimiter::tooManyAttempts("otp-fail:{$phone}", (int) config('services.otp.max_failures'))) {
            throw new ThrottleRequestsException('Trop de codes erronés pour ce numéro. Par sécurité, réessaie dans '
                .ceil(RateLimiter::availableIn("otp-fail:{$phone}") / 3600).' h.');
        }
    }
}
