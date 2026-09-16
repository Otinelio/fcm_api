<?php

namespace App\Filament\Resources\RestaurantSubscriptionResource\Pages;

use App\Filament\Resources\RestaurantSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRestaurantSubscription extends EditRecord
{
    protected static string $resource = RestaurantSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
