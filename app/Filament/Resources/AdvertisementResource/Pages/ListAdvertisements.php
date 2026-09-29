<?php

namespace App\Filament\Resources\AdvertisementResource\Pages;

use App\Filament\Resources\AdvertisementResource;
use App\Models\Advertisement;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAdvertisements extends ListRecords
{
    protected static string $resource = AdvertisementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouvelle publicité')
                ->icon('heroicon-m-plus'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Toutes')
                ->badge(Advertisement::count()),
            'active' => Tab::make('En diffusion')
                ->modifyQueryUsing(fn (Builder $query) => $query->active())
                ->badge(Advertisement::active()->count())
                ->badgeColor('success'),
            'scheduled' => Tab::make('Programmées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', now()))
                ->badge(Advertisement::where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', now())->count())
                ->badgeColor('info'),
            'expired' => Tab::make('Expirées')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('ends_at')->where('ends_at', '<', now()))
                ->badge(Advertisement::whereNotNull('ends_at')->where('ends_at', '<', now())->count())
                ->badgeColor('warning'),
            'inactive' => Tab::make('Désactivées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(Advertisement::where('is_active', false)->count())
                ->badgeColor('danger'),
        ];
    }
}
