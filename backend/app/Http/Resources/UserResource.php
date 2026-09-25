<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = array_merge(User::DEFAULT_SETTINGS, $this->settings ?? []);

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'phone' => $this->phone,
            'referralCode' => $this->referral_code,
            'balance' => $this->balance,
            'payout' => ['method' => $this->payout_method ?? 'wave', 'phone' => $this->payout_phone ?? $this->phone],
            // Parrainage : code à partager, crédit, filleuls, frais offerts.
            'referral' => app(ReferralService::class)->summary($this->resource),
            'lastMethod' => $this->last_pay_method ?? 'om',
            'settings' => [
                'notifDue' => (bool) $s['notif_due'],
                'notifSeats' => (bool) $s['notif_seats'],
                'notifPromo' => (bool) $s['notif_promo'],
                'biometric' => (bool) $s['biometric'],
                'hideAccess' => $s['hide_access'],
                'dataSaver' => $s['data_saver'],
            ],
        ];
    }
}
