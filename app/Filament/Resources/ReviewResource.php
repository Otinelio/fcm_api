<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReviewResource\Pages;
use App\Models\Review;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationGroup = 'Clients';

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Avis';

    protected static ?string $pluralModelLabel = 'Avis & Notes';

    protected static ?string $modelLabel = 'Avis';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'Avis')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement Évalué')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (" . ($record->email ?: 'Sans nom') . ")"))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('client_id')
                                    ->label('Client Auteur')
                                    ->relationship('client', 'phone')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} ({$record->phone})")
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('rating')
                                    ->label('Note Attribuée')
                                    ->options([
                                        5 => '★★★★★ (5/5)',
                                        4 => '★★★★☆ (4/5)',
                                        3 => '★★★☆☆ (3/5)',
                                        2 => '★★☆☆☆ (2/5)',
                                        1 => '★☆☆☆☆ (1/5)',
                                    ])
                                    ->default(5)
                                    ->required(),
                            ]),

                        Forms\Components\Textarea::make('comment')
                            ->label('Commentaire du Client')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.full_name')
                    ->label('Client')
                    ->description(fn (Review $record): ?string => $record->client?->phone)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rating')
                    ->label('Note')
                    ->badge()
                    ->formatStateUsing(fn ($state) => "{$state}/5 ★")
                    ->color(fn (int $state): string => match (true) {
                        $state >= 4 => 'success',
                        $state === 3 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Commentaire')
                    ->limit(55)
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('rating')
                    ->label('Note')
                    ->options([
                        5 => '5 étoiles',
                        4 => '4 étoiles',
                        3 => '3 étoiles',
                        2 => '2 étoiles',
                        1 => '1 étoile',
                    ]),

                Tables\Filters\SelectFilter::make('restaurant_id')
                    ->label('Établissement')
                    ->options(fn (): array => \App\Models\Restaurant::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($r) => [$r->id => (string) ($r->name ?: "Établissement #{$r->id} (" . ($r->email ?: 'Sans nom') . ")")])
                        ->all()
                    )
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun avis enregistré')
            ->emptyStateDescription('Les retours d\'expérience des clients apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-star');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviews::route('/'),
            'create' => Pages\CreateReview::route('/create'),
            'edit' => Pages\EditReview::route('/{record}/edit'),
        ];
    }
}
