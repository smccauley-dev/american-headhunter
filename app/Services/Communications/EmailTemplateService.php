<?php

namespace App\Services\Communications;

use App\DTOs\RenderedEmail;
use App\Models\Communications\EmailTemplate;
use App\Models\Communications\EmailTemplateVersion;
use App\Services\BaseService;
use App\Services\Platform\TenantService;
use App\Support\EmailTemplateVariables;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmailTemplateService extends BaseService
{
    public function __construct(
        private readonly TenantService $tenantService,
    ) {}

    // ─── Rendering ───────────────────────────────────────────────────────────────

    /**
     * Render the active version of a template with the given variables.
     * Returns null when no active version exists — callers fall back to
     * their hardcoded Blade view so email never silently stops sending.
     *
     * Values are HTML-escaped in the HTML body and inserted raw in the
     * text body. Array values become multi-line blocks (<br> in HTML).
     */
    public function render(string $templateKey, array $variables = []): ?RenderedEmail
    {
        $active = $this->getActiveVersion($templateKey);

        if ($active === null) {
            return null;
        }

        $variables = array_merge($this->globalVariables(), $variables);

        return new RenderedEmail(
            subject: $this->substitute($active['subject'], $variables, escapeHtml: false),
            html:    $active['html_body'] !== null ? $this->substitute($active['html_body'], $variables, escapeHtml: true) : null,
            text:    $active['text_body'] !== null ? $this->substitute($active['text_body'], $variables, escapeHtml: false) : null,
        );
    }

    /** Render arbitrary version content with sample data (admin preview). */
    public function preview(string $templateKey, string $subject, ?string $htmlBody, ?string $textBody): RenderedEmail
    {
        $samples = array_merge(
            $this->globalVariables(),
            EmailTemplateVariables::samplesFor($templateKey),
        );

        return new RenderedEmail(
            subject: $this->substitute($subject, $samples, escapeHtml: false),
            html:    $htmlBody !== null ? $this->substitute($htmlBody, $samples, escapeHtml: true) : null,
            text:    $textBody !== null ? $this->substitute($textBody, $samples, escapeHtml: false) : null,
        );
    }

    private function substitute(string $content, array $variables, bool $escapeHtml): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                $value = $escapeHtml
                    ? implode('<br>', array_map(fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES), $value))
                    : implode("\n", array_map('strval', $value));
            } elseif ($escapeHtml) {
                $value = htmlspecialchars((string) $value, ENT_QUOTES);
            }

            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($content, $replacements);
    }

    private function globalVariables(): array
    {
        return [
            'platform_name' => $this->tenantService->platformName(),
            'support_email' => $this->tenantService->supportEmail(),
            'app_url'       => config('app.url'),
            'current_year'  => date('Y'),
        ];
    }

    /** Cached active version content for a template key. */
    private function getActiveVersion(string $templateKey): ?array
    {
        return $this->cache("email_template:{$templateKey}", function () use ($templateKey) {
            $version = EmailTemplateVersion::query()
                ->where('status', 'active')
                ->whereHas('template', fn ($q) => $q
                    ->where('template_key', $templateKey)
                    ->whereNull('deleted_at'))
                ->first();

            return $version?->only(['subject', 'html_body', 'text_body']);
        }, ttlMinutes: 30);
    }

    // ─── Reads ───────────────────────────────────────────────────────────────────

    public function getTemplates(): Collection
    {
        return EmailTemplate::whereNull('deleted_at')
            ->with('activeVersion')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    // ─── Template writes ─────────────────────────────────────────────────────────

    public function createTemplate(string $templateKey, string $name, string $category = 'custom'): EmailTemplate
    {
        if (! preg_match('/^[a-z0-9_.]+$/', $templateKey)) {
            throw new \InvalidArgumentException('Template key may only contain lowercase letters, numbers, dots and underscores.');
        }

        $exists = EmailTemplate::where('template_key', $templateKey)->whereNull('deleted_at')->exists();
        if ($exists) {
            throw new \InvalidArgumentException("A template with key '{$templateKey}' already exists.");
        }

        return EmailTemplate::create([
            'template_key' => $templateKey,
            'name'         => $name,
            'category'     => $category,
        ]);
    }

    /** Soft-delete a custom template. System templates cannot be deleted. */
    public function deleteTemplate(string $templateId): void
    {
        $template = EmailTemplate::whereNull('deleted_at')->findOrFail($templateId);

        if ($template->isSystem()) {
            throw new \InvalidArgumentException('System templates cannot be deleted — they are wired to application code.');
        }

        $template->update(['deleted_at' => now()]);
        $this->invalidate("email_template:{$template->template_key}");
    }

    // ─── Version writes ──────────────────────────────────────────────────────────

    /** Create a new draft version with the next version number. */
    public function createDraft(
        string $templateId,
        string $subject,
        ?string $htmlBody,
        ?string $textBody,
        ?string $notes = null,
        ?string $createdByUserId = null,
    ): EmailTemplateVersion {
        if (($htmlBody === null || $htmlBody === '') && ($textBody === null || $textBody === '')) {
            throw new \InvalidArgumentException('A template version needs an HTML body, a text body, or both.');
        }

        EmailTemplate::whereNull('deleted_at')->findOrFail($templateId);

        $nextNumber = (int) EmailTemplateVersion::where('template_id', $templateId)->max('version_number') + 1;

        return EmailTemplateVersion::create([
            'template_id'        => $templateId,
            'version_number'     => $nextNumber,
            'subject'            => $subject,
            'html_body'          => $htmlBody !== '' ? $htmlBody : null,
            'text_body'          => $textBody !== '' ? $textBody : null,
            'status'             => 'draft',
            'notes'              => $notes !== '' ? $notes : null,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /** Update a draft version in place. Active/archived versions are immutable. */
    public function updateDraft(
        string $versionId,
        string $subject,
        ?string $htmlBody,
        ?string $textBody,
        ?string $notes = null,
    ): void {
        $version = EmailTemplateVersion::findOrFail($versionId);

        if ($version->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft versions can be edited. Duplicate this version to make changes.');
        }

        if (($htmlBody === null || $htmlBody === '') && ($textBody === null || $textBody === '')) {
            throw new \InvalidArgumentException('A template version needs an HTML body, a text body, or both.');
        }

        $version->update([
            'subject'   => $subject,
            'html_body' => $htmlBody !== '' ? $htmlBody : null,
            'text_body' => $textBody !== '' ? $textBody : null,
            'notes'     => $notes !== '' ? $notes : null,
        ]);
    }

    /** Make a version the active one; the previous active version is archived. */
    public function activateVersion(string $versionId): void
    {
        $version  = EmailTemplateVersion::findOrFail($versionId);
        $template = $version->template;

        DB::connection('communications')->transaction(function () use ($version, $template): void {
            EmailTemplateVersion::where('template_id', $template->id)
                ->where('status', 'active')
                ->where('id', '!=', $version->id)
                ->update(['status' => 'archived']);

            $version->update(['status' => 'active']);
        });

        $this->invalidate("email_template:{$template->template_key}");
    }

    /** Copy any version into a new editable draft. */
    public function duplicateAsDraft(string $versionId, ?string $createdByUserId = null): EmailTemplateVersion
    {
        $source = EmailTemplateVersion::findOrFail($versionId);

        return $this->createDraft(
            $source->template_id,
            $source->subject,
            $source->html_body,
            $source->text_body,
            "Copy of version {$source->version_number}",
            $createdByUserId,
        );
    }

    /** Delete a draft version. Active and archived versions are kept for history. */
    public function deleteDraft(string $versionId): void
    {
        $version = EmailTemplateVersion::findOrFail($versionId);

        if ($version->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft versions can be deleted.');
        }

        $version->delete();
    }
}
