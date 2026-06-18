<?php

namespace App\Filament\Admin\Concerns;

/**
 * Converts membership-plan price fields between the integer cents stored in
 * DB 12 and the dollar values shown in the form. Used by the MembershipPlan
 * Create and Edit pages so admins enter dollars while the column stays cents.
 */
trait ConvertsPlanPrices
{
    /** @var string[] */
    private array $planPriceFields = ['monthly_price_cents', 'annual_price_cents'];

    protected function centsToDollars(array $data): array
    {
        foreach ($this->planPriceFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = $data[$field] / 100;
            }
        }

        return $data;
    }

    protected function dollarsToCents(array $data): array
    {
        foreach ($this->planPriceFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) round($data[$field] * 100);
            }
        }

        return $data;
    }
}
