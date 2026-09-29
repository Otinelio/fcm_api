<?php

namespace App\Models;

use App\Services\Image\AdvertisementImageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Advertisement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'description',
        'image_path',
        'link_url',
        'starts_at',
        'ends_at',
        'is_active',
        'order',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    protected $appends = [
        'image_url',
    ];

    /**
     * URL publique absolue du visuel publicitaire.
     * S'adapte dynamiquement à l'hôte HTTP courant (IP locale 192.168.x.x, domaine de prod ou ngrok).
     */
    public function getImageUrlAttribute(): ?string
    {
        return \App\Support\StorageUrlResolver::resolve($this->image_path);
    }

    /**
     * Scope pour les publicités actives dans leur période de validité.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            });
    }

    /**
     * Suppression automatique du fichier physique lors de la suppression de l'enregistrement.
     */
    protected static function booted(): void
    {
        static::deleted(function (Advertisement $ad) {
            if ($ad->image_path) {
                app(AdvertisementImageService::class)->deleteIfExists($ad->image_path);
            }
        });

        static::updating(function (Advertisement $ad) {
            if ($ad->isDirty('image_path') && $ad->getOriginal('image_path')) {
                app(AdvertisementImageService::class)->deleteIfExists($ad->getOriginal('image_path'));
            }
        });
    }
}
