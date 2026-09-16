<?php

namespace App\Filament\Resources\LoyaltyCardResource\Pages;

use App\Filament\Resources\LoyaltyCardResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLoyaltyCards extends ListRecords
{
    protected static string $resource = LoyaltyCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
