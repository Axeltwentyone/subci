<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Str;

/** Dev / démo : l'opérateur « valide » la demande au bout de quelques secondes. */
class FakeGateway implements PaymentGateway
{
    public function __construct(private int $delay) {}

    public function request(Payment $payment): array
    {
        return ['reference' => 'FAKE-'.Str::upper(Str::random(10)), 'url' => null];
    }

    public function status(Payment $payment): PaymentStatus
    {
        $since = $payment->updated_at ?? $payment->created_at;

        return $since->diffInSeconds(now()) >= $this->delay ? PaymentStatus::Succeeded : PaymentStatus::Pending;
    }
}
