<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'type', // stamp, points, cashback, vip
        'is_active',
        'loops',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'loops' => 'boolean',
            'config' => 'array',
        ];
    }

    /**
     * Accesseur pour config : résout dynamiquement l'URL du logo.
     */
    public function getConfigAttribute($value): array
    {
        $config = is_array($value) ? $value : (json_decode($value ?? '{}', true) ?: []);
        if (! empty($config['logo_url'])) {
            $config['logo_url'] = \App\Support\StorageUrlResolver::resolve($config['logo_url']);
        }
        return $config;
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function tiers()
    {
        return $this->hasMany(LoyaltyProgramTier::class)->orderBy('order');
    }
}
