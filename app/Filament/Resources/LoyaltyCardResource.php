<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoyaltyCardResource\Pages;
use App\Models\LoyaltyCard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoyaltyCardResource extends Resource
{
    protected static ?string $model = LoyaltyCard::class;

    protected static ?string $navigationGroup = 'Gestion Clients';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Cartes de Fidélité';

    protected static ?string $pluralModelLabel = 'Cartes de Fidélité';

    protected static ?string $modelLabel = 'Carte de Fidélité';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('client_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('restaurant_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('loyalty_program_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('card_code')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('progress'),
                Forms\Components\TextInput::make('cashback_balance_fcfa')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('vip_tier')
                    ->maxLength(255),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('active'),
                Forms\Components\DateTimePicker::make('last_activity_at'),
                Forms\Components\DateTimePicker::make('completed_at'),
                Forms\Components\TextInput::make('max_level_name')
                    ->maxLength(255),
                Forms\Components\TextInput::make('max_level_order')
                    ->numeric(),
                Forms\Components\DateTimePicker::make('max_level_reached_at'),
                Forms\Components\TextInput::make('cycles_completed')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('referral_code')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('restaurant_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('loyalty_program_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('card_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cashback_balance_fcfa')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vip_tier')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_level_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('max_level_order')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_level_reached_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cycles_completed')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('referral_code')
                    ->searchable(),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoyaltyCards::route('/'),
            'create' => Pages\CreateLoyaltyCard::route('/create'),
            'edit' => Pages\EditLoyaltyCard::route('/{record}/edit'),
        ];
    }
}
