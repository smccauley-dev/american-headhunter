<?php

namespace App\Providers\Filament;

use Filament\Actions\Action;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Base fallback — applies to every Action instance including custom Action::make() calls.
        // Typed subclasses (EditAction, DeleteAction, etc.) override per their own configureUsing below.
        Action::configureUsing(fn (Action $a) => $a
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedCheckCircle))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        CreateAction::configureUsing(fn (CreateAction $a) => $a
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedPlus))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        EditAction::configureUsing(fn (EditAction $a) => $a
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedCheckCircle))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ViewAction::configureUsing(fn (ViewAction $a) => $a
            ->icon(Heroicon::OutlinedEye)
            ->color('gray'));

        DeleteAction::configureUsing(fn (DeleteAction $a) => $a
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedTrash))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ForceDeleteAction::configureUsing(fn (ForceDeleteAction $a) => $a
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedTrash))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        RestoreAction::configureUsing(fn (RestoreAction $a) => $a
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedArrowPath))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ReplicateAction::configureUsing(fn (ReplicateAction $a) => $a
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedDocumentDuplicate))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ExportAction::configureUsing(fn (ExportAction $a) => $a
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedArrowDownTray))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ImportAction::configureUsing(fn (ImportAction $a) => $a
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedArrowUpTray))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        AssociateAction::configureUsing(fn (AssociateAction $a) => $a
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedLink))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        AttachAction::configureUsing(fn (AttachAction $a) => $a
            ->icon(Heroicon::OutlinedPaperClip)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedPaperClip))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        DetachAction::configureUsing(fn (DetachAction $a) => $a
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('warning')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedMinusCircle))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        DissociateAction::configureUsing(fn (DissociateAction $a) => $a
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('warning')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedMinusCircle))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        DeleteBulkAction::configureUsing(fn (DeleteBulkAction $a) => $a
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedTrash))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ForceDeleteBulkAction::configureUsing(fn (ForceDeleteBulkAction $a) => $a
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedTrash))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        RestoreBulkAction::configureUsing(fn (RestoreBulkAction $a) => $a
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedArrowPath))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        DetachBulkAction::configureUsing(fn (DetachBulkAction $a) => $a
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('warning')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedMinusCircle))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        DissociateBulkAction::configureUsing(fn (DissociateBulkAction $a) => $a
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('warning')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedMinusCircle))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));

        ExportBulkAction::configureUsing(fn (ExportBulkAction $a) => $a
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalSubmitAction(fn (Action $action) => $action->icon(Heroicon::OutlinedArrowDownTray))
            ->modalCancelAction(fn (Action $action) => $action->icon(Heroicon::OutlinedXMark)));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Admin\Pages\Auth\Login::class)
            ->colors([
                'primary' => Color::hex('#0a1512'),
                'danger'  => Color::hex('#c84c21'),
                'warning' => Color::hex('#b8934a'),
                'success' => Color::hex('#6b7856'),
            ])
            ->brandName('American Headhunter')
            ->brandLogo(new HtmlString('
                <div class="ah-admin-logo">
                    <div class="ah-admin-mark-wrap">
                        <div class="ah-admin-mark">
                            <span class="ah-admin-mark-letters">AH</span>
                        </div>
                    </div>
                    <div class="ah-admin-brand-name">American Headhunter</div>
                    <div class="ah-admin-brand-sub">Admin · Field Operations</div>
                </div>
            '))
            ->brandLogoHeight('auto')
            ->darkMode(false)
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString($this->loginHeadContent()),
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_END,
                fn (): HtmlString => new HtmlString(
                    '<div class="ah-login-footer">30.88° N · 100.47° W · Est. 2025</div>'
                ),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => $this->loginNoticeHtml(),
            )
            ->discoverResources(
                in: app_path('Filament/Admin/Resources'),
                for: 'App\\Filament\\Admin\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Admin/Pages'),
                for: 'App\\Filament\\Admin\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Admin/Widgets'),
                for: 'App\\Filament\\Admin\\Widgets',
            )
            ->widgets([
                AccountWidget::class,
            ])
            ->navigationGroups([
                'Marketplace',
                'Users & Access',
                'Pricing & Promotions',
                'Billing',
                'Communications',
                'Safety & Compliance',
                'System',
            ])
            ->middleware([
                // SEC-043: trusted staff CRUD spans all users and cannot run
                // under per-user RLS, so the admin panel uses the ah_system role.
                // This MUST run first — before AuthenticateSession resolves the
                // guard user. The panel never sets an RLS context, so resolving
                // the user as the non-owner ah_runtime would return zero rows
                // (RLS deny), leaving the guard unable to confirm the logged-in
                // staff member and bouncing /admin <-> /admin/login forever.
                \App\Http\Middleware\UseSystemDatabaseRole::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\EnsureAdminIpAllowed::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function loginNoticeHtml(): HtmlString
    {
        try {
            $t       = app(\App\Services\Platform\TenantService::class);
            $message = $t->getSetting('login.unauthorized_message',   'This system is for authorized users only. Unauthorized access or use is prohibited and may result in criminal or civil penalties.');
            $link1L  = $t->getSetting('login.policy_label',           'Authorized Use Policy');
            $link1U  = $t->getSetting('login.policy_url',             '/authorized-use-policy');
            $link2L  = $t->getSetting('login.security_policy_label',  'Security Policy');
            $link2U  = $t->getSetting('login.security_policy_url',    '/security-policy');
        } catch (\Throwable) {
            return new HtmlString('');
        }

        if (! $message && ! $link1U && ! $link2U) {
            return new HtmlString('');
        }

        $msgHtml   = $message
            ? '<p class="ah-login-notice-text">' . e($message) . '</p>'
            : '';

        $safeUrl = static function (string $url): string {
            return preg_match('/^(\/|https?:\/\/)/i', $url) ? $url : '';
        };
        $link1U = $safeUrl($link1U);
        $link2U = $safeUrl($link2U);

        $linksHtml = '';
        if ($link1U || $link2U) {
            $a1 = $link1U ? '<a href="' . e($link1U) . '" class="ah-login-notice-link" target="_blank" rel="noopener">' . e($link1L ?: $link1U) . '</a>' : '';
            $a2 = $link2U ? '<a href="' . e($link2U) . '" class="ah-login-notice-link" target="_blank" rel="noopener">' . e($link2L ?: $link2U) . '</a>' : '';
            $linksHtml = '<div class="ah-login-notice-links">' . $a1 . $a2 . '</div>';
        }

        return new HtmlString(
            '<div class="ah-login-notice">' . $msgHtml . $linksHtml . '</div>'
        );
    }

    private function loginHeadContent(): string
    {
        return <<<'HTML'
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;1,9..144,500&family=JetBrains+Mono:wght@300;400;500;600&family=Crimson+Pro:ital,wght@0,300;0,400;1,300;1,400&display=swap" rel="stylesheet">
        <style>
        /* ── American Headhunter Admin Theme ─────────────────────────────── */

        /* === FULL PAGE BACKGROUND === */
        /* Login layout: dark forest. Main admin panel: parchment. */
        .fi-simple-layout,
        .fi-simple-main-ctn {
            background-color: #0a1512 !important;
        }
        .fi-simple-main-ctn {
            min-height: 100vh !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 40px 16px !important;
        }
        body.fi-body:not(:has(.fi-simple-layout)) {
            background-color: #f4ecdc !important;
        }

        /* ============================================================
           ADMIN MAIN LAYOUT — Parchment / Ink Theme
           ============================================================ */

        /* ── Topbar ─────────────────────────────────────────────────── */
        .fi-topbar-ctn {
            background-color: #e8dcc4 !important;
            border-bottom: 1px solid #a89874 !important;
            box-shadow: none !important;
        }
        .fi-topbar {
            background-color: #e8dcc4 !important;
        }
        .fi-topbar .fi-icon-btn {
            color: rgba(10, 21, 18, 0.5) !important;
        }
        .fi-topbar .fi-icon-btn:hover {
            color: #0a1512 !important;
            background-color: rgba(10, 21, 18, 0.06) !important;
        }
        /* Global search field — square parchment, matches themed form inputs */
        .fi-global-search-field,
        .fi-global-search-field .fi-input-wrp {
            border-radius: 0 !important;
            background-color: #faf7f2 !important;
            border: 1px solid #c9b896 !important;
            box-shadow: none !important;
            --tw-ring-shadow: none !important;
        }
        .fi-global-search-field input {
            background-color: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            color: #0a1512 !important;
        }
        .fi-global-search-field input::placeholder {
            color: rgba(10, 21, 18, 0.35) !important;
        }
        .fi-global-search-field svg,
        .fi-global-search-field .fi-icon {
            color: rgba(10, 21, 18, 0.35) !important;
        }

        /* ── Sidebar — dark ink, contrasts with parchment content ───── */
        .fi-sidebar,
        .fi-sidebar-header-ctn,
        .fi-sidebar-header,
        .fi-sidebar-nav {
            background-color: #0a1512 !important;
        }
        .fi-sidebar {
            border-right: 1px solid rgba(184, 147, 74, 0.2) !important;
        }
        .fi-sidebar-header {
            border-bottom: 1px solid rgba(184, 147, 74, 0.15) !important;
        }

        /* ── Nav groups & items ──────────────────────────────────────── */
        .fi-sidebar-group-label {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            /* 0.14em (trimmed from 0.18em) to keep group labels on one line in
               the fixed-width sidebar now that JetBrains Mono is wider. */
            letter-spacing: 0.14em !important;
            text-transform: uppercase !important;
            color: #b8934a !important;
            font-weight: 400 !important;
        }
        .fi-sidebar-item-btn {
            color: #f4ecdc !important;
            border-radius: 0 !important;
            transition: background-color 0.12s ease, color 0.12s ease !important;
        }
        .fi-sidebar-item-btn span,
        .fi-sidebar-item-btn-label,
        .fi-sidebar-item-label {
            color: #f4ecdc !important;
        }
        .fi-sidebar-item-btn svg {
            color: #c84c21 !important;
        }
        .fi-sidebar-item-btn:hover {
            background-color: rgba(244, 236, 220, 0.07) !important;
            color: #f4ecdc !important;
        }
        .fi-sidebar-item-btn:hover span,
        .fi-sidebar-item-btn:hover .fi-sidebar-item-btn-label,
        .fi-sidebar-item-btn:hover .fi-sidebar-item-label {
            color: #f4ecdc !important;
        }
        .fi-sidebar-item-btn:hover svg {
            color: #c84c21 !important;
        }
        .fi-sidebar-item.fi-active .fi-sidebar-item-btn {
            background-color: rgba(244, 236, 220, 0.08) !important;
            color: #f4ecdc !important;
            border-left: 2px solid #c84c21 !important;
        }
        .fi-sidebar-item.fi-active .fi-sidebar-item-btn span,
        .fi-sidebar-item.fi-active .fi-sidebar-item-btn-label,
        .fi-sidebar-item.fi-active .fi-sidebar-item-label {
            color: #f4ecdc !important;
        }
        .fi-sidebar-item.fi-active .fi-sidebar-item-btn svg {
            color: #c84c21 !important;
        }
        .fi-sidebar-group-collapse-btn {
            color: #b8934a !important;
        }
        .fi-sidebar-group-collapse-btn svg {
            color: #b8934a !important;
        }

        /* ── Brand mark — sidebar: parchment mark on dark bg ─────────── */
        .ah-admin-mark {
            background: #e8dcc4 !important;
            border-color: #e8dcc4 !important;
        }
        .ah-admin-mark-wrap::before,
        .ah-admin-mark-wrap::after {
            border-color: #e8dcc4 !important;
        }
        .ah-admin-mark-letters {
            color: #0a1512 !important;
        }
        .ah-admin-brand-name {
            color: #e8dcc4 !important;
        }
        .ah-admin-brand-sub {
            color: rgba(232, 220, 196, 0.45) !important;
        }

        /* ── Brand mark — topbar: ink mark on parchment bg ───────────── */
        /* Higher specificity (2 classes) beats sidebar rules (1 class) above */
        .fi-topbar-start .ah-admin-mark {
            background: #0a1512 !important;
            border-color: #0a1512 !important;
        }
        .fi-topbar-start .ah-admin-mark-wrap::before,
        .fi-topbar-start .ah-admin-mark-wrap::after {
            border-color: #0a1512 !important;
        }
        .fi-topbar-start .ah-admin-mark-letters {
            color: #e8dcc4 !important;
        }
        .fi-topbar-start .ah-admin-brand-name {
            color: #0a1512 !important;
        }
        .fi-topbar-start .ah-admin-brand-sub {
            color: rgba(10, 21, 18, 0.45) !important;
        }

        /* ── Main content area ───────────────────────────────────────── */
        .fi-main,
        .fi-main-ctn {
            background-color: #e8dcc4 !important;
        }

        /* ── Page header (title + breadcrumbs) ───────────────────────── */
        .fi-header {
            background-color: #e8dcc4 !important;
            border-bottom: 1px solid rgba(168, 152, 116, 0.6) !important;
            padding-top: 24px !important;
            padding-bottom: 19px !important;
        }
        .fi-header-heading {
            font-family: 'Fraunces', Georgia, serif !important;
            color: #0a1512 !important;
            font-weight: 500 !important;
        }
        .fi-breadcrumbs-item-label {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            letter-spacing: 0.1em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.5) !important;
        }
        .fi-breadcrumbs-separator-icon {
            color: rgba(10, 21, 18, 0.3) !important;
        }

        /* Sections inside a fi-ta-ctn (e.g. IP Allowlist toolbar box) — no card border */
        .fi-ta-ctn .fi-section {
            border: none !important;
            box-shadow: none !important;
            background: transparent !important;
        }
        .fi-ta-ctn .fi-section::before {
            display: none !important;
        }

        /* ── Sections / Cards — Field Record style ───────────────────── */
        .fi-section {
            background-color: #f4ecdc !important;
            border-radius: 0 !important;
            border: 1px solid #0a1512 !important;
            box-shadow: 8px 8px 0 #0a1512 !important;
            position: relative !important;
        }
        /* Inner dashed border (inset 8px, exact match to field-card::before) */
        .fi-section::before {
            content: '' !important;
            position: absolute !important;
            top: 8px !important; left: 8px !important;
            right: 8px !important; bottom: 8px !important;
            border: 1px dashed #a89874 !important;
            pointer-events: none !important;
            z-index: 0 !important;
        }
        .fi-section-header,
        .fi-section-content-ctn,
        .fi-section-content,
        .fi-section-footer {
            position: relative !important;
            z-index: 1 !important;
        }
        .fi-section:not(.fi-section-not-contained):not(.fi-aside) > .fi-section-header,
        .fi-section-header {
            background-color: transparent !important;
            border-bottom: none !important;
            padding-block: 1.25rem !important;
            /* Inset divider line using gradient — matches 24px section padding-inline */
            background-image: linear-gradient(#a89874, #a89874) !important;
            background-position: center bottom !important;
            background-size: calc(100% - 48px) 1px !important;
            background-repeat: no-repeat !important;
        }
        /* Also kill Filament's own full-width border-top on the content container */
        .fi-section.fi-section-has-header:not(.fi-collapsed) > .fi-section-content-ctn {
            border-top: none !important;
            border-top-width: 0 !important;
        }
        /* Section heading — "FIELD RECORD" label style (mono, small, uppercase) */
        .fi-section-header-heading {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 13px !important;
            font-weight: 400 !important;
            letter-spacing: 0.15em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.7) !important;
        }
        .fi-section-header-description {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            letter-spacing: 0.08em !important;
            color: rgba(10, 21, 18, 0.4) !important;
        }
        .fi-section-content {
            background-color: transparent !important;
        }

        /* ── Description field — Crimson Pro body serif, sage tone ─────── */
        .ah-description-entry .fi-in-text-entry,
        .ah-description-entry .fi-in-entry-content,
        .ah-description-entry .fi-in-entry-content p,
        .ah-description-entry .fi-in-entry-content-ctn {
            font-family: 'Crimson Pro', Georgia, serif !important;
            font-size: 16px !important;
            font-weight: 300 !important;
            line-height: 1.65 !important;
            color: #6b7856 !important;
        }

        /* ── Field rows — dotted dividers (field-row pattern) ────────── */
        .fi-in-entry {
            border-bottom: 1px dotted #a89874 !important;
            padding-top: 10px !important;
            padding-bottom: 10px !important;
        }
        .fi-in-entry:last-child,
        .fi-in-entry:last-of-type {
            border-bottom: none !important;
        }

        /* ── Infolist entries ────────────────────────────────────────── */
        .fi-in-entry-label {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 13px !important;
            letter-spacing: 0.12em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.7) !important;
            font-weight: 400 !important;
            margin-bottom: 4px !important;
        }
        .fi-in-text-entry,
        .fi-in-text-entry-content,
        .fi-in-entry-content-ctn,
        .fi-in-entry-content-ctn p,
        .fi-in-entry-content-ctn span {
            color: #0a1512 !important;
            font-size: 14px !important;
        }
        /* Page header title */
        .fi-header .fi-header-heading,
        h1.fi-header-heading {
            color: #0a1512 !important;
        }
        /* All text in main content */
        .fi-main p,
        .fi-main span:not(.fi-badge-label),
        .fi-main td,
        .fi-main th {
            color: #0a1512 !important;
        }
        /* Badge entries (status) */
        .fi-badge {
            border-radius: 0 !important;
        }

        /* ── Fix: allow pages to scroll naturally without double-height ghost space ── */
        /* fi-main{height:100%} resolves to 100dvh via fi-layout→body min-height     */
        /* chain. Content overflows that fixed height with overflow:visible, while    */
        /* Filament's layout elements still occupy viewport height — creating a       */
        /* scroll area = content + viewport (double). Setting auto breaks the chain.  */
        .fi-main { height: auto !important; }
        /* Ensure the sidebar still stretches full-height via fi-layout */
        .fi-layout { min-height: 100dvh !important; height: auto !important; }
        /* fi-page-content is display:grid — grid stretches items to track height,
           giving the form a definite height. fi-sc-actions{height:100%} inside the
           form then resolves to the full form height but sits AFTER the schema in
           block flow, overflowing past the form bottom by ~form height and doubling
           the body scroll area. Auto restores natural stack height. */
        .fi-sc-actions { height: auto !important; }

        /* ── Table container — field card treatment ─────────────────── */
        .fi-ta-ctn {
            background-color: #f4ecdc !important;
            border-radius: 0 !important;
            border: 1px solid #0a1512 !important;
            box-shadow: 8px 8px 0 #0a1512 !important;
            --tw-shadow: none !important;
            --tw-ring-shadow: none !important;
            position: relative !important;
            overflow: hidden !important;
        }
        .fi-ta-ctn::before {
            content: '' !important;
            position: absolute !important;
            top: 8px !important; left: 8px !important;
            right: 8px !important; bottom: 8px !important;
            border: 1px dashed #a89874 !important;
            pointer-events: none !important;
            z-index: 1 !important;
        }
        /* Ensure table content sits above the ::before dashed border */
        .fi-ta-ctn > * {
            position: relative !important;
            z-index: 2 !important;
        }
        /* Inset toolbar and table content to align with the dashed border */
        .fi-ta-header-toolbar,
        .fi-ta-content-ctn,
        .fi-ta-footer-ctn {
            margin-left: 8px !important;
            margin-right: 8px !important;
        }

        /* Table rows — parchment background, #a89874 dividers */
        .fi-ta-record {
            background-color: #f4ecdc !important;
        }
        .fi-ta-row:hover .fi-ta-record {
            background-color: rgba(10, 21, 18, 0.04) !important;
        }
        .fi-ta-content-ctn > :not(:last-child) {
            border-color: #a89874 !important;
        }

        /* Toolbar — transparent on parchment */
        .fi-ta-header-toolbar {
            background-color: transparent !important;
            border-color: #a89874 !important;
            margin-top: 8px !important;
        }

        /* Column header row */
        .fi-ta-content-ctn .fi-ta-content-header {
            background-color: rgba(10, 21, 18, 0.04) !important;
            border-color: #a89874 !important;
        }

        /* Strip elements (reorder, selection, filter indicators) */
        .fi-ta-reorder-indicator,
        .fi-ta-selection-indicator,
        .fi-ta-filter-indicators,
        .fi-ta-group-header {
            background-color: rgba(10, 21, 18, 0.04) !important;
            border-color: #a89874 !important;
        }

        /* ── Pagination — sandwich layout: [PREVIOUS] [1] [2] [3] [NEXT] ─ */
        .fi-ta-footer-ctn,
        .fi-ta-footer {
            background-color: transparent !important;
            border-color: #a89874 !important;
        }
        .fi-ta-footer td,
        .fi-ta-footer th {
            border: none !important;
        }
        /* Remove divide-x separators from footer rows */
        .fi-ta-footer-ctn * {
            border-left: none !important;
            border-right: none !important;
        }
        /* Override fi-ta-footer-ctn * for pagination buttons that need borders */
        .fi-pagination .fi-pagination-previous-btn,
        .fi-pagination .fi-pagination-next-btn {
            border-left: 1px solid rgba(10, 21, 18, 0.15) !important;
            border-right: 1px solid rgba(10, 21, 18, 0.15) !important;
        }
        /* Switch from 3-col grid to flex row */
        .fi-pagination {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 0.375rem !important;
        }
        /* PREVIOUS on left (order 1) */
        .fi-pagination .fi-pagination-previous-btn {
            display: inline-flex !important;
            order: 1 !important;
            flex-shrink: 0 !important;
            border-radius: 0 !important;
            border: 1px solid rgba(10, 21, 18, 0.15) !important;
            color: rgba(10, 21, 18, 0.55) !important;
            background-color: transparent !important;
        }
        .fi-pagination .fi-pagination-previous-btn:hover {
            background-color: rgba(10, 21, 18, 0.06) !important;
            color: #0a1512 !important;
        }
        /* Page number list — always visible, no surrounding box */
        .fi-pagination .fi-pagination-items {
            display: flex !important;
            order: 2 !important;
            align-items: center !important;
            gap: 0.25rem !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        /* NEXT on right (order 3) */
        .fi-pagination .fi-pagination-next-btn {
            display: inline-flex !important;
            order: 3 !important;
            flex-shrink: 0 !important;
            border-radius: 0 !important;
            border: 1px solid rgba(10, 21, 18, 0.15) !important;
            color: rgba(10, 21, 18, 0.55) !important;
            background-color: transparent !important;
        }
        .fi-pagination .fi-pagination-next-btn:hover {
            background-color: rgba(10, 21, 18, 0.06) !important;
            color: #0a1512 !important;
        }
        /* Per-page select — pushed to far right */
        .fi-pagination .fi-pagination-records-per-page-select-ctn {
            order: 4 !important;
            margin-left: auto !important;
        }
        /* Overview text (e.g. "1–10 of 25") — hidden */
        .fi-pagination .fi-pagination-overview {
            display: none !important;
        }
        /* Hide icon-only prev/next chevrons inside the items list — text buttons handle nav */
        .fi-pagination-item[rel="prev"],
        .fi-pagination-item[rel="next"],
        .fi-pagination-item[rel="first"],
        .fi-pagination-item[rel="last"] {
            display: none !important;
        }
        /* Page number buttons — match global button height and font */
        .fi-pagination-item-btn {
            border-radius: 0 !important;
            height: 36px !important;
            min-width: 32px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 0.5rem !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            letter-spacing: 0.12em !important;
            color: rgba(10, 21, 18, 0.55) !important;
            background-color: transparent !important;
            border: 1px solid transparent !important;
        }
        .fi-pagination-item-btn:hover {
            background-color: rgba(10, 21, 18, 0.06) !important;
            color: #0a1512 !important;
        }
        /* Active page — terracotta fill (matches active sidebar/tab convention) */
        .fi-pagination-item.fi-active .fi-pagination-item-btn,
        .fi-pagination-item-btn[aria-current="page"],
        .fi-pagination-item-btn[aria-current="true"] {
            background-color: #c84c21 !important;
            color: #f4ecdc !important;
            border-color: #c84c21 !important;
        }
        /* Per-page select widget */
        .fi-pagination-records-per-page-select {
            border-radius: 0 !important;
            border: none !important;
            background-color: transparent !important;
            color: #0a1512 !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            box-shadow: none !important;
        }
        /* Remove prefix divider border inside the per-page select input wrapper */
        .fi-pagination-records-per-page-select-ctn .fi-input-wrp,
        .fi-pagination-records-per-page-select-ctn .fi-input-wrp-prefix,
        .fi-pagination-records-per-page-select-ctn .fi-input-wrp-content-ctn {
            border: none !important;
            box-shadow: none !important;
        }

        /* ── Main panel forms — parchment theme ─────────────────────── */

        /* Field labels */
        .fi-main .fi-fo-field-label-content {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 13px !important;
            letter-spacing: 0.12em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.7) !important;
            font-weight: 400 !important;
        }
        .fi-main .fi-fo-field-wrp-error-message {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
        }
        /* Helper / hint text */
        .fi-main .fi-fo-field-wrp > p,
        .fi-main [class*="fi-fo-"][class*="helper"],
        .fi-main .fi-hint {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            color: rgba(10, 21, 18, 0.4) !important;
            letter-spacing: 0.05em !important;
        }

        /* Text inputs & textareas */
        .fi-main .fi-input-wrp {
            border-radius: 0 !important;
            border: 1px solid #c9b896 !important;
            background-color: #faf7f2 !important;
            box-shadow: none !important;
        }
        .fi-main .fi-input-wrp:focus-within {
            border-color: #a89874 !important;
            box-shadow: none !important;
        }
        .fi-main input.fi-input,
        .fi-main textarea.fi-input {
            background-color: transparent !important;
            color: #0a1512 !important;
            font-size: 14px !important;
        }
        .fi-main input.fi-input::placeholder,
        .fi-main textarea.fi-input::placeholder {
            color: rgba(10, 21, 18, 0.3) !important;
        }

        /* Select */
        .fi-main .fi-fo-select-wrp .fi-input-wrp,
        .fi-main .fi-select-input {
            background-color: #faf7f2 !important;
            color: #0a1512 !important;
        }

        /* Searchable Select dropdown — match the native TYPE select: white body,
           squared corners, thin border, no soft/offset shadow, no row dividers. */
        .fi-select-input .fi-dropdown-panel,
        .fi-select-input-options-ctn {
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        .fi-select-input .fi-dropdown-panel {
            background-color: #ffffff !important;
            border: 1px solid #0a1512 !important;
            overflow: hidden !important;
        }
        .fi-select-input-search-ctn,
        .fi-select-input-search-ctn input {
            background-color: #ffffff !important;
        }
        .fi-select-input-options-ctn > *,
        .fi-select-input-option-group > * {
            border-top-color: transparent !important;
        }

        /* Group headings (HUNTER / LANDOWNER / CLUB) — dark ink band, bold label */
        .fi-select-input-option-group,
        .fi-select-input-option-group > .fi-dropdown-header {
            background-color: #0a1512 !important;
            border: none !important;
            box-shadow: none !important;
        }
        .fi-select-input-option-group > .fi-dropdown-header {
            color: #c9b896 !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            font-weight: 700 !important;
            letter-spacing: 0.14em !important;
            text-transform: uppercase !important;
        }

        /* Options — squared, normal weight, grey+white highlight like the native select */
        .fi-select-input-option,
        .fi-select-input-option .fi-select-input-option-label {
            font-weight: 400 !important;
            border-radius: 0 !important;
        }
        .fi-select-input-option:hover,
        .fi-select-input-option[aria-selected='true'] {
            background-color: #808080 !important;
        }
        .fi-select-input-option:hover,
        .fi-select-input-option:hover .fi-select-input-option-label,
        .fi-select-input-option[aria-selected='true'],
        .fi-select-input-option[aria-selected='true'] .fi-select-input-option-label {
            color: #ffffff !important;
        }

        /* File upload — match the member frontend's branded parchment FilePond skin.
           Filament's own FilePond draws a white .filepond--panel-root *inside* the
           .fi-fo-file-upload-input-ctn wrapper, so styling the wrapper alone leaves
           a white box. Neutralise the wrapper and skin the panel itself, mirroring
           resources/css/app.css exactly, so the admin and member uploaders are
           pixel-identical: squared, paper drop zone, dashed parch border, mono
           labels, blaze "Browse". */
        .fi-main .fi-fo-file-upload-input-ctn,
        .fi-modal .fi-fo-file-upload-input-ctn {
            border-radius: 0 !important;
            border: none !important;
            background-color: transparent !important;
        }
        /* Filament's compiled .filepond--root is rounded (border-radius:var(--radius-lg))
           AND overflow:hidden, so it CLIPS the square panel into rounded corners.
           Squaring the root is the actual fix — squaring the panel alone is not enough. */
        .filepond--root { font-family: 'JetBrains Mono', Menlo, monospace !important; margin-bottom: 0 !important; border-radius: 0 !important; }
        .filepond--root .filepond--panel-root {
            border-radius: 0 !important;
            background-color: #faf7f2 !important;
            border: 1px dashed #a89874 !important;
        }
        /* FilePond paints the panel as stacked sub-layers; Filament's bundled
           FilePond CSS rounds those, so square every layer (not just panel-root). */
        .filepond--root .filepond--panel,
        .filepond--root .filepond--panel-top,
        .filepond--root .filepond--panel-center,
        .filepond--root .filepond--panel-bottom { border-radius: 0 !important; }
        .filepond--root:hover .filepond--panel-root {
            border-color: #0a1512 !important;
            background-color: rgba(10, 21, 18, 0.03) !important;
        }
        .filepond--root .filepond--drop-label {
            color: rgba(10, 21, 18, 0.55) !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            letter-spacing: 0.04em !important;
        }
        .filepond--root .filepond--label-action {
            color: #c84c21 !important;
            text-decoration-color: #c84c21 !important;
            text-underline-offset: 2px !important;
        }
        .filepond--root .filepond--item-panel { border-radius: 0 !important; background-color: #1c302a !important; }
        .filepond--root .filepond--image-preview,
        .filepond--root .filepond--image-preview-wrapper,
        .filepond--root .filepond--image-preview-overlay { border-radius: 0 !important; }
        .filepond--root .filepond--file-info-main { font-family: 'JetBrains Mono', Menlo, monospace !important; font-size: 11px !important; }
        .filepond--root .filepond--file-info-sub { font-family: 'JetBrains Mono', Menlo, monospace !important; font-size: 9px !important; }
        .filepond--root .filepond--drip-blob { background-color: #a89874 !important; }

        /* Toggle — sage when on (modal scope too, for the upload modals' EXIF toggle) */
        .fi-main .fi-toggle:checked,
        .fi-modal .fi-toggle:checked,
        .fi-main input[type="checkbox"]:checked ~ .fi-toggle,
        .fi-modal input[type="checkbox"]:checked ~ .fi-toggle {
            background-color: #6b7856 !important;
        }

        /* Repeater items */
        .fi-main .fi-fo-repeater-item {
            background-color: #faf7f2 !important;
            border-radius: 0 !important;
            border: 1px solid #c9b896 !important;
        }
        .fi-main .fi-fo-repeater-item-header {
            background-color: transparent !important;
            border-bottom: 1px solid #a89874 !important;
        }
        .fi-main .fi-fo-repeater-item-header-label {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            letter-spacing: 0.12em !important;
            color: rgba(10, 21, 18, 0.6) !important;
        }

        /* ── Modal window — parchment / field-card treatment ────────── */
        .fi-modal > .fi-modal-window-ctn > .fi-modal-window {
            background-color: #f4ecdc !important;
            border-radius: 0 !important;
            border: 1px solid #0a1512 !important;
            box-shadow: 8px 8px 0 #0a1512 !important;
        }
        .fi-modal-header {
            background-color: transparent !important;
            border-bottom: 1px solid #a89874 !important;
        }
        .fi-modal-heading {
            font-family: 'Fraunces', Georgia, serif !important;
            color: #0a1512 !important;
            font-weight: 500 !important;
        }
        .fi-modal-footer {
            background-color: transparent !important;
            border-top: 1px solid #a89874 !important;
        }
        /* Full DOM path + every likely container to guarantee padding above buttons */
        .fi-modal > .fi-modal-window-ctn > .fi-modal-window > .fi-modal-footer,
        .fi-modal-window > .fi-modal-footer,
        .fi-modal-footer,
        .fi-modal-footer-actions {
            padding-top: 0.625rem !important;
        }
        /* Modal close button */
        .fi-modal-close-btn {
            color: rgba(10, 21, 18, 0.4) !important;
        }
        .fi-modal-close-btn:hover {
            color: #0a1512 !important;
        }
        /* Modal form labels */
        .fi-modal .fi-fo-field-label-content {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            letter-spacing: 0.12em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.5) !important;
        }
        /* Modal form inputs */
        .fi-modal .fi-input-wrp {
            border-radius: 0 !important;
            border: 1px solid #c9b896 !important;
            background-color: #faf7f2 !important;
            box-shadow: none !important;
        }
        .fi-modal input.fi-input,
        .fi-modal textarea.fi-input {
            background-color: transparent !important;
            color: #0a1512 !important;
        }

        /* ── Relation manager tabs ───────────────────────────────────── */
        /* .fi-sc-tabs is the outer container (the white pill) */
        .fi-sc-tabs {
            background-color: transparent !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            border: none !important;
            overflow: visible !important;
        }
        /* .fi-tabs is the <nav> inside it */
        .fi-tabs {
            background-color: transparent !important;
            border-radius: 0 !important;
            border: none !important;
            border-bottom: 1px solid #a89874 !important;
            padding: 0 !important;
            gap: 0 !important;
            box-shadow: none !important;
            overflow: visible !important;
        }
        .fi-tabs-item {
            background-color: transparent !important;
            border-radius: 0 !important;
            color: rgba(10, 21, 18, 0.45) !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            /* 0.08em (trimmed from 0.12em): JetBrains Mono is wider than the
               former Instrument Mono; the .fi-tabs nav is overflow:visible so
               tabs wrap rather than scroll. Tighter tracking recovers width. */
            letter-spacing: 0.08em !important;
            text-transform: uppercase !important;
            border-bottom: 2px solid transparent !important;
            padding-bottom: 10px !important;
            margin-bottom: -1px !important;
            box-shadow: none !important;
        }
        .fi-tabs-item.fi-active {
            color: #0a1512 !important;
            border-bottom-color: #c84c21 !important;
            background-color: transparent !important;
        }
        .fi-tabs-item:hover {
            background-color: transparent !important;
            color: rgba(10, 21, 18, 0.7) !important;
        }
        .fi-tabs-item-label {
            color: inherit !important;
        }

        /* ── Relation manager — allow shadow to overflow ─────────────── */
        .fi-resource-relation-manager {
            overflow: visible !important;
        }

        /* ── Icon buttons — global (square, muted ink) ───────────────── */
        .fi-icon-btn {
            border-radius: 0 !important;
            color: rgba(10, 21, 18, 0.45) !important;
            background-color: transparent !important;
        }
        .fi-icon-btn:hover {
            background-color: rgba(10, 21, 18, 0.07) !important;
            color: #0a1512 !important;
        }

        /* ── Section collapse button ─────────────────────────────────── */
        .fi-section-collapse-btn {
            border-radius: 0 !important;
            color: rgba(10, 21, 18, 0.4) !important;
        }
        .fi-section-collapse-btn:hover {
            background-color: rgba(10, 21, 18, 0.06) !important;
        }

        /* ── Table column manager & filter dropdown panels ───────────── */
        .fi-ta-col-manager-modal,
        .fi-ta-filters-modal {
            background-color: #f4ecdc !important;
            border-radius: 0 !important;
            border: 1px solid #0a1512 !important;
            box-shadow: 8px 8px 0 #0a1512 !important;
        }
        .fi-ta-col-manager-heading,
        .fi-ta-filters-heading {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 10px !important;
            letter-spacing: 0.15em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.5) !important;
        }
        .fi-ta-col-manager-item {
            border-bottom: 1px dotted #a89874 !important;
            background-color: transparent !important;
        }
        .fi-ta-col-manager-label {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            color: #0a1512 !important;
        }

        /* ── Dropdown lists (user menu, nav dropdowns, search results) ── */
        .fi-dropdown-list {
            background-color: #f4ecdc !important;
            border-radius: 0 !important;
            border: 1px solid #0a1512 !important;
            box-shadow: 8px 8px 0 #0a1512 !important;
        }
        .fi-dropdown-list-item-label {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            color: #0a1512 !important;
        }

        /* ── All buttons — base reset (applies to every fi-btn variant) ── */
        .fi-btn {
            border-radius: 0 !important;
            box-shadow: none !important;
            height: 36px !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0.875rem !important;
            padding-right: 0.875rem !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.5rem !important;
            white-space: nowrap !important;
            line-height: 1 !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            letter-spacing: 0.12em !important;
            text-transform: uppercase !important;
        }
        .fi-btn svg {
            width: 14px !important;
            height: 14px !important;
            flex-shrink: 0 !important;
        }

        /* ── Ghost style — any button that is not a filled variant ──────── */
        /* Uses :not() so View, Cancel, and any unnamed secondary variants   */
        /* are caught regardless of whether fi-color-gray is present         */
        .fi-btn:not(.fi-color-primary):not(.fi-color-danger):not(.fi-color-success):not(.fi-color-warning) {
            background-color: #fafafa !important;
            color: rgba(10, 21, 18, 0.65) !important;
            border: 1px solid rgba(10, 21, 18, 0.2) !important;
        }
        .fi-btn:not(.fi-color-primary):not(.fi-color-danger):not(.fi-color-success):not(.fi-color-warning) > span,
        .fi-btn:not(.fi-color-primary):not(.fi-color-danger):not(.fi-color-success):not(.fi-color-warning) .fi-btn-label {
            color: rgba(10, 21, 18, 0.65) !important;
        }
        .fi-btn:not(.fi-color-primary):not(.fi-color-danger):not(.fi-color-success):not(.fi-color-warning):hover {
            background-color: rgba(10, 21, 18, 0.06) !important;
            color: #0a1512 !important;
        }

        /* ── Buttons — primary (ink) everywhere ─────────────────────── */
        .fi-btn.fi-color-primary {
            --text: #e8dcc4 !important;
            background-color: #0a1512 !important;
            color: #e8dcc4 !important;
            border: none !important;
        }
        .fi-btn.fi-color-primary > span,
        .fi-btn.fi-color-primary .fi-btn-label {
            color: #e8dcc4 !important;
        }
        .fi-btn.fi-color-primary:hover {
            background-color: #1c302a !important;
        }

        /* ── Danger buttons — Delete (global) ───────────────────────── */
        .fi-btn.fi-color-danger {
            background-color: #c84c21 !important;
            color: #f4ecdc !important;
            border-radius: 0 !important;
            border: none !important;
            box-shadow: none !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            letter-spacing: 0.12em !important;
            text-transform: uppercase !important;
        }
        .fi-btn.fi-color-danger > span,
        .fi-btn.fi-color-danger .fi-btn-label {
            color: #f4ecdc !important;
        }
        .fi-btn.fi-color-danger:hover {
            background-color: #8a3216 !important;
        }

        /* === CARD (parchment, square corners, corner marks) === */
        .fi-simple-main {
            background: #faf7f2 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            border: none !important;
            padding: 40px !important;
            max-width: 440px !important;
            width: 100% !important;
            position: relative !important;
        }
        .fi-simple-main::before,
        .fi-simple-main::after {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            border: 1.5px solid #0a1512;
            pointer-events: none;
        }
        .fi-simple-main::before { top: 12px; left: 12px; border-right: none; border-bottom: none; }
        .fi-simple-main::after  { bottom: 12px; right: 12px; border-left: none; border-top: none; }

        /* === HEADER / LOGO AREA === */
        .fi-simple-header {
            text-align: center !important;
            align-items: center !important;
            margin-bottom: 28px !important;
            padding-bottom: 24px !important;
            border-bottom: 1px solid rgba(10, 21, 18, 0.1) !important;
            gap: 8px !important;
        }
        .fi-simple-header-heading {
            font-family: 'Fraunces', Georgia, serif !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            font-style: italic !important;
            color: rgba(10, 21, 18, 0.4) !important;
            letter-spacing: 0.01em !important;
            margin-top: 0 !important;
        }
        .fi-logo,
        .fi-sidebar-header,
        .fi-sidebar-header > a,
        .fi-sidebar-header > div {
            overflow: visible !important;
        }
        .fi-logo {
            display: flex !important;
            justify-content: center !important;
            height: auto !important;
        }

        /* === AH MARK (inside brandLogo) — structure shared by sidebar + login === */
        .ah-admin-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 9px 0 4px;
            overflow: visible;
        }
        /* Wrapper carries the corner brackets — positioned within its own bounds, no overflow needed */
        .ah-admin-mark-wrap {
            position: relative;
            padding: 5px;
            display: inline-flex;
        }
        .ah-admin-mark-wrap::before,
        .ah-admin-mark-wrap::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            pointer-events: none;
        }
        .ah-admin-mark-wrap::before {
            top: 0;
            left: 0;
            border-top: 1.5px solid;
            border-left: 1.5px solid;
        }
        .ah-admin-mark-wrap::after {
            bottom: 0;
            right: 0;
            border-bottom: 1.5px solid;
            border-right: 1.5px solid;
        }
        .ah-admin-mark {
            width: 52px;
            height: 52px;
            border: 1.5px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .ah-admin-mark-letters {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 20px;
            font-weight: 600;
            letter-spacing: -0.02em;
            line-height: 1;
        }
        .ah-admin-brand-name {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 22px;
            font-weight: 500;
            letter-spacing: -0.02em;
            line-height: 1;
        }
        .ah-admin-brand-sub {
            font-family: 'JetBrains Mono', Menlo, monospace;
            font-size: 9px;
            letter-spacing: 0.22em;
            text-transform: uppercase;
        }
        /* Login page logo — color overrides (sidebar !important rules handle the sidebar) */
        .fi-simple-main .ah-admin-mark {
            border-color: #0a1512 !important;
            background: #0a1512 !important;
        }
        .fi-simple-main .ah-admin-mark-wrap::before,
        .fi-simple-main .ah-admin-mark-wrap::after {
            border-color: #0a1512 !important;
        }
        .fi-simple-main .ah-admin-mark-letters {
            font-family: 'Fraunces', Georgia, serif !important;
            font-size: 20px !important;
            font-weight: 600 !important;
            color: #e8dcc4 !important;
            letter-spacing: -0.02em !important;
            line-height: 1 !important;
        }
        .fi-simple-main .ah-admin-brand-name {
            font-family: 'Fraunces', Georgia, serif !important;
            font-size: 22px !important;
            font-weight: 500 !important;
            color: #0a1512 !important;
            letter-spacing: -0.02em !important;
            line-height: 1 !important;
        }
        .fi-simple-main .ah-admin-brand-sub {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 9px !important;
            letter-spacing: 0.22em !important;
            color: #6b7856 !important;
            text-transform: uppercase !important;
        }

        /* === LOGIN PAGE SCOPED STYLES (fi-simple-main = login card only) === */

        /* FORM LABELS — Filament uses .fi-fo-field-label-content for the text node */
        .fi-simple-main .fi-fo-field-label-content {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 13px !important;
            letter-spacing: 0.12em !important;
            text-transform: uppercase !important;
            color: rgba(10, 21, 18, 0.7) !important;
            font-weight: 400 !important;
        }
        .fi-simple-main .fi-fo-field-label-required-mark {
            color: #c84c21 !important;
        }

        /* FORM INPUTS — match main admin parchment field style */
        .fi-simple-main .fi-input-wrp {
            border-radius: 0 !important;
            background-color: #faf7f2 !important;
            box-shadow: none !important;
            border: 1px solid #c9b896 !important;
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch !important;
        }
        .fi-simple-main .fi-input-wrp:focus-within {
            border-color: #a89874 !important;
            box-shadow: none !important;
        }
        .fi-simple-main input.fi-input {
            background-color: transparent !important;
            color: #0a1512 !important;
            font-size: 14px !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            -webkit-text-fill-color: #0a1512 !important;
        }
        .fi-simple-main input.fi-input::placeholder {
            color: rgba(10, 21, 18, 0.3) !important;
            -webkit-text-fill-color: rgba(10, 21, 18, 0.3) !important;
        }
        /* Prevent browser autofill from flashing blue */
        .fi-simple-main input.fi-input:-webkit-autofill,
        .fi-simple-main input.fi-input:-webkit-autofill:hover,
        .fi-simple-main input.fi-input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #faf7f2 inset !important;
            -webkit-text-fill-color: #0a1512 !important;
        }

        /* PASSWORD REVEAL ICON — suppress native browser toggle */
        .fi-simple-main input[type="password"]::-ms-reveal,
        .fi-simple-main input[type="password"]::-ms-clear {
            display: none !important;
        }
        .fi-simple-main .fi-input-wrp-suffix {
            border-left: none !important;
            border-inline-start: none !important;
            border-right: none !important;
            border-top: none !important;
            border-bottom: none !important;
            padding-inline-start: 4px !important;
            padding-inline-end: 8px !important;
            display: flex !important;
            align-items: center !important;
        }
        .fi-simple-main .fi-icon-btn {
            color: rgba(10, 21, 18, 0.4) !important;
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            outline: none !important;
        }
        .fi-simple-main .fi-icon-btn:hover {
            color: #0a1512 !important;
            background: transparent !important;
        }

        /* SUBMIT BUTTON */
        /* Override --text CSS var at source so color: var(--text) reads cream regardless of specificity */
        .fi-simple-main .fi-btn {
            --text: #faf7f2 !important;
            --bg: #0a1512 !important;
        }
        .fi-simple-main .fi-btn.fi-color-primary,
        .fi-simple-main .fi-btn.fi-ac-btn-action,
        .fi-simple-main button[type="submit"].fi-btn {
            background-color: #0a1512 !important;
            color: #faf7f2 !important;
            border-radius: 0 !important;
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
            letter-spacing: 0.15em !important;
            text-transform: uppercase !important;
            border: none !important;
            width: 100% !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            box-shadow: none !important;
            height: 44px !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            line-height: 1 !important;
            transition: background-color 0.2s ease !important;
        }
        /* Keep button ink-colored and same size during loading/disabled state */
        .fi-simple-main .fi-btn.fi-color-primary:disabled,
        .fi-simple-main .fi-btn.fi-ac-btn-action:disabled,
        .fi-simple-main button[type="submit"].fi-btn:disabled {
            background-color: #0a1512 !important;
            opacity: 1 !important;
        }
        /* Constrain spinner SVG to font-size so it can't push the button taller */
        .fi-simple-main button[type="submit"].fi-btn svg,
        .fi-simple-main .fi-btn.fi-color-primary svg,
        .fi-simple-main .fi-btn.fi-color-primary * svg,
        .fi-simple-main button[type="submit"].fi-btn * svg {
            width: 11px !important;
            height: 11px !important;
            flex-shrink: 0 !important;
        }
        .fi-simple-main .fi-btn.fi-color-primary > span,
        .fi-simple-main .fi-btn.fi-ac-btn-action > span,
        .fi-simple-main button[type="submit"].fi-btn > span {
            color: #faf7f2 !important;
        }
        .fi-simple-main .fi-btn.fi-color-primary:hover,
        .fi-simple-main .fi-btn.fi-ac-btn-action:hover,
        .fi-simple-main button[type="submit"].fi-btn:hover {
            background-color: #c84c21 !important;
            --bg: #c84c21 !important;
        }
        .fi-simple-main .fi-btn.fi-color-primary:hover > span,
        .fi-simple-main .fi-btn.fi-ac-btn-action:hover > span,
        .fi-simple-main button[type="submit"].fi-btn:hover > span {
            color: #faf7f2 !important;
        }
        .fi-simple-main .fi-btn.fi-color-primary:focus,
        .fi-simple-main .fi-btn.fi-ac-btn-action:focus,
        .fi-simple-main button[type="submit"].fi-btn:focus {
            --tw-ring-color: rgba(10, 21, 18, 0.25) !important;
            outline: none !important;
        }

        /* VALIDATION ERRORS */
        .fi-simple-main .fi-fo-field-wrp-error-message {
            font-family: 'JetBrains Mono', Menlo, monospace !important;
            font-size: 11px !important;
        }

        /* === UNAUTHORIZED USE NOTICE === */
        .ah-login-notice {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid rgba(10, 21, 18, 0.1);
        }
        .ah-login-notice-text {
            font-family: 'JetBrains Mono', Menlo, monospace;
            font-size: 10px;
            line-height: 1.6;
            color: rgba(10, 21, 18, 0.45);
            letter-spacing: 0.03em;
            margin: 0 0 10px;
            text-align: center;
        }
        .ah-login-notice-links {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .ah-login-notice-link {
            font-family: 'JetBrains Mono', Menlo, monospace;
            font-size: 10px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #6b7856;
            text-decoration: none;
        }
        .ah-login-notice-link:hover {
            color: #0a1512;
            text-decoration: underline;
        }

        /* ── Modal footer buttons — min-width only (height/display from global) ─ */
        .fi-modal-footer .fi-btn {
            min-width: 80px !important;
        }

        /* ── Form page action buttons (Create/Edit footer, schema actions) ─── */
        .fi-sc-actions .fi-btn {
            min-width: 80px !important;
        }
        /* Align the actions row itself to the start and give breathing room */
        .fi-sc-actions .fi-ac {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 0.5rem !important;
        }

        /* === COORDINATES FOOTER === */
        .ah-login-footer {
            text-align: center;
            padding: 20px 0 0;
            font-family: 'JetBrains Mono', Menlo, monospace;
            font-size: 10px;
            letter-spacing: 0.2em;
            color: rgba(250, 247, 242, 0.2);
            text-transform: uppercase;
            pointer-events: none;
        }

        </style>
        HTML;
    }
}
