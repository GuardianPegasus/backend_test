<?php

namespace App\Services\Referral;

use App\Models\Master;
use App\Models\Referral;

class ReferralService
{
    public function registerReferral(Master $referred, string $code): ?Referral
    {
        $referrer = Master::where('referral_code', $code)->first();

        if (empty($referrer) || $referrer->id === $referred->id) {
            return null;
        }

        return Referral::firstOrCreate(
            [
                'referred_master_id' => $referred->id,
            ],
            [
                'referrer_master_id' => $referrer->id,
                'program' => Referral::PROGRAM_MASTER_INVITE,
                'status' => Referral::STATUS_PENDING,
            ]
        );
    }

    public function rewardAmount(int $paymentAmount): int
    {
        $percent = (int) config('referral.percent');

        return (int) round($paymentAmount * $percent);
    }
}
