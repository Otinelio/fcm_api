<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    protected $table = 'loyalty_transactions';

    protected $fillable = [
        'loyalty_card_id',
        'staff_user_id',
        'type',
        'value',
        'montant_commande_fcfa',
        'validation_method',
        'status',
        'canceled_by_staff_user_id',
        'canceled_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'montant_commande_fcfa' => 'decimal:2',
            'canceled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    public function canceledByStaffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'canceled_by_staff_user_id');
    }
}
