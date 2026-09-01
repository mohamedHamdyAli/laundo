<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\User\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «ادعُ أصدقاءك — شارك رمز الدعوة الخاص بك مع أصدقائك واحصل على خصومات حصرية لك
 * ولهم».
 *
 * One screen in the account section, and the numbers on it are the only reason a
 * referral programme keeps working: somebody who shares a code and then sees
 * nothing happen shares it once. So this reports both halves — who joined, and
 * who actually ordered — because the gap between them is the whole story of the
 * programme, and hiding it would let a customer think their friend's sign-up had
 * been forgotten rather than that it is waiting for their first order.
 */
class ReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $invited = User::where('referred_by', $user->id)->get(['id', 'referral_rewarded_at']);

        $coupons = Coupon::where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $spent = CouponRedemption::whereIn('coupon_id', $coupons->pluck('id'))
            ->pluck('coupon_id')
            ->all();

        return successReturnData([
            'code' => $user->referral_code,
            // Prewritten so every share reads the same and the code cannot be
            // separated from the instruction by a customer paraphrasing it.
            'share_text' => __(
                'Use my code :code on Laundo and we both get a discount.',
                ['code' => (string) $user->referral_code]
            ),
            'invited' => $invited->count(),
            // The half that pays. «بعد أول طلب مدفوع» is the rule, so a friend
            // who signed up and has not ordered is counted but not yet earned.
            'ordered' => $invited->whereNotNull('referral_rewarded_at')->count(),
            'rewards' => $coupons->map(fn (Coupon $c) => [
                'code' => $c->code,
                'type' => $c->type,
                'value' => (float) $c->value,
                'expires_at' => $c->ends_at?->toDateString(),
                'used' => in_array($c->id, $spent, true),
            ])->values(),
        ]);
    }

    /**
     * What a code is worth before somebody signs up with it.
     *
     * The register screen shows the reward beside the field, and an app that
     * hardcodes «خصم 20%» is an app that lies the day the owner changes it.
     */
    public function terms(): JsonResponse
    {
        $value = (float) (getSettingValue('Referral_Reward_Value') ?? 0);

        return successReturnData([
            'active' => $value > 0,
            'type' => getSettingValue('Referral_Reward_Type') === Coupon::FIXED
                ? Coupon::FIXED
                : Coupon::PERCENTAGE,
            'value' => $value,
            // Stated plainly, because «خصومات حصرية لك ولهم» does not say when.
            'note' => __('Both of you get the discount after your friend pays for their first order.'),
        ]);
    }
}
