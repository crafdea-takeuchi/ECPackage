<?php

namespace App\Actions\Shipments;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelShipment
{
    public function handle(Shipment $shipment, User $actor, string $reason): Shipment
    {
        return DB::transaction(function () use ($shipment, $actor, $reason): Shipment {
            $current = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'draft') {
                throw ValidationException::withMessages(['status' => '下書きの発送だけ取り消せます。']);
            }
            $current->status = 'cancelled';
            $current->cancelled_by = $actor->id;
            $current->cancelled_at = now();
            $current->cancellation_reason = $reason;
            $current->lock_version++;
            $current->save();

            return $current;
        }, 3);
    }
}
