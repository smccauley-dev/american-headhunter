<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Concerns\HasEditPageScaffold;
use App\Filament\Admin\Resources\Users\CustomerUserResource;
use App\Models\Identity\BackgroundCheckResult;
use App\Models\Identity\IdentityVerification;
use App\Models\Identity\OfacScreeningResult;
use App\Models\Identity\Role;
use App\Models\Identity\UserAdminNote;
use App\Models\Identity\UserProfile;
use App\Services\Audit\AuditService;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuth;
use App\Support\HasIconPageHeading;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditCustomerUser extends EditRecord
{
    use HasEditPageScaffold;
    use HasIconPageHeading;

    protected static string $resource = CustomerUserResource::class;

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $name = trim(
            ($this->getRecord()->profile?->first_name ?? '')
            . ' '
            . ($this->getRecord()->profile?->last_name ?? '')
        ) ?: $this->getRecord()->email;

        return $this->headingWithIcon($name, 'heroicon-o-user');
    }

    public function form(Schema $schema): Schema
    {
        $nonAdminRoles = CustomerUserResource::getNonAdminRoles();

        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([

                    // ── Identity ──────────────────────────────────────────────
                    Tab::make('Identity')
                        ->schema([
                            Section::make()
                                ->columns(2)
                                ->schema([
                                    Placeholder::make('user_id_display')
                                        ->label('User ID')
                                        ->content(fn () => $this->getRecord()->id)
                                        ->columnSpanFull(),
                                    FileUpload::make('avatar_upload')
                                        ->label('Profile Photo')
                                        ->image()
                                        ->disk('local')
                                        ->directory('tmp/avatars')
                                        ->imagePreviewHeight('80')
                                        ->columnSpanFull()
                                        ->helperText('Stored via DocumentService on save. JPG, PNG, WebP — max 5MB.')
                                        ->maxSize(5120)
                                        ->dehydrated(false),

                                    TextInput::make('first_name')
                                        ->label('First Name')
                                        ->maxLength(100),
                                    TextInput::make('last_name')
                                        ->label('Last Name')
                                        ->maxLength(100),
                                    TextInput::make('email')
                                        ->label('Email Address')
                                        ->email()
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(table: 'users', column: 'email', ignoreRecord: true)
                                        ->columnSpanFull(),
                                    TextInput::make('phone')
                                        ->label('Phone')
                                        ->tel()
                                        ->maxLength(20),
                                    Select::make('account_type')
                                        ->label('Primary Portal')
                                        ->helperText('Controls which portal this user is directed to at login.')
                                        ->options([
                                            'hunter'     => 'Hunter',
                                            'landowner'  => 'Landowner',
                                            'club'       => 'Club',
                                            'outfitter'  => 'Outfitter',
                                            'consultant' => 'Consultant',
                                            'seller'     => 'Seller',
                                        ])
                                        ->required(),
                                    Select::make('status')
                                        ->label('Account Status')
                                        ->options([
                                            'active'               => 'Active',
                                            'suspended'            => 'Suspended',
                                            'banned'               => 'Banned',
                                            'pending_verification' => 'Pending Verification',
                                        ])
                                        ->required(),
                                    CheckboxList::make('roles')
                                        ->label('Platform Roles')
                                        ->helperText('Multi-role: controls what the user can do. Portal above controls where they log in.')
                                        ->relationship(
                                            'roles',
                                            'display_name',
                                            fn ($query) => $query
                                                ->whereIn('name', CustomerUserResource::getNonAdminRoles())
                                                ->orderBy('display_name')
                                        )
                                        ->columns(3)
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    // ── Profile ───────────────────────────────────────────────
                    Tab::make('Profile')
                        ->schema([
                            Section::make()
                                ->columns(2)
                                ->schema([
                                    TextInput::make('display_name')
                                        ->label('Display Name')
                                        ->maxLength(100)
                                        ->columnSpanFull(),
                                    Textarea::make('bio')
                                        ->label('Bio')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                    Select::make('state_code')
                                        ->label('State')
                                        ->options(function () {
                                            $codes = [
                                                'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
                                                'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
                                                'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
                                                'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
                                                'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
                                            ];
                                            return array_combine($codes, $codes);
                                        })
                                        ->searchable(),
                                    TextInput::make('zip_code')
                                        ->label('ZIP Code')
                                        ->maxLength(10),
                                    DatePicker::make('date_of_birth')
                                        ->label('Date of Birth')
                                        ->maxDate(now()->subYears(13)),
                                    Select::make('gender')
                                        ->options([
                                            'male'              => 'Male',
                                            'female'            => 'Female',
                                            'prefer_not_to_say' => 'Prefer Not to Say',
                                        ])
                                        ->placeholder('—'),
                                    Toggle::make('is_veteran')
                                        ->label('Veteran')
                                        ->helperText('Set automatically on veteran verification approval — only override with super_admin authority.')
                                        ->disabled(fn () => ! AdminAuth::isSuperAdmin())
                                        ->live(),

                                    Select::make('veteran_branch')
                                        ->label('Branch of Service')
                                        ->options([
                                            'army'          => 'Army',
                                            'navy'          => 'Navy',
                                            'air_force'     => 'Air Force',
                                            'marine_corps'  => 'Marine Corps',
                                            'coast_guard'   => 'Coast Guard',
                                            'space_force'   => 'Space Force',
                                            'national_guard'=> 'National Guard',
                                            'reserves'      => 'Reserves',
                                        ])
                                        ->placeholder('Select branch')
                                        ->hidden(fn (Get $get) => ! $get('is_veteran')),

                                    Toggle::make('veteran_is_active')
                                        ->label('Currently Active Duty')
                                        ->hidden(fn (Get $get) => ! $get('is_veteran')),

                                    TextInput::make('veteran_service_range')
                                        ->label('Years of Service')
                                        ->placeholder('1994 / 1998')
                                        ->mask('9999 / 9999')
                                        ->maxLength(11)
                                        ->hidden(fn (Get $get) => ! $get('is_veteran')),

                                    TextInput::make('veteran_last_rank')
                                        ->label('Last Held Rank')
                                        ->maxLength(100)
                                        ->placeholder('e.g. Staff Sergeant')
                                        ->hidden(fn (Get $get) => ! $get('is_veteran')),

                                    Textarea::make('veteran_bio')
                                        ->label('Service Notes')
                                        ->rows(3)
                                        ->maxLength(1000)
                                        ->columnSpanFull()
                                        ->hidden(fn (Get $get) => ! $get('is_veteran')),

                                    Toggle::make('is_first_responder')
                                        ->label('First Responder')
                                        ->helperText('Police, fire, EMS, and other first responder service.')
                                        ->columnSpanFull()
                                        ->live(),

                                    Select::make('first_responder_type')
                                        ->label('Type')
                                        ->options([
                                            'police'       => 'Police Officer',
                                            'sheriff'      => 'Sheriff / Deputy',
                                            'fire'         => 'Firefighter',
                                            'emt_paramedic'=> 'EMT / Paramedic',
                                            'search_rescue'=> 'Search & Rescue',
                                            'dispatcher'   => '911 Dispatcher',
                                            'correctional' => 'Correctional Officer',
                                        ])
                                        ->placeholder('Select type')
                                        ->hidden(fn (Get $get) => ! $get('is_first_responder')),

                                    Toggle::make('first_responder_is_active')
                                        ->label('Currently Active')
                                        ->hidden(fn (Get $get) => ! $get('is_first_responder')),

                                    TextInput::make('first_responder_service_range')
                                        ->label('Years of Service')
                                        ->placeholder('2005 / 2018')
                                        ->mask('9999 / 9999')
                                        ->maxLength(11)
                                        ->hidden(fn (Get $get) => ! $get('is_first_responder')),

                                    TextInput::make('first_responder_last_rank')
                                        ->label('Last Held Rank / Title')
                                        ->maxLength(100)
                                        ->placeholder('e.g. Lieutenant')
                                        ->hidden(fn (Get $get) => ! $get('is_first_responder')),

                                    Textarea::make('first_responder_bio')
                                        ->label('Service Notes')
                                        ->rows(3)
                                        ->maxLength(1000)
                                        ->columnSpanFull()
                                        ->hidden(fn (Get $get) => ! $get('is_first_responder')),
                                ]),
                        ]),

                    // ── Security ──────────────────────────────────────────────
                    Tab::make('Security')
                        ->schema([
                            Section::make('Public Profile')
                                ->columns(2)
                                ->schema([
                                    Toggle::make('is_profile_public')
                                        ->label('Profile is Public')
                                        ->helperText('When enabled the user\'s profile is visible at /hunters/{username}. Only Hunters, Anglers, and Outfitters should have this enabled.')
                                        ->columnSpanFull(),

                                    TextInput::make('username')
                                        ->label('Username / @handle')
                                        ->helperText('Set once by the user when they first enable a public profile. Used as the URL slug (/hunters/username) and @mention handle. Only super_admin may change it.')
                                        ->prefix('@')
                                        ->maxLength(30)
                                        ->regex('/^[a-z][a-z0-9_]{2,29}$/')
                                        ->disabled(fn () => ! AdminAuth::isSuperAdmin())
                                        ->dehydrated(fn () => AdminAuth::isSuperAdmin())
                                        ->unique(table: 'users', column: 'username', ignoreRecord: true)
                                        ->placeholder('not yet set'),
                                ]),

                            Section::make('Password')
                                ->schema([
                                    TextInput::make('new_password')
                                        ->label('Set New Password')
                                        ->password()
                                        ->revealable()
                                        ->minLength(10)
                                        ->maxLength(128)
                                        ->helperText('Leave blank to keep the current password. Visible to super_admin only.')
                                        ->visible(fn () => AdminAuth::isSuperAdmin())
                                        ->dehydrated(false),
                                    Placeholder::make('force_reset_info')
                                        ->label('')
                                        ->content('Use "Force Password Reset" in the header actions to send a reset email without setting a password directly.'),
                                ]),

                            Section::make('MFA Status')
                                ->schema([
                                    Placeholder::make('mfa_status_detail')
                                        ->label('Configured Methods')
                                        ->content(function () {
                                            try {
                                                $configs  = $this->getRecord()->mfaConfigurations()->get()->keyBy('method');
                                                $platform = \Illuminate\Support\Facades\DB::connection('platform')
                                                    ->table('mfa_factor_settings')
                                                    ->pluck('is_enabled', 'factor');

                                                $rows = '';
                                                foreach ([
                                                    'email' => 'Email',
                                                    'totp'  => 'Authenticator App (TOTP)',
                                                    'sms'   => 'SMS / Text Message',
                                                ] as $method => $label) {
                                                    $platformOn = (bool) ($platform[$method] ?? false);
                                                    $cfg        = $configs->get($method);
                                                    $enabled    = $cfg && $cfg->is_enabled;

                                                    $dot = $enabled
                                                        ? '<span style="color:#16a34a;font-size:16px;">●</span>'
                                                        : '<span style="color:#d1d5db;font-size:16px;">○</span>';

                                                    $status = $enabled
                                                        ? '<span style="color:#16a34a;font-weight:600;">Enabled</span>'
                                                          . ($cfg?->verified_at
                                                              ? '<span style="color:#9ca3af;font-size:11px;margin-left:8px;">verified '
                                                                . e($cfg->verified_at->format('M j, Y')) . '</span>'
                                                              : '')
                                                        : '<span style="color:#9ca3af;">Disabled</span>';

                                                    $platformBadge = ! $platformOn
                                                        ? '<span style="color:#ef4444;font-size:10px;margin-left:8px;">[platform off]</span>'
                                                        : '';

                                                    $rows .= '<div style="display:flex;align-items:center;gap:10px;'
                                                        . 'padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">'
                                                        . '<span>' . $dot . '</span>'
                                                        . '<span style="flex:1;color:#374151;">' . e($label) . $platformBadge . '</span>'
                                                        . '<span>' . $status . '</span>'
                                                        . '</div>';
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    $rows ?: '<span style="color:#9ca3af;">No MFA configured</span>'
                                                );
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        })
                                        ->columnSpanFull(),

                                    SchemaActions::make([
                                        // ── Email ─────────────────────────────
                                        Action::make('enable_email_mfa')
                                            ->label('Enable Email MFA')
                                            ->icon('heroicon-o-envelope')
                                            ->color('success')
                                            ->requiresConfirmation()
                                            ->modalHeading('Enable Email MFA')
                                            ->modalDescription('Admin-activates email-based two-factor authentication for this account.')
                                            ->visible(fn () => $this->platformMethodEnabled('email') && ! $this->mfaMethodEnabled('email'))
                                            ->action(function () {
                                                try {
                                                    $this->enableMfaMethod('email');
                                                    Notification::make()->success()->title('Email MFA enabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        Action::make('disable_email_mfa')
                                            ->label('Disable Email MFA')
                                            ->icon('heroicon-o-envelope-open')
                                            ->color('danger')
                                            ->requiresConfirmation()
                                            ->modalHeading('Disable Email MFA')
                                            ->modalDescription('Removes email-based two-factor authentication. The user can re-enable it from their Security settings.')
                                            ->visible(fn () => $this->mfaMethodEnabled('email'))
                                            ->action(function () {
                                                try {
                                                    $this->disableMfaMethod('email');
                                                    Notification::make()->success()->title('Email MFA disabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        // ── TOTP ──────────────────────────────
                                        Action::make('enable_totp_mfa')
                                            ->label('Enable Authenticator MFA')
                                            ->icon('heroicon-o-device-phone-mobile')
                                            ->color('success')
                                            ->requiresConfirmation()
                                            ->modalHeading('Enable Authenticator App MFA')
                                            ->modalDescription('Admin-activates TOTP. The user must still scan the QR code on their next login to complete enrollment.')
                                            ->visible(fn () => $this->platformMethodEnabled('totp') && ! $this->mfaMethodEnabled('totp'))
                                            ->action(function () {
                                                try {
                                                    $this->enableMfaMethod('totp');
                                                    Notification::make()->success()->title('Authenticator MFA enabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        Action::make('disable_totp_mfa')
                                            ->label('Disable Authenticator MFA')
                                            ->icon('heroicon-o-shield-check')
                                            ->color('danger')
                                            ->requiresConfirmation()
                                            ->modalHeading('Disable Authenticator App MFA')
                                            ->modalDescription('Removes TOTP two-factor authentication. The user will need to re-scan the QR code to re-enroll.')
                                            ->visible(fn () => $this->mfaMethodEnabled('totp'))
                                            ->action(function () {
                                                try {
                                                    $this->disableMfaMethod('totp');
                                                    Notification::make()->success()->title('Authenticator MFA disabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        // ── SMS ───────────────────────────────
                                        Action::make('enable_sms_mfa')
                                            ->label('Enable SMS MFA')
                                            ->icon('heroicon-o-chat-bubble-left-ellipsis')
                                            ->color('success')
                                            ->requiresConfirmation()
                                            ->modalHeading('Enable SMS MFA')
                                            ->modalDescription('Admin-activates SMS-based two-factor authentication for this account.')
                                            ->visible(fn () => $this->platformMethodEnabled('sms') && ! $this->mfaMethodEnabled('sms'))
                                            ->action(function () {
                                                try {
                                                    $this->enableMfaMethod('sms');
                                                    Notification::make()->success()->title('SMS MFA enabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        Action::make('disable_sms_mfa')
                                            ->label('Disable SMS MFA')
                                            ->icon('heroicon-o-chat-bubble-left')
                                            ->color('danger')
                                            ->requiresConfirmation()
                                            ->modalHeading('Disable SMS MFA')
                                            ->modalDescription('Removes SMS-based two-factor authentication for this account.')
                                            ->visible(fn () => $this->mfaMethodEnabled('sms'))
                                            ->action(function () {
                                                try {
                                                    $this->disableMfaMethod('sms');
                                                    Notification::make()->success()->title('SMS MFA disabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        // ── Reset / Emergency ─────────────────
                                        Action::make('reset_totp_secret')
                                            ->label('Clear TOTP Token')
                                            ->icon('heroicon-o-arrow-path')
                                            ->color('warning')
                                            ->requiresConfirmation()
                                            ->modalHeading('Clear Authenticator App Token')
                                            ->modalDescription('Clears the stored TOTP secret and disables the authenticator method. The user must re-scan a new QR code to re-enroll.')
                                            ->visible(fn () => $this->mfaTotpExists())
                                            ->action(function () {
                                                try {
                                                    $this->getRecord()
                                                        ->mfaConfigurations()
                                                        ->where('method', 'totp')
                                                        ->update([
                                                            'is_enabled'       => false,
                                                            'secret_encrypted' => null,
                                                            'verified_at'      => null,
                                                        ]);
                                                    Notification::make()->warning()->title('TOTP secret cleared — user must re-enroll')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),

                                        Action::make('disable_all_mfa')
                                            ->label('Disable All MFA')
                                            ->icon('heroicon-o-shield-exclamation')
                                            ->color('danger')
                                            ->requiresConfirmation()
                                            ->modalHeading('Disable All MFA Methods')
                                            ->modalDescription('Emergency: removes every two-factor method from this account. The user will need to re-enroll on their next login.')
                                            ->action(function () {
                                                try {
                                                    $this->getRecord()
                                                        ->mfaConfigurations()
                                                        ->update(['is_enabled' => false, 'verified_at' => null]);
                                                    Notification::make()->warning()->title('All MFA methods disabled')->send();
                                                } catch (\Throwable $e) {
                                                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                                                }
                                            }),
                                    ]),
                                ]),

                            Section::make('Login History')
                                ->schema([
                                    Placeholder::make('login_history')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                            $entries = $this->getRecord()
                                                ->loginHistory()
                                                ->orderByDesc('created_at')
                                                ->limit(20)
                                                ->get();

                                            if ($entries->isEmpty()) {
                                                return 'No login history.';
                                            }

                                            $col = 'style="display:grid;grid-template-columns:2fr 1.5fr 1.2fr 0.8fr;gap:0 1rem;"';
                                            $headerStyle = 'style="padding-bottom:6px;border-bottom:1px solid #e5e7eb;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;"';
                                            $rowStyle    = 'style="padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;align-items:center;"';

                                            $rows = $entries->map(fn ($e) =>
                                                '<div ' . $col . ' ' . $rowStyle . '>'
                                                . '<span style="color:#6b7280;">' . e($e->created_at?->format('M j, Y H:i')) . '</span>'
                                                . '<span style="font-family:monospace;font-size:12px;">' . e($e->ip_address ?? '—') . '</span>'
                                                . ($e->success
                                                    ? '<span style="color:#16a34a;">✓ Success</span>'
                                                    : '<span style="color:#dc2626;">✗ Failed</span>')
                                                . '<span>' . ($e->mfa_used ? '<span style="color:#2563eb;">MFA</span>' : '—') . '</span>'
                                                . '</div>'
                                            )->join('');

                                            return new \Illuminate\Support\HtmlString(
                                                '<div style="width:100%;">'
                                                . '<div ' . $col . ' ' . $headerStyle . '>'
                                                . '<span>Time</span><span>IP Address</span><span>Result</span><span>MFA</span>'
                                                . '</div>'
                                                . $rows
                                                . '</div>'
                                            );
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                    // ── Compliance ────────────────────────────────────────────
                    Tab::make('Compliance')
                        ->schema([
                            Section::make('Trust Score')
                                ->schema([
                                    Placeholder::make('trust_score_display')
                                        ->label('Current Score')
                                        ->content(fn () => $this->getRecord()->trust_score . ' / 100'),
                                    Placeholder::make('trust_score_events')
                                        ->label('Recent Events')
                                        ->content(function () {
                                            try {
                                                $events = $this->getRecord()
                                                    ->trustScoreEvents()
                                                    ->orderByDesc('created_at')
                                                    ->limit(10)
                                                    ->get();

                                                if ($events->isEmpty()) {
                                                    return 'No events recorded.';
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    $events->map(fn ($e) =>
                                                        '<div class="flex gap-4 py-1 border-b border-gray-100 text-sm">'
                                                        . '<span class="text-gray-400 w-36">' . e($e->created_at?->format('M j Y H:i')) . '</span>'
                                                        . '<span class="' . ($e->delta >= 0 ? 'text-green-600' : 'text-red-600') . ' w-12">'
                                                        . ($e->delta >= 0 ? '+' : '') . $e->delta
                                                        . '</span>'
                                                        . '<span class="text-gray-700">' . e($e->reason ?? '—') . '</span>'
                                                        . '</div>'
                                                    )->join('')
                                                );
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),

                            Section::make('Background Check')
                                ->schema([
                                    Placeholder::make('bg_check_status')
                                        ->label('Status')
                                        ->content(function () {
                                            try {
                                                $check = BackgroundCheckResult::where('user_id', $this->getRecord()->id)
                                                    ->orderByDesc('created_at')
                                                    ->first();
                                                if (! $check) return 'No background check on file.';
                                                return ucfirst($check->status ?? 'unknown')
                                                    . ($check->completed_at ? ' — completed ' . $check->completed_at->format('M j Y') : '');
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),

                            Section::make('OFAC Screening')
                                ->schema([
                                    Placeholder::make('ofac_status')
                                        ->label('Latest Result')
                                        ->content(function () {
                                            try {
                                                $result = OfacScreeningResult::where('user_id', $this->getRecord()->id)
                                                    ->orderByDesc('created_at')
                                                    ->first();
                                                if (! $result) return 'No OFAC screening on file.';
                                                return ucfirst($result->result ?? 'unknown')
                                                    . ' — screened ' . $result->created_at?->format('M j Y');
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),

                            Section::make('Identity Verification')
                                ->schema([
                                    Placeholder::make('identity_verifications')
                                        ->label('Verifications')
                                        ->content(function () {
                                            try {
                                                $records = IdentityVerification::where('user_id', $this->getRecord()->id)
                                                    ->orderByDesc('created_at')
                                                    ->get();

                                                if ($records->isEmpty()) {
                                                    return 'No identity verifications on file.';
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    $records->map(fn ($v) =>
                                                        '<div class="py-1 text-sm border-b border-gray-100">'
                                                        . '<span class="font-medium">' . ucfirst($v->verification_type ?? '—') . '</span> '
                                                        . '— ' . ucfirst($v->status ?? '—')
                                                        . ($v->verified_at ? ' on ' . $v->verified_at->format('M j Y') : '')
                                                        . ' via ' . ($v->provider ?? '—')
                                                        . '</div>'
                                                    )->join('')
                                                );
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                    // ── Admin Notes ───────────────────────────────────────────
                    Tab::make('Admin Notes')
                        ->schema([
                            Section::make()
                                ->schema([
                                    Placeholder::make('admin_notes_list')
                                        ->label('Staff Notes')
                                        ->content(function () {
                                            $notes = UserAdminNote::where('user_id', $this->getRecord()->id)
                                                ->orderByDesc('created_at')
                                                ->get();

                                            if ($notes->isEmpty()) {
                                                return 'No staff notes on file.';
                                            }

                                            return new \Illuminate\Support\HtmlString(
                                                $notes->map(fn ($n) =>
                                                    '<div class="py-2 border-b border-gray-100">'
                                                    . '<div class="text-xs text-gray-400 mb-1">'
                                                    . e($n->created_at?->format('M j Y H:i'))
                                                    . ' — ' . e($n->getAuthor()?->profile?->first_name . ' ' . $n->getAuthor()?->profile?->last_name)
                                                    . '</div>'
                                                    . '<div class="text-sm text-gray-800 whitespace-pre-wrap">' . e($n->note) . '</div>'
                                                    . '</div>'
                                                )->join('')
                                            );
                                        }),
                                    Textarea::make('new_note')
                                        ->label('Add Note')
                                        ->rows(3)
                                        ->helperText('Notes are permanent and visible to all staff. Never include sensitive credentials or passwords.')
                                        ->dehydrated(false),
                                ]),
                        ]),

                    // ── Audit Log ─────────────────────────────────────────────
                    Tab::make('Audit Log')
                        ->schema([
                            Section::make()
                                ->schema([
                                    Placeholder::make('audit_log')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                                $events = \App\Models\Audit\AuditLog::on('audit')
                                                    ->where('record_id', $this->getRecord()->id)
                                                    ->orderByDesc('occurred_at')
                                                    ->limit(50)
                                                    ->get();

                                                if ($events->isEmpty()) {
                                                    return 'No audit events for this user.';
                                                }

                                                $boolFields = [
                                                    'is_veteran', 'is_first_responder',
                                                    'veteran_is_active', 'first_responder_is_active',
                                                ];
                                                $formatVal = function ($field, $val) use ($boolFields): string {
                                                    if ($val === null || $val === '') return '—';
                                                    if (in_array($field, $boolFields, true)) {
                                                        return $val ? 'Yes' : 'No';
                                                    }
                                                    if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}(T|\s)/', $val)) {
                                                        try { return \Carbon\Carbon::parse($val)->format('M j, Y'); } catch (\Throwable) {}
                                                    }
                                                    if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                                                        try { return \Carbon\Carbon::parse($val)->format('M j, Y'); } catch (\Throwable) {}
                                                    }
                                                    return (string) $val;
                                                };

                                                $ths = 'text-align:left;font-size:0.72rem;font-weight:600;text-transform:uppercase;'
                                                     . 'letter-spacing:0.05em;color:#6b7280;padding:0.4rem 0.75rem;'
                                                     . 'border-bottom:2px solid #e5e7eb;white-space:nowrap;';
                                                $tds = 'padding:0.55rem 0.75rem;border-bottom:1px solid #f3f4f6;'
                                                     . 'vertical-align:top;font-size:0.875rem;color:#374151;';

                                                $html  = '<table style="width:100%;border-collapse:collapse;">';
                                                $html .= '<thead><tr>'
                                                       . "<th style=\"{$ths}\">Time</th>"
                                                       . "<th style=\"{$ths}\">Event</th>"
                                                       . "<th style=\"{$ths}\">IP</th>"
                                                       . "<th style=\"{$ths}\">Summary</th>"
                                                       . '</tr></thead><tbody>';

                                                foreach ($events as $e) {
                                                    $html .= '<tr>'
                                                           . "<td style=\"{$tds}white-space:nowrap;color:#9ca3af;font-size:0.8rem;\">"
                                                           . e($e->occurred_at?->format('M j, Y H:i')) . '</td>'
                                                           . "<td style=\"{$tds}font-family:monospace;font-size:0.8rem;\">"
                                                           . e($e->event_type) . '</td>'
                                                           . "<td style=\"{$tds}font-family:monospace;font-size:0.8rem;color:#9ca3af;\">"
                                                           . e($e->ip_address ?? '—') . '</td>'
                                                           . "<td style=\"{$tds}\">" . e($e->action_summary ?? '—') . '</td>'
                                                           . '</tr>';

                                                    if (! empty($e->new_values)) {
                                                        $dths = 'text-align:left;font-size:0.68rem;font-weight:600;text-transform:uppercase;'
                                                              . 'letter-spacing:0.04em;color:#9ca3af;padding:0.25rem 0.5rem;'
                                                              . 'border-bottom:1px solid #e5e7eb;';
                                                        $dtds = 'padding:0.2rem 0.5rem;border-bottom:1px solid #f9fafb;'
                                                              . 'font-size:0.75rem;vertical-align:middle;';

                                                        $diffRows = '';
                                                        foreach ($e->new_values as $field => $newVal) {
                                                            $oldVal      = $e->old_values[$field] ?? null;
                                                            $oldFmt      = e($formatVal($field, $oldVal));
                                                            $newFmt      = e($formatVal($field, $newVal));
                                                            $diffRows .= '<tr>'
                                                                . "<td style=\"{$dtds}font-family:monospace;color:#6b7280;\">" . e($field) . '</td>'
                                                                . "<td style=\"{$dtds}color:#dc2626;text-decoration:line-through;\">{$oldFmt}</td>"
                                                                . "<td style=\"{$dtds}color:#16a34a;\">{$newFmt}</td>"
                                                                . '</tr>';
                                                        }

                                                        $html .= '<tr><td colspan="4" style="padding:0 0.75rem 0.5rem 1.5rem;'
                                                               . 'border-bottom:1px solid #f3f4f6;">'
                                                               . '<table style="border-collapse:collapse;width:auto;">'
                                                               . '<thead><tr>'
                                                               . "<th style=\"{$dths}\">Field</th>"
                                                               . "<th style=\"{$dths}\">Before</th>"
                                                               . "<th style=\"{$dths}\">After</th>"
                                                               . '</tr></thead><tbody>'
                                                               . $diffRows
                                                               . '</tbody></table></td></tr>';
                                                    }
                                                }

                                                $html .= '</tbody></table>';
                                                return new \Illuminate\Support\HtmlString($html);
                                            } catch (\Throwable) {
                                                return 'Audit log unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                    // ── Properties & Leases ───────────────────────────────────
                    Tab::make('Properties & Leases')
                        ->schema([
                            Section::make('Properties Owned')
                                ->schema([
                                    Placeholder::make('properties_owned')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                                $userId = $this->getRecord()->id;

                                                // Direct ownership via properties.owner_user_id
                                                $direct = \App\Models\Property\Property::on('property')
                                                    ->where('owner_user_id', $userId)
                                                    ->whereNull('deleted_at')
                                                    ->get(['id', 'title', 'state_code', 'status']);

                                                // Granted ownership via property_managers role = 'owner'
                                                $grantedIds = \App\Models\Property\PropertyManager::on('property')
                                                    ->where('user_id', $userId)
                                                    ->where('role', 'owner')
                                                    ->whereNull('revoked_at')
                                                    ->pluck('property_id');

                                                $granted = \App\Models\Property\Property::on('property')
                                                    ->whereIn('id', $grantedIds)
                                                    ->whereNull('deleted_at')
                                                    ->get(['id', 'title', 'state_code', 'status']);

                                                $props = $direct->merge($granted)->unique('id');

                                                if ($props->isEmpty()) return 'No properties owned.';

                                                $hs = 'font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
                                                    . 'color:#6b7280;padding:0.4rem 0.75rem;border-bottom:2px solid #e5e7eb;';
                                                $cs = 'padding:0.6rem 0.75rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';

                                                $header = '<div style="display:grid;grid-template-columns:2fr 0.5fr 1fr;">'
                                                    . "<div style=\"{$hs}\">Property</div>"
                                                    . "<div style=\"{$hs}\">State</div>"
                                                    . "<div style=\"{$hs}\">Status</div>"
                                                    . '</div>';

                                                $rows = $props->map(function ($p) use ($cs) {
                                                    $statusColor = match ($p->status) {
                                                        'active'    => ['#d1fae5', '#065f46'],
                                                        'draft'     => ['#f3f4f6', '#374151'],
                                                        'suspended' => ['#fef3c7', '#92400e'],
                                                        'archived'  => ['#e5e7eb', '#6b7280'],
                                                        default     => ['#f3f4f6', '#374151'],
                                                    };
                                                    $badge = "<span style=\"background:{$statusColor[0]};color:{$statusColor[1]};"
                                                           . "padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;\">"
                                                           . ucfirst($p->status) . '</span>';

                                                    return '<div style="display:grid;grid-template-columns:2fr 0.5fr 1fr;">'
                                                        . "<div style=\"{$cs}\"><span style=\"font-weight:500;font-size:0.875rem;color:#374151;\">"
                                                        . e($p->title) . '</span></div>'
                                                        . "<div style=\"{$cs}\"><span style=\"font-size:0.8rem;color:#6b7280;\">" . e($p->state_code) . '</span></div>'
                                                        . "<div style=\"{$cs}\">{$badge}</div>"
                                                        . '</div>';
                                                })->join('');

                                                return new \Illuminate\Support\HtmlString($header . $rows);
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),

                            Section::make('Property Manager / Operator Roles')
                                ->schema([
                                    Placeholder::make('property_manager_roles')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                                $grants = \App\Models\Property\PropertyManager::on('property')
                                                    ->where('user_id', $this->getRecord()->id)
                                                    ->whereNull('revoked_at')
                                                    ->whereIn('role', ['co_owner', 'manager', 'operator'])
                                                    ->with('property')
                                                    ->get();

                                                if ($grants->isEmpty()) return 'No property management roles.';

                                                $roleBadge = fn (string $role) => match ($role) {
                                                    'owner'    => ['Owner',     '#fce7f3', '#9d174d'],
                                                    'co_owner' => ['Co-Owner',  '#d1fae5', '#065f46'],
                                                    'manager'  => ['Manager',   '#dbeafe', '#1e40af'],
                                                    'operator' => ['Operator',  '#fef3c7', '#92400e'],
                                                    default    => [ucfirst($role), '#f3f4f6', '#374151'],
                                                };

                                                $hs = 'font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
                                                    . 'color:#6b7280;padding:0.4rem 0.75rem;border-bottom:2px solid #e5e7eb;';
                                                $cs = 'padding:0.6rem 0.75rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';

                                                $header = '<div style="display:grid;grid-template-columns:2fr 1fr 1.2fr;">'
                                                    . "<div style=\"{$hs}\">Property</div>"
                                                    . "<div style=\"{$hs}\">Role</div>"
                                                    . "<div style=\"{$hs}\">Granted</div>"
                                                    . '</div>';

                                                $rows = $grants->map(function ($g) use ($cs, $roleBadge) {
                                                    [$label, $bg, $color] = $roleBadge($g->role);
                                                    $badge = "<span style=\"background:{$bg};color:{$color};padding:0.15rem 0.5rem;"
                                                           . "border-radius:9999px;font-size:0.72rem;font-weight:600;\">{$label}</span>";
                                                    $date  = $g->granted_at?->format('M j, Y') ?? '—';

                                                    return '<div style="display:grid;grid-template-columns:2fr 1fr 1.2fr;">'
                                                        . "<div style=\"{$cs}\"><span style=\"font-weight:500;font-size:0.875rem;color:#374151;\">"
                                                        . e($g->property?->title ?? '—') . '</span></div>'
                                                        . "<div style=\"{$cs}\">{$badge}</div>"
                                                        . "<div style=\"{$cs}\"><span style=\"font-size:0.8rem;color:#6b7280;\">{$date}</span></div>"
                                                        . '</div>';
                                                })->join('');

                                                return new \Illuminate\Support\HtmlString($header . $rows);
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),

                            Section::make('Leases')
                                ->schema([
                                    Placeholder::make('leases_summary')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                                $userId = $this->getRecord()->id;
                                                $leases = \App\Models\Lease\Lease::on('lease')
                                                    ->where(fn ($q) =>
                                                        $q->where('lessee_user_id', $userId)
                                                          ->orWhere('lessor_user_id', $userId)
                                                    )
                                                    ->whereNull('deleted_at')
                                                    ->orderByDesc('created_at')
                                                    ->get(['id', 'lessee_user_id', 'lessor_user_id', 'status', 'start_date', 'end_date']);

                                                if ($leases->isEmpty()) return 'No leases found.';

                                                return new \Illuminate\Support\HtmlString(
                                                    $leases->map(fn ($l) =>
                                                        '<div class="py-1 text-sm border-b border-gray-100 flex gap-3">'
                                                        . '<span class="font-mono text-xs text-gray-400">' . substr($l->id, 0, 8) . '…</span>'
                                                        . '<span class="text-xs px-1 rounded bg-gray-100">'
                                                        . ($l->lessee_user_id === $userId ? 'Lessee' : 'Lessor')
                                                        . '</span>'
                                                        . '<span class="text-xs px-1 rounded bg-green-50 text-green-700">' . e($l->status) . '</span>'
                                                        . '<span class="text-gray-500">'
                                                        . e($l->start_date?->format('M j Y')) . ' – ' . e($l->end_date?->format('M j Y'))
                                                        . '</span>'
                                                        . '</div>'
                                                    )->join('')
                                                );
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),

                            Section::make('Club Memberships')
                                ->schema([
                                    Placeholder::make('club_memberships')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                                $userId = $this->getRecord()->id;

                                                $owned = \App\Models\Lease\Club::on('lease')
                                                    ->where('owner_user_id', $userId)
                                                    ->whereNull('deleted_at')
                                                    ->get(['id', 'name', 'status']);

                                                $memberships = \App\Models\Lease\ClubMember::on('lease')
                                                    ->where('user_id', $userId)
                                                    ->whereNull('deleted_at')
                                                    ->with('club')
                                                    ->get();

                                                if ($owned->isEmpty() && $memberships->isEmpty()) {
                                                    return 'No club memberships.';
                                                }

                                                $lines = [];

                                                foreach ($owned as $c) {
                                                    $lines[] = '<div class="py-1 text-sm border-b border-gray-100 flex gap-3">'
                                                        . '<span class="font-medium">' . e($c->name) . '</span>'
                                                        . '<span class="text-xs px-1 rounded bg-amber-50 text-amber-700">Owner</span>'
                                                        . '<span class="text-xs text-gray-400">' . e($c->status) . '</span>'
                                                        . '</div>';
                                                }

                                                foreach ($memberships as $m) {
                                                    $lines[] = '<div class="py-1 text-sm border-b border-gray-100 flex gap-3">'
                                                        . '<span class="font-medium">' . e($m->club?->name ?? '—') . '</span>'
                                                        . '<span class="text-xs px-1 rounded bg-gray-100">' . e(ucfirst($m->role)) . '</span>'
                                                        . '<span class="text-xs text-gray-400">' . e($m->status) . '</span>'
                                                        . '</div>';
                                                }

                                                return new \Illuminate\Support\HtmlString(implode('', $lines));
                                            } catch (\Throwable) {
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                ]),
        ]);
    }

    // ── Lifecycle hooks ───────────────────────────────────────────────────────

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $profile = $this->getRecord()->profile;
        $data['first_name']   = $profile?->first_name   ?? '';
        $data['last_name']    = $profile?->last_name    ?? '';
        $data['display_name'] = $profile?->display_name ?? '';
        $data['bio']          = $profile?->bio          ?? '';
        $data['state_code']   = $profile?->state_code   ?? null;
        $data['zip_code']     = $profile?->zip_code     ?? '';
        $data['date_of_birth']= $profile?->date_of_birth?->format('Y-m-d') ?? null;
        $data['gender']                = $profile?->gender                ?? null;
        $data['veteran_branch']                = $profile?->veteran_branch                    ?? null;
        $data['veteran_service_range']         = $this->formatServiceRange($profile?->veteran_service_start,        $profile?->veteran_service_end);
        $data['veteran_is_active']             = (bool) ($profile?->veteran_is_active        ?? false);
        $data['veteran_last_rank']             = $profile?->veteran_last_rank                ?? null;
        $data['veteran_bio']                   = $profile?->veteran_bio                     ?? null;
        $data['first_responder_type']          = $profile?->first_responder_type            ?? null;
        $data['first_responder_service_range'] = $this->formatServiceRange($profile?->first_responder_service_start, $profile?->first_responder_service_end);
        $data['first_responder_is_active']     = (bool) ($profile?->first_responder_is_active ?? false);
        $data['first_responder_last_rank']     = $profile?->first_responder_last_rank       ?? null;
        $data['first_responder_bio']           = $profile?->first_responder_bio             ?? null;
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $updateData = [
            'email'        => $data['email'],
            'phone'        => $data['phone']        ?? null,
            'status'       => $data['status'],
            'account_type' => $data['account_type'],
            'is_veteran'         => $data['is_veteran']         ?? $record->is_veteran,
            'is_first_responder' => $data['is_first_responder'] ?? $record->is_first_responder,
            'is_profile_public'  => (bool) ($data['is_profile_public'] ?? $record->is_profile_public),
        ];

        // Username: only super_admin can change it (field is dehydrated only for super_admin).
        // Never blank it out — if the key is absent, leave it untouched.
        if (array_key_exists('username', $data) && filled($data['username'])) {
            $updateData['username'] = strtolower(trim($data['username']));
        }

        // Snapshot user fields before update
        $oldUserState = $record->only(array_keys($updateData));

        if (! empty($data['new_password'])) {
            $updateData['password_hash'] = Hash::make($data['new_password']);
        }

        $record->update($updateData);

        $profileData = array_filter([
            'first_name'    => $data['first_name']    ?? null,
            'last_name'     => $data['last_name']     ?? null,
            'display_name'  => $data['display_name']  ?? null,
            'bio'           => $data['bio']            ?? null,
            'state_code'    => $data['state_code']    ?? null,
            'zip_code'      => $data['zip_code']      ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender'        => $data['gender']        ?? null,
        ], fn ($v) => $v !== null);

        // Veteran detail fields — only written when the veteran toggle is on
        $profileData['veteran_is_active'] = false;
        if (! empty($data['is_veteran'])) {
            $profileData['veteran_is_active'] = (bool) ($data['veteran_is_active'] ?? false);
            $profileData = array_merge($profileData, array_filter([
                'veteran_branch'    => $data['veteran_branch']    ?? null,
                'veteran_last_rank' => $data['veteran_last_rank'] ?? null,
                'veteran_bio'       => $data['veteran_bio']       ?? null,
            ], fn ($v) => $v !== null));
            foreach ($this->parseServiceRange($data['veteran_service_range'] ?? null, 'veteran') as $k => $v) {
                if ($v !== null) $profileData[$k] = $v;
            }
        }

        // First responder detail fields — only written when the first_responder toggle is on
        $profileData['first_responder_is_active'] = false;
        if (! empty($data['is_first_responder'])) {
            $profileData['first_responder_is_active'] = (bool) ($data['first_responder_is_active'] ?? false);
            $profileData = array_merge($profileData, array_filter([
                'first_responder_type'      => $data['first_responder_type']      ?? null,
                'first_responder_last_rank' => $data['first_responder_last_rank'] ?? null,
                'first_responder_bio'       => $data['first_responder_bio']       ?? null,
            ], fn ($v) => $v !== null));
            foreach ($this->parseServiceRange($data['first_responder_service_range'] ?? null, 'first_responder') as $k => $v) {
                if ($v !== null) $profileData[$k] = $v;
            }
        }

        // Snapshot profile fields before update
        $profile = $record->profile;
        $oldProfileState = $profile
            ? array_intersect_key($profile->attributesToArray(), $profileData)
            : [];

        $record->profile()->updateOrCreate(['user_id' => $record->id], $profileData);

        // Build diff — only fields that actually changed
        $allOld = array_merge($oldUserState, $oldProfileState);
        $allNew = array_merge(
            array_intersect_key($updateData, $oldUserState),
            $profileData
        );

        $oldValues = [];
        $newValues = [];
        foreach ($allNew as $field => $newVal) {
            $oldVal = $allOld[$field] ?? null;
            if ((string) $oldVal !== (string) $newVal) {
                $oldValues[$field] = $oldVal;
                $newValues[$field] = $newVal;
            }
        }

        app(AuditService::class)->log(
            eventType:      'update',
            sourceDatabase: 'identity',
            tableName:      'users',
            recordId:       $record->id,
            userId:         Auth::id(),
            ipAddress:      request()->ip(),
            userAgent:      request()->userAgent(),
            actionSummary:  "Platform user updated: {$record->email}",
            changedFields:  array_keys($newValues) ?: null,
            oldValues:      $oldValues ?: null,
            newValues:      $newValues ?: null,
        );

        return $record;
    }

    protected function afterSave(): void
    {
        $data = $this->data;

        // Process avatar upload if a file was provided
        if (! empty($data['avatar_upload'])) {
            $file = $data['avatar_upload'];
            if ($file instanceof TemporaryUploadedFile) {
                try {
                    $document = app(DocumentService::class)->storeUploadedFile(
                        $file->toUploadedFile(),
                        $this->getRecord()->id,
                        'avatar',
                    );

                    $this->getRecord()->profile()->updateOrCreate(
                        ['user_id' => $this->getRecord()->id],
                        ['avatar_document_id' => $document->id],
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Avatar upload failed: ' . $e->getMessage())
                        ->warning()
                        ->send();
                }
            }
        }

        // Save admin note if provided
        if (! empty($data['new_note'])) {
            UserAdminNote::create([
                'user_id'        => $this->getRecord()->id,
                'author_user_id' => Auth::id(),
                'note'           => $data['new_note'],
            ]);

            app(AuditService::class)->log(
                eventType:      'create',
                sourceDatabase: 'identity',
                tableName:      'user_admin_notes',
                recordId:       $this->getRecord()->id,
                userId:         Auth::id(),
                ipAddress:      request()->ip(),
                userAgent:      request()->userAgent(),
                actionSummary:  "Staff note added for user: {$this->getRecord()->email}",
                changedFields:  ['note'],
            );
        }
    }

    // ── Header actions ────────────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\ViewAction::make(),

            Action::make('force_password_reset')
                ->label('Force Password Reset')
                ->icon('heroicon-o-envelope')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function () {
                    $record = $this->getRecord();
                    app(\App\Services\Identity\VerificationService::class)
                        ->sendPasswordResetEmail($record);

                    app(AuditService::class)->log(
                        eventType:      'update',
                        sourceDatabase: 'identity',
                        tableName:      'users',
                        recordId:       $record->id,
                        userId:         Auth::id(),
                        ipAddress:      request()->ip(),
                        userAgent:      request()->userAgent(),
                        actionSummary:  "Admin forced password reset for: {$record->email}",
                        changedFields:  ['password_hash'],
                    );

                    Notification::make()->title('Password reset email sent.')->success()->send();
                }),

            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-no-symbol')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status === 'active')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update(['status' => 'suspended']);

                    app(AuditService::class)->log(
                        eventType:      'update',
                        sourceDatabase: 'identity',
                        tableName:      'users',
                        recordId:       $record->id,
                        userId:         Auth::id(),
                        ipAddress:      request()->ip(),
                        userAgent:      request()->userAgent(),
                        actionSummary:  "User suspended: {$record->email}",
                        changedFields:  ['status'],
                        oldValues:      ['status' => 'active'],
                        newValues:      ['status' => 'suspended'],
                    );

                    $this->refreshFormData(['status']);
                    Notification::make()->title('User suspended.')->warning()->send();
                }),

            Action::make('unsuspend')
                ->label('Unsuspend')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status === 'suspended')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update(['status' => 'active']);

                    app(AuditService::class)->log(
                        eventType:      'update',
                        sourceDatabase: 'identity',
                        tableName:      'users',
                        recordId:       $record->id,
                        userId:         Auth::id(),
                        ipAddress:      request()->ip(),
                        userAgent:      request()->userAgent(),
                        actionSummary:  "User unsuspended: {$record->email}",
                        changedFields:  ['status'],
                        oldValues:      ['status' => 'suspended'],
                        newValues:      ['status' => 'active'],
                    );

                    $this->refreshFormData(['status']);
                    Notification::make()->title('User unsuspended.')->success()->send();
                }),

            Action::make('ban')
                ->label('Ban User')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Banning is permanent until manually reversed. The user will be unable to log in.')
                ->visible(fn () => AdminAuth::isSuperAdmin() && $this->getRecord()->status !== 'banned')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update(['status' => 'banned']);

                    app(AuditService::class)->log(
                        eventType:      'update',
                        sourceDatabase: 'identity',
                        tableName:      'users',
                        recordId:       $record->id,
                        userId:         Auth::id(),
                        ipAddress:      request()->ip(),
                        userAgent:      request()->userAgent(),
                        actionSummary:  "User banned: {$record->email}",
                        changedFields:  ['status'],
                        newValues:      ['status' => 'banned'],
                    );

                    $this->refreshFormData(['status']);
                    Notification::make()->title('User banned.')->danger()->send();
                }),

            ...array_filter($this->standardHeaderActions(), fn ($a) => $a->getName() !== 'view'),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function platformMethodEnabled(string $method): bool
    {
        try {
            return (bool) \Illuminate\Support\Facades\DB::connection('platform')
                ->table('mfa_factor_settings')
                ->where('factor', $method)
                ->value('is_enabled');
        } catch (\Throwable) {
            return false;
        }
    }

    private function mfaMethodEnabled(string $method): bool
    {
        try {
            return $this->getRecord()
                ->mfaConfigurations()
                ->where('method', $method)
                ->where('is_enabled', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function mfaTotpExists(): bool
    {
        try {
            return $this->getRecord()
                ->mfaConfigurations()
                ->where('method', 'totp')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function enableMfaMethod(string $method): void
    {
        $cfg = $this->getRecord()
            ->mfaConfigurations()
            ->firstOrNew(['method' => $method]);
        $cfg->is_enabled  = true;
        $cfg->verified_at = now();
        $cfg->save();
    }

    private function disableMfaMethod(string $method): void
    {
        $this->getRecord()
            ->mfaConfigurations()
            ->where('method', $method)
            ->update(['is_enabled' => false, 'verified_at' => null]);
    }

    private function formatServiceRange(mixed $start, mixed $end): ?string
    {
        $toYear = function (mixed $v): ?int {
            if ($v === null) return null;
            if ($v instanceof \DateTimeInterface) return (int) $v->format('Y');
            if (is_int($v)) return $v;
            return (int) \Illuminate\Support\Carbon::parse((string) $v)->format('Y');
        };

        $s = $toYear($start);
        $e = $toYear($end);

        if ($s && $e) return "{$s} / {$e}";
        if ($s)       return (string) $s;
        return null;
    }

    private function parseServiceRange(?string $range, string $prefix): array
    {
        $toDate = function (?string $part): ?string {
            if ($part === null) return null;
            $part = trim($part);
            if (! ctype_digit($part) || strlen($part) !== 4) return null;
            $y = (int) $part;
            return ($y >= 1940 && $y <= 2100) ? "{$y}-01-01" : null;
        };

        $start = null;
        $end   = null;

        if ($range && str_contains($range, '/')) {
            $parts = array_map('trim', explode('/', $range, 2));
            $start = $toDate($parts[0] ?? null);
            $end   = $toDate($parts[1] ?? null);
        } elseif ($range) {
            $start = $toDate(trim($range));
        }

        return [
            "{$prefix}_service_start" => $start,
            "{$prefix}_service_end"   => $end,
        ];
    }
}
