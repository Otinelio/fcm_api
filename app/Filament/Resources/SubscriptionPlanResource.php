<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationGroup = 'Finance & Abonnements';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Formules d\'Abonnement';

    protected static ?string $pluralModelLabel = 'Formules d\'Abonnement';

    protected static ?string $modelLabel = 'Formule d\'Abonnement';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('price_monthly')
                    ->label('Prix Mensuel (FCFA)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('price_yearly')
                    ->label('Prix Annuel (FCFA)')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('max_staff')
                    ->numeric()
                    ->default(1),
                Forms\Components\TextInput::make('max_loyalty_programs')
                    ->numeric()
                    ->default(1),
                Forms\Components\TextInput::make('max_clients')
                    ->numeric(),
                Forms\Components\Toggle::make('allows_cashback')
                    ->label('Cashback autorisé'),
                Forms\Components\Toggle::make('allows_vip')
                    ->label('Niveaux VIP autorisés'),
                Forms\Components\Toggle::make('allows_auto_notifications')
                    ->label('Notifications automatiques'),
                Forms\Components\Toggle::make('allows_geolocation')
                    ->label('Géolocalisation'),
                Forms\Components\Toggle::make('allows_marketplace')
                    ->label('Marketplace'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Plan Actif')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price_monthly')
                    ->label('Mensuel (FCFA)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_yearly')
                    ->label('Annuel (FCFA)')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
