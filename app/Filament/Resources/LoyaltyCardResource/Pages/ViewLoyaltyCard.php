<?php

namespace App\Filament\Resources\LoyaltyCardResource\Pages;

use App\Filament\Resources\LoyaltyCardResource;
use Filament\Actions;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewLoyaltyCard extends ViewRecord
{
    protected static string $resource = LoyaltyCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil-square'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\ViewEntry::make('card_detail')
                    ->label('')
                    ->view('filament.resources.loyalty-card.components.card-detail-view')
                    ->columnSpanFull(),
            ]);
    }
}
