<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Remboursement d'une demande refusée, expirée ou annulée : le membre choisit
 * - crédit Sub.ci : immédiat, déduit de ses prochains paiements (pas de frais d'envoi pour Sub.ci) ;
 * - argent : renvoyé sur son mobile money, sous 48 h (versement manuel).
 * Sans choix après quelques jours : argent.
 */
class RefundService
{
    public function choose(Payment $refund, string $choice): Payment
    {
        return DB::transaction(function () use ($refund, $choice) {
            $refund = Payment::with('user')->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->type !== PaymentType::Refund || $refund->refund_choice !== 'pending') {
                throw new ConflictHttpException('Ce remboursement a déjà été traité.');
            }

            return $choice === 'credit' ? $this->toCredit($refund) : $this->toCash($refund);
        });
    }

    /** Remboursements sans choix depuis N jours : argent. */
    public function defaultOverdue(): int
    {
        $days = (int) config('services.payments.refund_choice_days');

        return Payment::where('type', PaymentType::Refund)->where('refund_choice', 'pending')
            ->where('created_at', '<=', now()->subDays($days))->get()
            ->each(fn (Payment $p) => DB::transaction(function () use ($p) {
                $locked = Payment::with('user')->whereKey($p->id)->lockForUpdate()->first();
                if ($locked->refund_choice === 'pending') {
                    $this->toCash($locked);
                }
            }))
            ->count();
    }

    private function toCredit(Payment $refund): Payment
    {
        User::whereKey($refund->user_id)->increment('referral_credit', $refund->amount);
        $refund->update(['refund_choice' => 'credit', 'status' => PaymentStatus::Succeeded, 'confirmed_at' => now(), 'label' => 'Crédit Sub.ci · '.$refund->label]);
        $refund->user->notify(new AppNotification('pay', '+'.EarningService::fcfa($refund->amount).' de crédit Sub.ci',
            'Déjà disponible : il est déduit de ton prochain paiement.', ['label' => 'Choisir une offre', 'to' => '/explore']));

        return $refund;
    }

    private function toCash(Payment $refund): Payment
    {
        $manual = (bool) config('services.payments.manual_payouts');
        $refund->update(['refund_choice' => 'cash'] + ($manual ? [] : ['status' => PaymentStatus::Succeeded, 'confirmed_at' => now()]));
        $amount = EarningService::fcfa($refund->amount);
        if ($manual) {
            AdminAlerts::send('payouts', "Remboursement à verser · {$amount}",
                $refund->user->shortName().' · '.$refund->method->label().' '.$refund->phone, '/payouts', 'payouts');
        }
        $refund->user->notify(new AppNotification('pay', 'Remboursement demandé',
            "{$amount} renvoyés sur ton ".$refund->method->label().($manual ? ', sous 48 h.' : '.')));

        return $refund;
    }
}
