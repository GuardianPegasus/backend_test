<?php

use App\Models\Master;
use App\Models\Referral;
use App\Models\ReferralEarning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Текущий мастер приходит в заголовке X-Master-Id и уже разложен
| в атрибуты запроса middleware'ем ResolveCurrentMaster:
|
|     $master = $request->attributes->get('current_master');
|
| ВАЖНО: в ТЗ поля названы «по-человечески», но в базе они так:
|     ref_code            -> masters.referral_code
|     referrals.referrer_id   -> referrals.referrer_master_id
|     referrals.master_id     -> referrals.referred_master_id
| Код ниже написан по фактической схеме, иначе будет no such column.
|
*/

Route::get('/ping', fn () => ['ok' => true]);

/**
 * Текущий мастер из заголовка X-Master-Id.
 *
 * Возвращает Master, либо JsonResponse с ошибкой:
 * 400 — заголовка нет вовсе, 404 — мастера с таким id не существует.
 */
$masterFromHeader = function (Request $request): Master|JsonResponse {
    $header = $request->header('X-Master-Id');

    if (!is_string($header) || trim($header) === '') {
        return response()->json(['message' => 'Заголовок X-Master-Id не передан'], 400);
    }

    // Мастер уже разобран middleware'ем ResolveCurrentMaster.
    $master = $request->attributes->get('current_master');

    if (!$master instanceof Master) {
        return response()->json(['message' => 'Мастер не найден'], 404);
    }

    return $master;
};

Route::prefix('referrals')->group(function () use ($masterFromHeader) {

    /*
    | POST /api/referrals/attach
    | { "code": "..." } — привязать текущего мастера к владельцу кода.
    */
    Route::post('/attach', function (Request $request) use ($masterFromHeader): JsonResponse {
        $current = $masterFromHeader($request);

        if ($current instanceof JsonResponse) {
            return $current;
        }

        // Код владельца; пустой/некорректный код = владелец не найден (404).
        $code = $request->input('code');
        $code = is_string($code) ? trim($code) : '';

        $owner = Master::where('referral_code', $code)->first();

        if ($owner === null) {
            return response()->json(['message' => 'Владелец кода не найден'], 404);
        }

        // Привязать мастера к самому себе нельзя.
        if ($owner->is($current)) {
            return response()->json(['message' => 'Нельзя привязать код самого мастера'], 422);
        }

        // У мастера уже есть привязка к кому-то — вторую не создаём.
        $alreadyAttached = Referral::where('referred_master_id', $current->id)->exists();

        if ($alreadyAttached) {
            return response()->json(['message' => 'Мастер уже привязан к другому коду'], 422);
        }

        $referral = Referral::create([
            'referrer_master_id' => $owner->id,       // владелец кода
            'referred_master_id' => $current->id,     // кто привязался
            'status' => Referral::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Привязка создана',
            'referral' => [
                'referrer_master_id' => $referral->referrer_master_id,
                'referred_master_id' => $referral->referred_master_id,
                'status' => $referral->status,
                'created_at' => $referral->created_at?->toIso8601String(),
            ],
        ], 201);
    });

    /*
    | GET /api/referrals/my — кого привёл текущий мастер.
    */
    Route::get('/my', function (Request $request) use ($masterFromHeader): JsonResponse {
        $current = $masterFromHeader($request);

        if ($current instanceof JsonResponse) {
            return $current;
        }

        $referrals = Referral::where('referrer_master_id', $current->id)
            ->with('referredMaster:id,name')
            ->orderBy('id')
            ->get();

        // Начисления по всем рефералам одним запросом, чтобы не плодить N+1.
        $stats = ReferralEarning::whereIn('referral_id', $referrals->pluck('id'))
            ->selectRaw('referral_id, SUM(amount) as earned, COUNT(*) as entries')
            ->groupBy('referral_id')
            ->get()
            ->keyBy(fn (ReferralEarning $earning): int => (int) $earning->referral_id);

        $items = $referrals->map(function (Referral $referral) use ($stats): array {
            $stat = $stats->get($referral->id);

            // Засчитан, если по рефералу уже статус rewarded (его ставит
            // PaymentObserver после первого денежного платежа)
            // или по нему уже есть начисления в referral_earnings.
            $isQualified = $referral->status === Referral::STATUS_REWARDED
                || (int) ($stat?->entries ?? 0) > 0;

            return [
                'name' => $referral->referredMaster?->name,
                'attached_at' => $referral->created_at?->toIso8601String(),
                'is_qualified' => $isQualified,
                'earned_amount' => (int) ($stat?->earned ?? 0),
            ];
        });

        return response()->json($items->values());
    });

    /*
    | GET /api/referrals/earnings — сводка по деньгам текущего мастера.
    */
    Route::get('/earnings', function (Request $request) use ($masterFromHeader): JsonResponse {
        $current = $masterFromHeader($request);

        if ($current instanceof JsonResponse) {
            return $current;
        }

        $earnings = ReferralEarning::where('referrer_master_id', $current->id);

        $total = (int) (clone $earnings)->sum('amount');
        $pending = (int) (clone $earnings)
            ->where('status', ReferralEarning::STATUS_PENDING)
            ->sum('amount');
        $paid = (int) (clone $earnings)
            ->where('status', ReferralEarning::STATUS_PAID)
            ->sum('amount');

        // Сколько приведённых уже засчитано (status = rewarded ставит PaymentObserver).
        $qualifiedCount = Referral::where('referrer_master_id', $current->id)
            ->where('status', Referral::STATUS_REWARDED)
            ->count();

        return response()->json([
            'total_earned' => $total,
            'pending' => $pending,
            'paid' => $paid,
            'qualified_count' => $qualifiedCount,
        ]);
    });
});
