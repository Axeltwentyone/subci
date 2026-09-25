<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OtpService
{
    /** Génère et « envoie » un code à 6 chiffres. Renvoie le code (à n'exposer qu'en local). */
    public function send(string $phone): string
    {
        OtpCode::where('phone', $phone)->whereNull('consumed_at')->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(config('services.otp.ttl')),
        ]);

        // TODO passerelle SMS (Orange SMS API, Twilio…). Le format « @sub.ci #code » active WebOTP.
        // Code en clair dans les logs : uniquement en local (en prod, quiconque lit les logs pourrait se connecter).
        if (app()->environment('local', 'testing')) {
            Log::info("OTP Sub.ci pour {$phone} : {$code}");
        }

        return $code;
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)->whereNull('consumed_at')->where('expires_at', '>', now())->latest()->first();
        if (! $otp || $otp->attempts >= config('services.otp.max_attempts')) {
            return false;
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }
        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
