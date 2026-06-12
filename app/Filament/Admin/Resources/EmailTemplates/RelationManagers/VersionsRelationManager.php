<?php

namespace App\Filament\Admin\Resources\EmailTemplates\RelationManagers;

use App\Models\Communications\EmailTemplate;
use App\Models\Communications\EmailTemplateVersion;
use App\Services\Communications\EmailTemplateService;
use App\Support\EmailTemplateVariables;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->versionFormComponents());
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('version_number')
                    ->label('Ver.')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'   => 'success',
                        'draft'    => 'warning',
                        'archived' => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => EmailTemplateVersion::STATUSES[$state] ?? $state),
                TextColumn::make('subject')
                    ->limit(60),
                TextColumn::make('notes')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y H:i'),
            ])
            ->headerActions([
                Action::make('addVersion')
                    ->label('Add Version')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Add Version')
                    ->modalDescription('New versions start as drafts. Activate a draft to put it into use.')
                    ->modalWidth('4xl')
                    ->form($this->versionFormComponents())
                    ->action(function (array $data): void {
                        app(EmailTemplateService::class)->createDraft(
                            $this->getOwnerRecord()->id,
                            $data['subject'],
                            $data['html_body'] ?? null,
                            $data['text_body'] ?? null,
                            $data['notes'] ?? null,
                            Auth::id(),
                        );

                        Notification::make()->title('Draft version created')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (EmailTemplateVersion $record): string => "Preview — Version {$record->version_number}")
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (EmailTemplateVersion $record) {
                        $rendered = app(EmailTemplateService::class)->preview(
                            $this->getOwnerRecord()->template_key,
                            $record->subject,
                            $record->html_body,
                            $record->text_body,
                        );

                        return view('filament.admin.email-templates.version-preview', [
                            'rendered' => $rendered,
                        ]);
                    }),

                Action::make('editDraft')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (EmailTemplateVersion $record): bool => $record->status === 'draft')
                    ->modalHeading(fn (EmailTemplateVersion $record): string => "Edit Draft — Version {$record->version_number}")
                    ->modalWidth('4xl')
                    ->fillForm(fn (EmailTemplateVersion $record): array => [
                        'subject'   => $record->subject,
                        'html_body' => $record->html_body,
                        'text_body' => $record->text_body,
                        'notes'     => $record->notes,
                    ])
                    ->form($this->versionFormComponents())
                    ->action(function (EmailTemplateVersion $record, array $data): void {
                        try {
                            app(EmailTemplateService::class)->updateDraft(
                                $record->id,
                                $data['subject'],
                                $data['html_body'] ?? null,
                                $data['text_body'] ?? null,
                                $data['notes'] ?? null,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Draft saved')->success()->send();
                    }),

                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (EmailTemplateVersion $record): bool => $record->status !== 'active')
                    ->requiresConfirmation()
                    ->modalHeading('Activate Version')
                    ->modalDescription('This version becomes the one sent to users. The currently active version is archived.')
                    ->action(function (EmailTemplateVersion $record): void {
                        app(EmailTemplateService::class)->activateVersion($record->id);

                        Notification::make()->title("Version {$record->version_number} activated")->success()->send();
                    }),

                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (EmailTemplateVersion $record): void {
                        $draft = app(EmailTemplateService::class)->duplicateAsDraft($record->id, Auth::id());

                        Notification::make()
                            ->title("Copied to draft version {$draft->version_number}")
                            ->success()
                            ->send();
                    }),

                Action::make('sendTest')
                    ->label('Send Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->modalHeading('Send Test Email')
                    ->modalDescription('Sends this version with sample placeholder data.')
                    ->form([
                        TextInput::make('to')
                            ->label('Send To')
                            ->email()
                            ->required()
                            ->default(fn () => Auth::user()?->email),
                    ])
                    ->action(function (EmailTemplateVersion $record, array $data): void {
                        $rendered = app(EmailTemplateService::class)->preview(
                            $this->getOwnerRecord()->template_key,
                            $record->subject,
                            $record->html_body,
                            $record->text_body,
                        );

                        try {
                            if ($rendered->html !== null) {
                                Mail::html($rendered->html, function ($message) use ($data, $rendered): void {
                                    $message->to($data['to'])->subject('[TEST] ' . $rendered->subject);
                                });
                            } else {
                                Mail::raw((string) $rendered->text, function ($message) use ($data, $rendered): void {
                                    $message->to($data['to'])->subject('[TEST] ' . $rendered->subject);
                                });
                            }

                            Notification::make()
                                ->title('Test email sent')
                                ->body("Sent to {$data['to']} with sample data.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Test email failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('deleteDraft')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (EmailTemplateVersion $record): bool => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Draft')
                    ->action(function (EmailTemplateVersion $record): void {
                        app(EmailTemplateService::class)->deleteDraft($record->id);

                        Notification::make()->title('Draft deleted')->success()->send();
                    }),
            ]);
    }

    /** Shared form fields for the Add Version / Edit Draft modals. */
    private function versionFormComponents(): array
    {
        return [
            TextInput::make('subject')
                ->label('Subject')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('html_body')
                ->label('HTML Body')
                ->rows(16)
                ->helperText('Full HTML document. Leave blank for plain-text-only emails.')
                ->columnSpanFull(),
            Textarea::make('text_body')
                ->label('Text Body')
                ->rows(8)
                ->helperText('Plain-text alternative. Recommended even when an HTML body is set.')
                ->columnSpanFull(),
            TextInput::make('notes')
                ->label('Version Notes')
                ->maxLength(255)
                ->placeholder('What changed in this version')
                ->columnSpanFull(),
            Placeholder::make('variables')
                ->label('Available Variables')
                ->content(fn (): HtmlString => $this->variableReference())
                ->columnSpanFull(),
        ];
    }

    private function variableReference(): HtmlString
    {
        /** @var EmailTemplate $template */
        $template = $this->getOwnerRecord();

        $rows = '';
        foreach (EmailTemplateVariables::for($template->template_key) as $key => $info) {
            $token = e('{' . $key . '}');
            $label = e($info['label']);
            $rows .= "<div style=\"display:flex;gap:12px;padding:2px 0;\"><code style=\"min-width:180px;\">{$token}</code><span>{$label}</span></div>";
        }

        return new HtmlString(
            '<div style="font-size:13px;">' . $rows . '</div>'
        );
    }
}
