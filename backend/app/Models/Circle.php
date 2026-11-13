<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** Cercle de partage : des amis se regroupent pour partager le coût d'un abonnement. */
#[Fillable(['user_id', 'service_id', 'name', 'code', 'is_open', 'seats', 'price', 'seat_price', 'closed_at'])]
class Circle extends Model
{
    public const MAX_SEATS = 10;

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Circle $circle) {
            // Sans 0/O ni 1/I : se recopie sans erreur.
            do {
                $code = Str::upper(Str::random(8));
                $code = strtr($code, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'W', 'L' => 'V']);
            } while (static::where('code', $code)->exists());
            $circle->code ??= $code;
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Membres, créateur compris. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'circle_members')->withTimestamps()->orderByPivot('created_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function freeSeats(): int
    {
        return max(0, $this->seats - $this->members()->count());
    }

    /** Ce que paie un membre : le prix fixé par le créateur (cercle ouvert), sinon la part égale. */
    public function memberPrice(): int
    {
        return $this->is_open && $this->seat_price ? $this->seat_price : $this->share();
    }

    /** Part de chacun si tout le monde paie la même chose (arrondie au franc supérieur). */
    public function share(): int
    {
        return (int) ceil($this->price / max(1, $this->seats));
    }
}
