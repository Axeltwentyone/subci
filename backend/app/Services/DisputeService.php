<?php

namespace App\Services;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Models\Admin;
use App\Models\Dispute;
use App\Models\Subscription;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * « Un souci ? » : le membre signale un problème d'accès.
 * Les gains de l'hôte pour ce membre sont gelés jusqu'à ce que le membre dise
 * que c'est réglé, ou que l'équipe tranche (remboursement du temps non versé, ou rejet).
 */
class DisputeService
{
    public function __construct(private EarningService $earnings) {}

    public function open(Subscription $sub, DisputeReason $reason, ?string $message): Dispute
    {
        return DB::transaction(function () use ($sub, $reason, $message) {
            $sub = Subscription::with('service', 'hostOffer.user', 'user')->lockForUpdate()->findOrFail($sub->id);
            if ($sub->disputes()->where('status', DisputeStatus::Open)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Tu as déjà signalé un souci pour cet abonnement. L’équipe s’en occupe.']);
            }
            if (! $sub->hostOffer && ! $sub->ends_at->isFuture()) {
                throw ValidationException::withMessages(['reason' => 'Cet abonnement est terminé.']);
            }

            $dispute = $sub->disputes()->create([
                'user_id' => $sub->user_id,
                'host_offer_id' => $sub->host_offer_id,
                'reason' => $reason,
                'message' => $message,
                'status' => DisputeStatus::Open,
            ]);
            $this->earnings->hold($sub, true);

            $short = Str::before($sub->service->name, ' ');
            $member = $sub->user->shortName();
            $sub->hostOffer?->user->notify(new AppNotification('host', "{$member} signale un souci",
                "« {$reason->label()} » sur ton {$short}. Tes gains pour ce membre sont en pause le temps que ce soit réglé.",
                ['label' => 'Voir', 'to' => "/host/offers/{$sub->host_offer_id}"]));
            AdminAlerts::send('disputes', "Souci signalé · {$short}", "{$member} : {$reason->label()}".($message ? ' — '.Str::limit($message, 80) : ''), '/disputes', 'disputes');

            return $dispute;
        });
    }

    /** Le membre confirme que c'est réglé : gains de l'hôte libérés. */
    public function solve(Dispute $dispute): Dispute
    {
        return $this->close($dispute, DisputeStatus::Solved, null, null);
    }

    /** Décision de l'équipe. */
    public function resolve(Dispute $dispute, bool $refund, ?string $note, Admin $admin): Dispute
    {
        return $this->close($dispute, $refund ? DisputeStatus::Refunded : DisputeStatus::Rejected, $note, $admin);
    }

    private function close(Dispute $dispute, DisputeStatus $status, ?string $note, ?Admin $admin): Dispute
    {
        return DB::transaction(function () use ($dispute, $status, $note, $admin) {
            $dispute = Dispute::with('subscription.service', 'subscription.user', 'offer.user')->lockForUpdate()->findOrFail($dispute->id);
            if ($dispute->status !== DisputeStatus::Open) {
                throw ValidationException::withMessages(['dispute' => 'Ce signalement est déjà clos.']);
            }
            $sub = $dispute->subscription;
            $short = Str::before($sub->service->name, ' ');
            $host = $dispute->offer?->user;

            if ($status === DisputeStatus::Refunded) {
                [$amount, $paidUntil] = $this->earnings->refundUnreleased($sub, "{$short} · souci d’accès");
                // Filleul remboursé peu après son arrivée : la récompense du parrain est reprise.
                app(ReferralService::class)->cancelIfRefunded($sub->user);
                // L'accès s'arrête là où l'hôte a été payé ; le membre est sorti du cercle.
                $ends = $paidUntil && $paidUntil->isFuture() ? $paidUntil : now();
                $sub->update(['ends_at' => $ends->min($sub->ends_at), 'auto_renew' => false, 'host_offer_id' => null]);
                $dispute->offer?->members()->where('user_id', $sub->user_id)->delete();
                $sub->user->notify(new AppNotification('pay', "Souci {$short} : remboursé",
                    $amount > 0 ? EarningService::fcfa($amount).' te sont remboursés.' : 'Tout avait déjà été versé à l’hôte : contacte le support pour la suite.',
                    ['label' => 'Voir les offres', 'to' => '/service/'.$sub->service->slug]));
                $host?->notify(new AppNotification('host', "Souci {$short} : membre remboursé",
                    'Après vérification, '.$sub->user->shortName().' est remboursé·e du temps non versé.'));
            } else {
                $this->earnings->hold($sub, false);
                $host?->notify(new AppNotification('host', "Souci {$short} réglé", 'Tes gains pour '.$sub->user->shortName().' reprennent normalement.'));
                if ($status === DisputeStatus::Rejected) {
                    $sub->user->notify(new AppNotification('ok', "Souci {$short} : clos", $note ?: 'Après vérification, l’accès fonctionne. Écris-nous si ce n’est pas le cas.'));
                }
            }

            $dispute->update(['status' => $status, 'resolution' => $note, 'resolved_by' => $admin?->id, 'resolved_at' => now()]);
            $admin?->log('dispute.'.($status === DisputeStatus::Refunded ? 'refund' : 'reject'), $dispute, array_filter(['note' => $note]));

            return $dispute;
        });
    }
}
