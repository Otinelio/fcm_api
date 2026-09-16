<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RestaurantSubscriptionResource\Pages;
use App\Models\RestaurantSubscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RestaurantSubscriptionResource extends Resource
{
    protected static ?string $model = RestaurantSubscription::class;

    protected static ?string $navigationGroup = 'Finance & Abonnements';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Abonnements Restos';

    protected static ?string $pluralModelLabel = 'Abonnements Restaurants';

    protected static ?string $modelLabel = 'Abonnement Restaurant';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('restaurant_id')
                    ->relationship('restaurant', 'name')
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('plan_id')
                    ->relationship('plan', 'name')
                    ->searchable(),
                Forms\Components\Select::make('status')
                    ->options([
                        'active' => 'Actif',
                        'pending' => 'En attente',
                        'canceled' => 'Annulé',
                        'expired' => 'Expiré',
                    ])
                    ->default('active')
                    ->required(),
                Forms\Components\Select::make('billing_cycle')
                    ->options([
                        'monthly' => 'Mensuel',
                        'yearly' => 'Annuel',
                    ])
                    ->default('monthly')
                    ->required(),
                Forms\Components\DateTimePicker::make('starts_at'),
                Forms\Components\DateTimePicker::make('ends_at'),
                Forms\Components\DateTimePicker::make('canceled_at'),
                Forms\Components\TextInput::make('payment_reference')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Restaurant')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plan')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'canceled' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Actif',
                        'pending' => 'En attente',
                        'canceled' => 'Annulé',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRestaurantSubscriptions::route('/'),
            'create' => Pages\CreateRestaurantSubscription::route('/create'),
            'edit' => Pages\EditRestaurantSubscription::route('/{record}/edit'),
        ];
    }
}
