<?php

namespace App\Filament\Resources\RestaurantSubscriptionResource\Pages;

use App\Filament\Resources\RestaurantSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRestaurantSubscriptions extends ListRecords
{
    protected static string $resource = RestaurantSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
