<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\ServiceVerifications\Concerns\BuildsVerificationQueue;
use App\Models\Identity\VeteranVerification;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Veteran review queue, rendered as a section of the consolidated
 * User Verification page (see UserVerifications). The column set, filters
 * and approve/reject/view-proof actions come from the shared queue builder.
 */
class VeteranVerificationsTable extends TableWidget
{
    use BuildsVerificationQueue;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return static::configureQueueTable(
            $table->query(VeteranVerification::query()->with('user.profile')),
        )->heading('Veteran Verifications');
    }
}
