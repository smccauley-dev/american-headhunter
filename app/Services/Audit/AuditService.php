<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditService
{
    /**
     * Write an audit event. This method NEVER throws — failures are logged
     * to the application log and the calling transaction continues.
     */
    public function log(
        string $eventType,
        string $sourceDatabase,
        string $tableName,
        string $recordId,
        ?string $userId = null,
        ?string $sessionId = null,
        ?string $actionSummary = null,
        ?array $changedFields = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        try {
            DB::connection('audit')->table('audit_log')->insert([
                'id'              => (string) Str::uuid(),
                'event_type'      => $eventType,
                'source_database' => $sourceDatabase,
                'table_name'      => $tableName,
                'record_id'       => $recordId,
                'user_id'         => $userId,
                'session_id'      => $sessionId,
                'action_summary'  => $actionSummary,
                'changed_fields'  => $changedFields ? json_encode($changedFields) : null,
                'old_values'      => $oldValues ? json_encode($this->sanitize($oldValues)) : null,
                'new_values'      => $newValues ? json_encode($this->sanitize($newValues)) : null,
                'ip_address'      => $ipAddress,
                'user_agent'      => $userAgent,
                'occurred_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditService: failed to write audit log', [
                'event_type'  => $eventType,
                'table_name'  => $tableName,
                'record_id'   => $recordId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience wrappers for common event types.
     */
    public function logLogin(string $userId, string $ipAddress, string $userAgent, bool $success): void
    {
        $this->log(
            eventType:     $success ? 'login_success' : 'login_failed',
            sourceDatabase: 'ah_identity',
            tableName:     'login_history',
            recordId:      $userId,
            userId:        $userId,
            actionSummary: $success ? 'Successful login' : 'Failed login attempt',
            ipAddress:     $ipAddress,
            userAgent:     $userAgent,
        );
    }

    public function logPasswordChanged(string $userId, string $ipAddress): void
    {
        $this->log(
            eventType:     'password_changed',
            sourceDatabase: 'ah_identity',
            tableName:     'users',
            recordId:      $userId,
            userId:        $userId,
            actionSummary: 'Password changed',
            ipAddress:     $ipAddress,
        );
    }

    public function logAccountCreated(string $userId, string $accountType): void
    {
        $this->log(
            eventType:     'account_created',
            sourceDatabase: 'ah_identity',
            tableName:     'users',
            recordId:      $userId,
            userId:        $userId,
            actionSummary: "Account created — type: {$accountType}",
        );
    }

    public function logMfaEnabled(string $userId, string $method): void
    {
        $this->log(
            eventType:     'mfa_enabled',
            sourceDatabase: 'ah_identity',
            tableName:     'mfa_configurations',
            recordId:      $userId,
            userId:        $userId,
            actionSummary: "MFA enabled — method: {$method}",
        );
    }

    public function logMfaDisabled(string $userId, string $method): void
    {
        $this->log(
            eventType:      'mfa_disabled',
            sourceDatabase: 'ah_identity',
            tableName:      'mfa_configurations',
            recordId:       $userId,
            userId:         $userId,
            actionSummary:  "MFA disabled \u2014 method: {$method}",
        );
    }

    public function logRecoveryCodesGenerated(string $userId): void
    {
        $this->log(
            eventType:      'mfa_recovery_codes_generated',
            sourceDatabase: 'ah_identity',
            tableName:      'user_recovery_codes',
            recordId:       $userId,
            userId:         $userId,
            actionSummary:  'MFA recovery codes generated',
        );
    }

    public function logRecoveryCodeUsed(string $userId, string $ipAddress): void
    {
        $this->log(
            eventType:      'mfa_recovery_code_used',
            sourceDatabase: 'ah_identity',
            tableName:      'user_recovery_codes',
            recordId:       $userId,
            userId:         $userId,
            actionSummary:  'MFA recovery code used for authentication',
            ipAddress:      $ipAddress,
        );
    }

    /**
     * Strip fields that must never appear in audit logs,
     * even in the old/new values columns.
     */
    private function sanitize(array $values): array
    {
        $sensitive = [
            'password_hash',
            'secret_encrypted',
            'code_hash',          // mfa_challenge.code_hash, user_recovery_codes.code_hash
            'access_token_encrypted',
            'refresh_token_encrypted',
            'raw_result_encrypted',
            'match_details_encrypted',
        ];

        return array_diff_key($values, array_flip($sensitive));
    }
}
