<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'name', 'mono', 'color', 'fg', 'category', 'meta', 'description',
    'price', 'full_price', 'seats', 'activation_minutes', 'position', 'is_popular', 'is_active',
])]
class Service extends Model
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Le membre choisit-il son offre ? Non pour la musique : chacun garde son
     * propre compte dans un groupe famille, toutes les offres se valent —
     * Sub.ci attribue la meilleure, l'hôte valide ensuite.
     */
    public function choosesOffer(): bool
    {
        return $this->category !== Category::Music;
    }

    public function savingPercent(): int
    {
        return (int) round((1 - $this->price / max(1, $this->full_price)) * 100);
    }

    /** Prix d'une durée à partir du prix mensuel d'une place : 3 mois -5 %, 6 mois -10 %. */
    public static function durationPrice(int $monthly, int $months): int
    {
        $discount = match ($months) {
            3 => 0.05,
            6 => 0.10,
            default => 0,
        };

        return (int) round($monthly * $months * (1 - $discount));
    }

    public function hostOffers(): HasMany
    {
        return $this->hasMany(HostOffer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
