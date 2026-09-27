<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Codes de connexion WhatsApp : état de la configuration et création du modèle depuis l'admin. */
class WhatsAppController extends Controller
{
    public function show(WhatsAppOtp $whatsapp): JsonResponse
    {
        $configured = WhatsAppOtp::enabled();
        $waba = filled(config('services.whatsapp.waba_id'));

        return response()->json([
            'configured' => $configured,
            'wabaConfigured' => $waba,
            'template' => config('services.whatsapp.otp_template'),
            'language' => config('services.whatsapp.language'),
            'status' => $configured && $waba ? $whatsapp->templateStatus() : null,
            'account' => $configured && $waba ? $whatsapp->accountStatus() : null,
        ]);
    }

    public function createTemplate(Request $request, WhatsAppOtp $whatsapp): JsonResponse
    {
        abort_unless(WhatsAppOtp::enabled() && filled(config('services.whatsapp.waba_id')), 409, 'Renseigne WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID et WHATSAPP_WABA_ID dans Render.');
        $result = $whatsapp->createTemplate();
        $request->user()->log('whatsapp.template', null, ['ok' => $result['ok'], 'code' => $result['code'] ?? null]);

        // « message » : affiché tel quel dans l'admin (raison donnée par Meta).
        return response()->json($result + ($result['ok'] ? [] : ['message' => 'Meta refuse : '.($result['error'] ?? 'erreur inconnue').' (#'.($result['code'] ?? '?').($result['subcode'] ? '/'.$result['subcode'] : '').')']), $result['ok'] ? 200 : 422);
    }
}
