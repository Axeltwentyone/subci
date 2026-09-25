<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parrainage.
 * - Le filleul saisit le code d'un ami avant son premier paiement : ses frais de service sont offerts
 *   jusqu'à ce qu'un hôte l'accepte.
 * - À cette acceptation, le parrain reçoit un crédit Sub.ci (plafonné par mois), déduit de ses prochains
 *   paiements, jamais retirable. Crédit repris si le filleul est remboursé dans les 30 jours.
 */
class ReferralService
{
    /** Le membre peut encore saisir un code : pas de parrain, jamais payé d'abonnement. */
    public function canApply(User $user): bool
    {
        return ! Referral::where('referee_id', $user->id)->exists()
            && ! $user->payments()->where('type', PaymentType::Subscription)->where('status', PaymentStatus::Succeeded)->exists();
    }

    public function apply(User $referee, string $code): Referral
    {
        $referrer = User::where('referral_code', strtoupper(trim($code)))->first();
        if (! $referrer || $referrer->suspended_at) {
            throw ValidationException::withMessages(['code' => 'Code de parrainage inconnu.']);
        }
        if ($referrer->id === $referee->id) {
            throw ValidationException::withMessages(['code' => 'Tu ne peux pas utiliser ton propre code.']);
        }
        if (! $this->canApply($referee)) {
            throw ValidationException::withMessages(['code' => 'Le parrainage n’est possible qu’avant ton premier abonnement.']);
        }
        // Pas de parrainages croisés (A parraine B qui parraine A).
        if (Referral::where('referrer_id', $referee->id)->where('referee_id', $referrer->id)->exists()) {
            throw ValidationException::withMessages(['code' => 'Ce code ne peut pas être utilisé.']);
        }

        return Referral::create(['referrer_id' => $referrer->id, 'referee_id' => $referee->id, 'status' => 'pending']);
    }

    /** Frais de service offerts : parrainage en attente de la 1re acceptation. */
    public function waivesFee(User $user): bool
    {
        return (bool) config('services.referral.waive_fee')
            && Referral::where('referee_id', $user->id)->where('status', 'pending')->exists();
    }

    /** Réserve le crédit du membre pour un paiement (à appeler dans une transaction). */
    public function reserveCredit(User $user, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }
        $locked = User::whereKey($user->id)->lockForUpdate()->first();
        $use = min($locked->referral_credit, $max);
        if ($use > 0) {
            $locked->decrement('referral_credit', $use);
        }

        return $use;
    }

    /** Paiement abandonné, échoué ou remboursé intégralement : le crédit revient au membre (une seule fois). */
    public function restoreCredit(Payment $payment): void
    {
        if ($payment->credit_used <= 0) {
            return;
        }
        DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked->credit_restored_at) {
                return;
            }
            $locked->update(['credit_restored_at' => now()]);
            User::whereKey($locked->user_id)->increment('referral_credit', $locked->credit_used);
        });
    }

    /** Paiement confirmé en retard alors que le crédit avait été rendu : on le reprend. */
    public function reapplyCredit(Payment $payment): void
    {
        if ($payment->credit_used <= 0 || ! $payment->credit_restored_at) {
            return;
        }
        $user = User::whereKey($payment->user_id)->lockForUpdate()->first();
        $user->decrement('referral_credit', min($user->referral_credit, $payment->credit_used));
        $payment->update(['credit_restored_at' => null]);
    }

    /** Le filleul vient d'être accepté par un hôte : récompense du parrain. */
    public function reward(User $referee): void
    {
        $referral = Referral::with('referrer')->where('referee_id', $referee->id)->where('status', 'pending')->lockForUpdate()->first();
        if (! $referral) {
            return;
        }
        $reward = (int) config('services.referral.reward');
        $thisMonth = Referral::where('referrer_id', $referral->referrer_id)->where('status', 'rewarded')
            ->where('rewarded_at', '>=', now()->startOfMonth())->count();
        if ($thisMonth >= (int) config('services.referral.monthly_cap') || $referral->referrer->suspended_at) {
            $referral->update(['status' => 'capped']);

            return;
        }

        $referral->update(['status' => 'rewarded', 'reward' => $reward, 'rewarded_at' => now()]);
        User::whereKey($referral->referrer_id)->increment('referral_credit', $reward);
        $referral->referrer->notify(new AppNotification('ok', '+'.EarningService::fcfa($reward).' de crédit',
            $referee->shortName().' a rejoint Sub.ci grâce à toi. Ton crédit est déduit de ton prochain paiement.',
            ['label' => 'Voir', 'to' => '/profile']));
    }

    /** Filleul remboursé peu après : la récompense est reprise (jusqu'à zéro). */
    public function cancelIfRefunded(User $referee): void
    {
        $referral = Referral::where('referee_id', $referee->id)->where('status', 'rewarded')
            ->where('rewarded_at', '>=', now()->subDays(30))->lockForUpdate()->first();
        if (! $referral) {
            return;
        }
        $referral->update(['status' => 'cancelled']);
        $referrer = User::whereKey($referral->referrer_id)->lockForUpdate()->first();
        $referrer->decrement('referral_credit', min($referrer->referral_credit, $referral->reward));
    }

    /** Résumé pour l'app : code, crédit, filleuls. */
    public function summary(User $user): array
    {
        $mine = Referral::where('referrer_id', $user->id)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $as = Referral::with('referrer')->where('referee_id', $user->id)->first();

        return [
            'code' => $user->referral_code,
            'credit' => (int) $user->referral_credit,
            'reward' => (int) config('services.referral.reward'),
            'friends' => (int) ($mine['rewarded'] ?? 0),
            'pending' => (int) ($mine['pending'] ?? 0),
            'feeWaived' => $this->waivesFee($user),
            'referredBy' => $as?->referrer?->shortName(),
            'canApply' => ! $as && $this->canApply($user),
        ];
    }
}
