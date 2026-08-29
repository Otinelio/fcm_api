<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $guarded = [];

    public function campaign()
    {
        return $this->belongsTo(NotificationCampaign::class, 'notification_campaign_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
