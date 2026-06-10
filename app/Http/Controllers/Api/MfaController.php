<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Identity\MfaConfiguration;
use App\Services\Auth\MfaService;
use App\Services\Mfa\MfaMethodRegistry;
use App\Services\Mfa\TotpMfaMethod;
use App\Services\Audit\AuditService;
use App\Services\Platform\MfaFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService        $mfaService,
        private readonly MfaMethodRegistry $mfaRegistry,
        private readonly TotpMfaMethod     $totpMethod,
        private readonly MfaFactorService  $mfaFactorService,
        private readonly AuditService      $audit,
    ) {}

    public function list(Request $request): JsonResponse
    {
        $configs = MfaConfiguration::where('user_id', $request->user()->id)
            ->get(['method', 'is_enabled', 'verified_at', 'created_at']);

        return response()->json(['methods' => $configs->toArray()]);
    }

    public function enroll(Request $request, string $method): JsonResponse
    {
        if (! in_array($method, ['totp', 'sms', 'email'])) {
            return response()->json(['message' => 'Unknown MFA method.'], 422);
        }

        if (! $this->mfaFactorService->isFactorEnabled($method)) {
            return response()->json(['message' => 'This MFA method is not currently available.'], 422);
        }

        $user = $request->user();

        if ($method === 'totp') {
            $secret = $this->totpMethod->generateSecret();
            $this->totpMethod->storeSecret($user, $secret);

            return response()->json([
                'method'      => 'totp',
                'secret'      => $secret,
                'qr_code_url' => $this->totpMethod->qrCodeUrl($user->email, $secret),
            ]);
        }

        MfaConfiguration::firstOrCreate(
            ['user_id' => $user->id, 'method' => $method],
            ['is_enabled' => false],
        );

        $this->mfaRegistry->get($method)->triggerChallenge($user, $request->ip());

        $maskedDestination = match ($method) {
            'sms'   => '**** ' . substr($user->phone ?? '', -4),
            'email' => substr($user->email, 0, 1) . '***@' . substr(strrchr($user->email, '@'), 1),
        };

        return response()->json(['method' => $method, 'sent_to' => $maskedDestination]);
    }

    public function confirm(Request $request, string $method): JsonResponse
    {
        if (! in_array($method, ['totp', 'sms', 'email'])) {
            return response()->json(['message' => 'Unknown MFA method.'], 422);
        }

        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $this->mfaRegistry->get($method)->verify($user, $data['code'])) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        // Recovery codes are generated once per account — check by presence
        // in user_recovery_codes, not by whether any factor is currently enabled.
        // This way re-enabling a factor after disabling does not regenerate codes,
        // but a post-admin-reset re-enrollment does.
        $isFirstEnrollment = ! $this->mfaService->hasRecoveryCodes($user);

        MfaConfiguration::where('user_id', $user->id)
            ->where('method', $method)
            ->update(['is_enabled' => true, 'verified_at' => now()]);

        $this->audit->logMfaEnabled($user->id, $method);

        $response = ['verified' => true];

        if ($isFirstEnrollment) {
            $response['recovery_codes'] = $this->mfaService->generateBackupCodes($user);
            $this->audit->logRecoveryCodesGenerated($user->id);
        }

        return response()->json($response);
    }

    public function disable(Request $request, string $method): JsonResponse
    {
        if (! in_array($method, ['totp', 'sms', 'email'])) {
            return response()->json(['message' => 'Unknown MFA method.'], 422);
        }

        MfaConfiguration::where('user_id', $request->user()->id)
            ->where('method', $method)
            ->update(['is_enabled' => false, 'verified_at' => null]);

        $this->audit->logMfaDisabled($request->user()->id, $method);

        return response()->json(['disabled' => true]);
    }

    /**
     * Regenerate recovery codes. Requires proof-of-possession of an enrolled factor.
     * Active PAT alone is insufficient — wrong recovery code is a stronger attack signal.
     */
    public function regenerate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:totp,sms,email'],
            'code'   => ['required', 'string'],
        ]);

        $user = $request->user();

        $enrolled = MfaConfiguration::where('user_id', $user->id)
            ->where('method', $data['method'])
            ->where('is_enabled', true)
            ->exists();

        if (! $enrolled) {
            return response()->json(['message' => 'MFA method not enrolled.'], 422);
        }

        if (! $this->mfaRegistry->get($data['method'])->verify($user, $data['code'])) {
            return response()->json(['message' => 'Invalid MFA code.'], 422);
        }

        $codes = $this->mfaService->generateBackupCodes($user);
        $this->audit->logRecoveryCodesGenerated($user->id);

        return response()->json(['recovery_codes' => $codes]);
    }
}
