<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'last_login_at', 'alerts'])]
#[Hidden(['password'])]
class Admin extends Authenticatable
{
    use HasApiTokens;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'alerts' => 'array',
        ];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AdminAction::class);
    }

    /** Alertes push choisies (défauts de AdminAlerts::KINDS si jamais réglées). */
    public function alertSettings(): array
    {
        return collect(\App\Services\AdminAlerts::KINDS)
            ->map(fn ($k, $kind) => (bool) ($this->alerts[$kind] ?? $k[1]))
            ->all();
    }

    public function wantsAlert(string $kind): bool
    {
        return $this->alertSettings()[$kind] ?? false;
    }

    /** Trace une action d'administration. */
    public function log(string $action, ?object $subject = null, array $meta = []): AdminAction
    {
        return $this->actions()->create([
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
