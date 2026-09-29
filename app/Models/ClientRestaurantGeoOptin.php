<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientRestaurantGeoOptin extends Model
{
    use HasFactory;

    protected $table = 'client_restaurant_geo_optins';

    protected $fillable = [
        'client_id',
        'restaurant_id',
        'opted_in',
        'radius_m',
        'is_inside',
        'last_entered_at',
        'last_exited_at',
        'last_notified_at',
    ];

    protected $casts = [
        'opted_in' => 'boolean',
        'radius_m' => 'integer',
        'is_inside' => 'boolean',
        'last_entered_at' => 'datetime',
        'last_exited_at' => 'datetime',
        'last_notified_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
