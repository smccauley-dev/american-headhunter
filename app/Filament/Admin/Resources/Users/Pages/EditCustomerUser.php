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
use App\Services\Auth\MfaService;
use App\Services\Documents\DocumentService;
use App\Services\Identity\UserService;
use App\Services\Lease\LeaseService;
use App\Services\Platform\MfaFactorService;
use App\Services\Property\PropertyService;
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
                        ->icon('heroicon-o-identification')
                        ->schema([
                            Section::make('Account')
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
                                ]),
                        ]),

                    // ── Roles ─────────────────────────────────────────────────
                    Tab::make('Roles')
                        ->icon('heroicon-o-key')
                        ->schema([
                            Section::make('Platform Roles')
                                ->schema([
                                    CheckboxList::make('roles')
                                        ->hiddenLabel()
                                        ->helperText('Multi-role: controls what the user can do. The Primary Portal on the Identity tab controls where they log in.')
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

                    // ── Contact ───────────────────────────────────────────────
                    Tab::make('Contact')
                        ->icon('heroicon-o-phone')
                        ->schema([
                            Section::make('Primary Contact')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('email')
                                        ->label('Email Address')
                                        ->email()
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(table: 'users', column: 'email', ignoreRecord: true),
                                    TextInput::make('phone')
                                        ->label('Phone')
                                        ->tel()
                                        ->maxLength(20),
                                ]),

                            Section::make('Mailing Address')
                                ->description('Used for tax forms (1099) and mailing legal documents. Encrypted at rest.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('address_line1')
                                        ->label('Street Address')
                                        ->maxLength(255)
                                        ->columnSpanFull(),
                                    TextInput::make('address_line2')
                                        ->label('Apt / Unit / Suite')
                                        ->maxLength(100)
                                        ->columnSpanFull(),
                                    TextInput::make('city')
                                        ->label('City')
                                        ->maxLength(100),
                                    Select::make('state_code')
                                        ->label('State')
                                        ->options(\App\Support\UsStates::names())
                                        ->searchable(),
                                    TextInput::make('county')
                                        ->label('County / Parish / District')
                                        ->maxLength(100),
                                    TextInput::make('zip_code')
                                        ->label('ZIP Code')
                                        ->maxLength(10),
                                ]),

                            Section::make('Emergency Contact')
                                ->description('Who to reach in a field emergency or SOS event. Encrypted at rest.')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('emergency_contact_name')
                                        ->label('Name')
                                        ->maxLength(150),
                                    TextInput::make('emergency_contact_relationship')
                                        ->label('Relationship')
                                        ->maxLength(60)
                                        ->placeholder('e.g. Spouse, Parent'),
                                    TextInput::make('emergency_contact_phone')
                                        ->label('Phone')
                                        ->tel()
                                        ->maxLength(20),
                                    TextInput::make('emergency_contact_email')
                                        ->label('Email')
                                        ->email()
                                        ->maxLength(255),
                                ]),
                        ]),

                    // ── Profile ───────────────────────────────────────────────
                    Tab::make('Profile')
                        ->icon('heroicon-o-user-circle')
                        ->schema([
                            Section::make('Profile Details')
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
                                            'law_enforcement' => 'Law Enforcement',
                                            'fire'            => 'Fire Fighter',
                                            'emt'             => 'EMT / Paramedic',
                                            'search_rescue'   => 'Search & Rescue',
                                            'corrections'     => 'Corrections Officer',
                                            'dispatch'        => 'Dispatcher / 911',
                                            'other'           => 'Other',
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
                        ->icon('heroicon-o-lock-closed')
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
                                        ->helperText('Set once by the user when they first enable a public profile. Used as the URL slug (/hunters/username) and @mention handle. Only Super Administrator may change it.')
                                        ->prefix('@')
                                        ->maxLength(30)
                                        ->regex('/^[a-z][a-z0-9_]{2,29}$/')
                                        ->disabled(fn () => ! AdminAuth::isSuperAdmin())
                                        ->dehydrated(fn () => AdminAuth::isSuperAdmin())
                                        ->unique(table: 'users', column: 'username', ignoreRecord: true)
                                        ->placeholder('not yet set'),
                                ]),

                            Section::make('Password')
                                ->description('User password management')
                                ->headerActions([
                                    Action::make('force_password_reset')
                                        ->label('Force Password Reset')
                                        ->icon('heroicon-o-envelope')
                                        ->color('warning')
                                        ->requiresConfirmation()
                                        ->modalDescription('This will send a password reset email to the user\'s address. They will be required to set a new password before logging in.')
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
                                ])
                                ->schema([
                                    TextInput::make('new_password')
                                        ->label('Set New Password')
                                        ->password()
                                        ->revealable()
                                        ->minLength(10)
                                        ->maxLength(128)
                                        ->helperText('Leave blank to keep the current password. Visible to Super Administrator only.')
                                        ->visible(fn () => AdminAuth::isSuperAdmin()),
                                ]),

                            Section::make('MFA Status')
                                ->description('Multi-factor authentication configuration')
                                ->headerActions($this->mfaFactorActions())
                                ->schema([
                                    Placeholder::make('mfa_status_detail')
                                        ->label('Configured Methods')
                                        ->content(function () {
                                            try {
                                                $configs       = $this->getRecord()->mfaConfigurations()->get()->keyBy('method');
                                                $factorService = app(MfaFactorService::class);

                                                $methods = [];
                                                foreach ([
                                                    'email' => 'Email',
                                                    'totp'  => 'Authenticator App (TOTP)',
                                                    'sms'   => 'SMS / Text Message',
                                                ] as $method => $label) {
                                                    $cfg       = $configs->get($method);
                                                    $methods[] = [
                                                        'label'       => $label,
                                                        'enabled'     => (bool) $cfg?->is_enabled,
                                                        'verified_at' => $cfg?->verified_at,
                                                        'platform_on' => $factorService->isFactorEnabled($method),
                                                    ];
                                                }

                                                return view('filament.admin.users.mfa-status', ['methods' => $methods]);
                                            } catch (\Throwable $e) {
                                                report($e);
                                                return 'Unavailable.';
                                            }
                                        })
                                        ->columnSpanFull(),
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

                                                return view('filament.admin.users.login-history', ['entries' => $entries]);
                                            } catch (\Throwable $e) {
                                                report($e);
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                    // ── Compliance ────────────────────────────────────────────
                    Tab::make('Compliance')
                        ->icon('heroicon-o-shield-check')
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

                                                return view('filament.admin.users.trust-score-events', ['events' => $events]);
                                            } catch (\Throwable $e) {
                                                report($e);
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
                                            } catch (\Throwable $e) {
                                                report($e);
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
                                            } catch (\Throwable $e) {
                                                report($e);
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

                                                return view('filament.admin.users.identity-verifications', ['records' => $records]);
                                            } catch (\Throwable $e) {
                                                report($e);
                                                return 'Unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                    // ── Admin Notes ───────────────────────────────────────────
                    Tab::make('Admin Notes')
                        ->icon('heroicon-o-pencil-square')
                        ->schema([
                            Section::make('Staff Notes')
                                ->schema([
                                    Placeholder::make('admin_notes_list')
                                        ->hiddenLabel()
                                        ->content(function () {
                                            $notes = UserAdminNote::where('user_id', $this->getRecord()->id)
                                                ->orderByDesc('created_at')
                                                ->get();

                                            if ($notes->isEmpty()) {
                                                return 'No staff notes on file.';
                                            }

                                            return view('filament.admin.users.admin-notes', ['notes' => $notes]);
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
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Section::make('Audit Log')
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

                                                return view('filament.admin.users.audit-log', ['events' => $events]);
                                            } catch (\Throwable $e) {
                                                report($e);
                                                return 'Audit log unavailable.';
                                            }
                                        }),
                                ]),
                        ]),

                    // ── Properties & Leases ───────────────────────────────────
                    Tab::make('Properties & Leases')
                        ->icon('heroicon-o-home-modern')
                        ->schema([
                            Section::make('Properties Owned')
                                ->schema([
                                    Placeholder::make('properties_owned')
                                        ->label('')
                                        ->content(function () {
                                            try {
                                                $properties = app(PropertyService::class)
                                                    ->getOwnedPropertySummaries($this->getRecord()->id);

                                                if (empty($properties)) return 'No properties owned.';

                                                return view('filament.admin.users.properties-owned', ['properties' => $properties]);
                                            } catch (\Throwable $e) {
                                                report($e);
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
                                                $grants = app(PropertyService::class)
                                                    ->getManagerGrantSummaries($this->getRecord()->id);

                                                if (empty($grants)) return 'No property management roles.';

                                                return view('filament.admin.users.manager-roles', ['grants' => $grants]);
                                            } catch (\Throwable $e) {
                                                report($e);
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
                                                $leases = app(LeaseService::class)
                                                    ->getLeaseSummariesForUser($this->getRecord()->id);

                                                if (empty($leases)) return 'No leases found.';

                                                return view('filament.admin.users.leases', ['leases' => $leases]);
                                            } catch (\Throwable $e) {
                                                report($e);
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
                                                $clubs = app(LeaseService::class)
                                                    ->getClubAffiliationsForUser($this->getRecord()->id);

                                                if (empty($clubs)) return 'No club memberships.';

                                                return view('filament.admin.users.club-memberships', ['clubs' => $clubs]);
                                            } catch (\Throwable $e) {
                                                report($e);
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
        $data['address_line1'] = $profile?->address_line1 ?? '';
        $data['address_line2'] = $profile?->address_line2 ?? '';
        $data['city']          = $profile?->city          ?? '';
        $data['county']        = $profile?->county        ?? '';
        $data['state_code']   = $profile?->state_code   ?? null;
        $data['zip_code']     = $profile?->zip_code     ?? '';
        $data['emergency_contact_name']         = $profile?->emergency_contact_name         ?? '';
        $data['emergency_contact_relationship'] = $profile?->emergency_contact_relationship ?? '';
        $data['emergency_contact_phone']        = $profile?->emergency_contact_phone        ?? '';
        $data['emergency_contact_email']        = $profile?->emergency_contact_email        ?? '';
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

        // Snapshot user fields before update — password_hash is added after the
        // snapshot so the hash never enters the audit diff below.
        $oldUserState = $record->only(array_keys($updateData));

        if (! empty($data['new_password'])) {
            $updateData['password_hash'] = Hash::make($data['new_password']);
        }

        $record->update($updateData);

        // Nulls are written through so an admin can clear a field; empty
        // strings are normalized to null for enum/date columns.
        $profileData = array_map(fn ($v) => $v === '' ? null : $v, [
            'first_name'    => $data['first_name']    ?? null,
            'last_name'     => $data['last_name']     ?? null,
            'display_name'  => $data['display_name']  ?? null,
            'bio'           => $data['bio']           ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city'          => $data['city']          ?? null,
            'county'        => $data['county']        ?? null,
            'state_code'    => $data['state_code']    ?? null,
            'zip_code'      => $data['zip_code']      ?? null,
            'emergency_contact_name'         => $data['emergency_contact_name']         ?? null,
            'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
            'emergency_contact_phone'        => $data['emergency_contact_phone']        ?? null,
            'emergency_contact_email'        => $data['emergency_contact_email']        ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender'        => $data['gender']        ?? null,
        ]);

        // PII fields encrypted at rest — their values must never reach the audit
        // log. We record only that they changed (field name), never old/new values.
        $encryptedProfileFields = [
            'address_line1', 'address_line2', 'city', 'county',
            'emergency_contact_name', 'emergency_contact_relationship',
            'emergency_contact_phone', 'emergency_contact_email',
        ];

        // Veteran detail fields — only written when the veteran toggle is on
        $profileData['veteran_is_active'] = false;
        if (! empty($data['is_veteran'])) {
            $profileData['veteran_is_active'] = (bool) ($data['veteran_is_active'] ?? false);
            $profileData['veteran_branch']    = $data['veteran_branch']    ?: null;
            $profileData['veteran_last_rank'] = $data['veteran_last_rank'] ?: null;
            $profileData['veteran_bio']       = $data['veteran_bio']       ?: null;
            $profileData = array_merge(
                $profileData,
                $this->parseServiceRange($data['veteran_service_range'] ?? null, 'veteran'),
            );
        }

        // First responder detail fields — only written when the first_responder toggle is on
        $profileData['first_responder_is_active'] = false;
        if (! empty($data['is_first_responder'])) {
            $profileData['first_responder_is_active'] = (bool) ($data['first_responder_is_active'] ?? false);
            $profileData['first_responder_type']      = $data['first_responder_type']      ?: null;
            $profileData['first_responder_last_rank'] = $data['first_responder_last_rank'] ?: null;
            $profileData['first_responder_bio']       = $data['first_responder_bio']       ?: null;
            $profileData = array_merge(
                $profileData,
                $this->parseServiceRange($data['first_responder_service_range'] ?? null, 'first_responder'),
            );
        }

        // Snapshot profile fields before update. Encrypted fields are excluded
        // from the value diff (attributesToArray would expose ciphertext) — we
        // capture their decrypted old values separately, only to detect change.
        $profile = $record->profile;
        $encOld  = [];
        foreach ($encryptedProfileFields as $f) {
            $encOld[$f] = $profile?->{$f}; // decrypted via HasEncryptedFields
        }
        $oldProfileState = $profile
            ? array_diff_key(
                array_intersect_key($profile->attributesToArray(), $profileData),
                array_flip($encryptedProfileFields)
            )
            : [];

        $record->profile()->updateOrCreate(['user_id' => $record->id], $profileData);

        // Build diff — only non-encrypted fields that actually changed
        $allOld = array_merge($oldUserState, $oldProfileState);
        $allNew = array_merge(
            array_intersect_key($updateData, $oldUserState),
            array_diff_key($profileData, array_flip($encryptedProfileFields))
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

        // Record that the password changed without ever logging the hash
        $changedFields = array_keys($newValues);
        if (! empty($data['new_password'])) {
            $changedFields[] = 'password_hash';
        }

        // Encrypted PII: log only the field name when it changed — never the value
        foreach ($encryptedProfileFields as $f) {
            if ((string) ($encOld[$f] ?? null) !== (string) ($profileData[$f] ?? null)) {
                $changedFields[] = $f;
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
            changedFields:  $changedFields ?: null,
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
            // Livewire stores FileUpload state as an array keyed by upload UUID
            $file = is_array($data['avatar_upload'])
                ? reset($data['avatar_upload'])
                : $data['avatar_upload'];
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

    /**
     * Section header actions for the MFA Status section. Enable/disable pairs
     * are generated per factor; all writes delegate to UserService so every
     * change is audited.
     */
    private function mfaFactorActions(): array
    {
        $factors = [
            'email' => [
                'name'        => 'Email MFA',
                'modalName'   => 'Email MFA',
                'enableIcon'  => 'heroicon-o-envelope',
                'disableIcon' => 'heroicon-o-envelope-open',
                'enableDesc'  => 'Admin-activates email-based two-factor authentication for this account.',
                'disableDesc' => 'Removes email-based two-factor authentication. The user can re-enable it from their Security settings.',
            ],
            'totp' => [
                'name'        => 'Authenticator MFA',
                'modalName'   => 'Authenticator App MFA',
                'enableIcon'  => 'heroicon-o-device-phone-mobile',
                'disableIcon' => 'heroicon-o-shield-check',
                'enableDesc'  => 'Admins cannot enroll an authenticator on a user\'s behalf. This only succeeds if the user currently has an enrolled secret on file — after a disable the secret is cleared, so this will fail and the user must re-enroll from their own Security settings. If it does enable, the user will be required to provide an authenticator code at their next login; if they no longer have the app set up they must sign in with a recovery code. Prefer asking the user to set up TOTP themselves.',
                'disableDesc' => 'Removes TOTP two-factor authentication and clears the stored secret. The user will need to re-scan a new QR code to re-enroll.',
            ],
            'sms' => [
                'name'        => 'SMS MFA',
                'modalName'   => 'SMS MFA',
                'enableIcon'  => 'heroicon-o-chat-bubble-left-ellipsis',
                'disableIcon' => 'heroicon-o-chat-bubble-left',
                'enableDesc'  => 'Admin-activates SMS-based two-factor authentication for this account.',
                'disableDesc' => 'Removes SMS-based two-factor authentication for this account.',
            ],
        ];

        $actions = [];

        foreach ($factors as $method => $f) {
            $enable = Action::make("enable_{$method}_mfa")
                ->label("Enable {$f['name']}")
                ->icon($f['enableIcon'])
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading("Enable {$f['modalName']}")
                ->modalDescription($f['enableDesc'])
                ->visible(fn () => AdminAuth::canManageSecurity()
                    && app(MfaFactorService::class)->isFactorEnabled($method)
                    && ! $this->mfaMethodEnabled($method))
                ->action(function () use ($method, $f) {
                    try {
                        app(UserService::class)->enableMfaFactor($this->getRecord(), $method, Auth::id());
                        Notification::make()->success()->title("{$f['name']} enabled")->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                    }
                });

            // TOTP cannot be enrolled by an admin — re-enabling carries a real
            // lockout risk, so flag the confirmation as a warning and make the
            // submit button say what it does rather than a generic "Confirm".
            if ($method === 'totp') {
                $enable->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('warning')
                    ->modalSubmitActionLabel('Enable anyway');
            }

            $actions[] = $enable;

            $actions[] = Action::make("disable_{$method}_mfa")
                ->label("Disable {$f['name']}")
                ->icon($f['disableIcon'])
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading("Disable {$f['modalName']}")
                ->modalDescription($f['disableDesc'])
                ->visible(fn () => AdminAuth::canManageSecurity() && $this->mfaMethodEnabled($method))
                ->action(function () use ($method, $f) {
                    try {
                        app(UserService::class)->disableMfaFactor($this->getRecord(), $method, Auth::id());
                        Notification::make()->success()->title("{$f['name']} disabled")->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                    }
                });
        }

        $actions[] = Action::make('reset_totp_secret')
            ->label('Clear TOTP Token')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Clear Authenticator App Token')
            ->modalDescription('Clears the stored TOTP secret and disables the authenticator method. The user must re-scan a new QR code to re-enroll.')
            ->visible(fn () => AdminAuth::canManageSecurity() && $this->mfaTotpExists())
            ->action(function () {
                try {
                    app(UserService::class)->clearTotpSecret($this->getRecord(), Auth::id());
                    Notification::make()->warning()->title('TOTP secret cleared — user must re-enroll')->send();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });

        $actions[] = Action::make('disable_all_mfa')
            ->label('Disable All MFA')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Disable All MFA Methods')
            ->modalDescription('Emergency: disables every two-factor method, invalidates all recovery codes, and revokes all API tokens. The user must re-enroll on their next login.')
            ->visible(fn () => AdminAuth::isSuperAdmin()
                && app(MfaService::class)->isEnabled($this->getRecord()))
            ->action(function () {
                try {
                    app(UserService::class)->resetMfa($this->getRecord(), Auth::id());
                    Notification::make()->warning()->title('All MFA disabled — recovery codes invalidated, tokens revoked')->send();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });

        return $actions;
    }

    private function mfaMethodEnabled(string $method): bool
    {
        try {
            return $this->getRecord()
                ->mfaConfigurations()
                ->where('method', $method)
                ->where('is_enabled', true)
                ->exists();
        } catch (\Throwable $e) {
            report($e);
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
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
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
