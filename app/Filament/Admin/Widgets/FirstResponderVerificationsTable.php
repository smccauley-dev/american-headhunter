<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\ServiceVerifications\Concerns\BuildsVerificationQueue;
use App\Models\Identity\FirstResponderVerification;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * First-responder review queue, rendered as a section of the consolidated
 * User Verification page (see UserVerifications). Shares the same queue
 * builder as the veteran section — only the model and heading differ.
 */
class FirstResponderVerificationsTable extends TableWidget
{
    use BuildsVerificationQueue;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return static::configureQueueTable(
            $table->query(FirstResponderVerification::query()->with('user.profile')),
        )->heading('First Responder Verifications');
    }
}
