<?php

namespace Database\Seeders;

use App\Modules\Setting\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * «عن لاندو» · «الشروط والأحكام» · «سياسة الخصوصية».
 *
 * These three settings held Latin filler from `SettingsSeeder` and were served
 * verbatim to both apps and, once the landing page shipped, to the public web.
 * They are long-form HTML written by the dashboard's rich-text editors, which is
 * why the values here are HTML too.
 *
 * ## Why its own seeder
 *
 * `SettingsSeeder` calls `updateOrCreate` across the whole table, so running it
 * to refresh the legal copy would also reset `App_Name`, `Currency`,
 * `Country_Id` and every social URL to their template defaults — on an install
 * where an owner may have configured them. This one touches exactly three keys
 * and is safe to re-run on production:
 *
 *     php artisan db:seed --class=LegalContentSeeder
 *
 * The documents begin at `<h2>` and carry no title of their own: the page
 * renders the title as its `<h1>`, so a repeated title showed as a duplicate
 * heading and left the hierarchy skipping from h1 to h3.
 *
 * ## What this text is, and is not
 *
 * Every clause is written from what the code actually does — the approval gate
 * in `OrderStatus`, the cancellation cut-off in `allowedNext()`, the fee rule in
 * `DeliveryFeeCalculator`, the retention windows in `laundo:prune`, the data the
 * migrations actually store. So it describes this product accurately rather than
 * being boilerplate lifted from another service.
 *
 * It is **not legal advice and has not been reviewed by a lawyer.** It is a
 * well-informed draft that a solicitor should read before the business relies on
 * it — particularly the liability, governing-law and data-transfer clauses,
 * which are the ones a template gets wrong. Recorded here rather than as a
 * banner on the page, because a public page that opens by disclaiming itself is
 * worse than one an owner has been told to have checked.
 */
class LegalContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->put('About', $this->aboutEn(), $this->aboutAr());
        $this->put('Terms', $this->termsEn(), $this->termsAr());
        $this->put('Privacy_Policy', $this->privacyEn(), $this->privacyAr());
    }

    /**
     * Store one translatable value.
     *
     * `JSON_UNESCAPED_UNICODE` for the reason the whole codebase uses it: without
     * it the Arabic is stored as `\uXXXX` and becomes unreadable in the editor
     * that has to maintain it.
     */
    private function put(string $key, string $en, string $ar): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE)]
        );
    }

    // ---------------------------------------------------------------- About

    private function aboutEn(): string
    {
        return <<<'HTML'
<p>Laundo collects your laundry from your door, has it cleaned by a partner laundry, and brings it back. We operate across Greater Cairo.</p>
<p>What makes us different is the order of events. Your laundry is counted piece by piece, priced from a published list, and the total is sent to you for approval — and nothing is washed until you approve it. If the count looks wrong, you can send it back to be counted again, as many times as you need, at no charge.</p>
<h2>How it works</h2>
<ul>
<li>Choose a collection window, a service and the pieces you are sending.</li>
<li>A driver collects from your door and records what was handed over.</li>
<li>The laundry counts and prices every piece, and the total comes to you.</li>
<li>You approve it, or ask for a recount. Cleaning begins only on your approval.</li>
<li>The finished order comes back in the window you chose.</li>
</ul>
<h2>What we do not do</h2>
<p>We do not price by weight, and we do not set a price after the work is done. Prices are per piece and published, and the price you approved is the price you pay.</p>
HTML;
    }

    private function aboutAr(): string
    {
        return <<<'HTML'
<p>لاندو بيستلم غسيلك من باب بيتك، ومغسلة شريكة بتنضّفه، وبيرجعلك. بنعمل في نطاق القاهرة الكبرى.</p>
<p>اللي بيفرقنا هو ترتيب الخطوات. غسيلك بيتعدّ قطعة قطعة، وبيتسعّر من قائمة معلنة، والإجمالي بيوصلك للموافقة — ومفيش حاجة بتتغسل قبل ما توافق. ولو العدّ مش مظبوط، تقدر ترجّعه لإعادة العدّ، أي عدد مرات تحتاجه، من غير أي رسوم.</p>
<h2>بيشتغل إزاي</h2>
<ul>
<li>اختار ميعاد الاستلام والخدمة والقطع اللي هتبعتها.</li>
<li>المندوب بيستلم من باب بيتك وبيسجّل اللي اتسلّمه.</li>
<li>المغسلة بتعدّ وبتسعّر كل قطعة، والإجمالي بيوصلك.</li>
<li>توافق، أو تطلب إعادة العدّ. والتنظيف مايبدأش غير بموافقتك.</li>
<li>الطلب بيرجعلك جاهز في الميعاد اللي اخترته.</li>
</ul>
<h2>اللي إحنا مابنعملهوش</h2>
<p>مابنسعّرش بالوزن، ومابنحددش السعر بعد ما الشغل يخلص. الأسعار بالقطعة ومعلنة، والسعر اللي وافقت عليه هو اللي بتدفعه.</p>
HTML;
    }

    // ---------------------------------------------------------------- Terms

    private function termsEn(): string
    {
        return <<<'HTML'
<p>These terms govern your use of the Laundo apps and website, and every order you place through them. By placing an order you accept them.</p>

<h2>1. Who we are</h2>
<p>Laundo is a laundry collection and delivery service operating in Greater Cairo. The cleaning is carried out by partner laundries. We are responsible to you for the order: the collection, the price you are quoted, the cleaning we arrange and the return.</p>

<h2>2. Your account</h2>
<p>An account is identified by your mobile number, verified by a one-time code. Keep your number and access to it secure; orders placed from a verified account are treated as placed by you. Tell us at once if you believe someone else has access.</p>
<p>Accounts are for people aged 18 or over.</p>

<h2>3. Placing an order</h2>
<p>You choose a service, list the pieces you are sending, give a collection address and pick a collection window. The figure shown at that point is an <strong>estimate</strong> based on the pieces you listed. It is not the final price.</p>
<p>Collection and delivery windows are ranges, not appointments. A driver on a route cannot promise a minute, so we commit to the window and tell you when the driver is on the way.</p>

<h2>4. Counting, pricing and your approval</h2>
<p>This is the core of the service and the clause worth reading twice.</p>
<ul>
<li>Your driver records the pieces at collection. The partner laundry counts them again on arrival, item by item.</li>
<li>Each piece is priced from our published price list — the same list shown on our website. Prices are per piece. We do not price by weight.</li>
<li>The itemised count and the final total are sent to you, and <strong>the order stops there</strong>. Nothing is cleaned until you act.</li>
<li>You may <strong>approve</strong> the price, <strong>ask for a recount</strong>, or ask a question about a line. A recount is free and there is no limit on how many times you may ask.</li>
<li>If you never approve, nothing is cleaned and nothing is charged.</li>
</ul>
<p>The price you approve is copied onto your order. A later change to our published prices cannot affect an order you have already approved.</p>
<p>Some services — bulky household textiles, for example — are quoted after inspection rather than from the per-piece list. That quote goes through the same approval.</p>

<h2>5. Cancelling</h2>
<p>You may cancel free of charge at any time up to the moment the driver takes your items. After that the order runs to completion — but you still approve the price before anything is cleaned.</p>
<p>Where a count cannot be agreed, we may return your items uncleaned. In that case no cleaning is charged, but the collection and delivery charge remains payable, because the journeys were made.</p>

<h2>6. Collection and delivery charges</h2>
<p>The charge is calculated from the distance between your address and the assigned laundry, with a minimum for your area. Collecting from one address and delivering to another counts as two journeys and is charged accordingly. The charge is shown to you as its own line before you approve.</p>

<h2>7. Paying</h2>
<p>Payment is due when your order is handed back to you. You pay the driver in cash at your door, after your items are with you. In-app payment methods will be added; when they are, the method you choose and any charge specific to it will be shown before you approve.</p>
<p>Where a charge applies to a particular payment method, it is shown as its own line and you can avoid it by paying another way.</p>

<h2>8. Handover at your door</h2>
<p>You choose, independently for collection and for return, whether to hand the items over in person or have them left at your door. If you ask for items to be left, they are left at your risk from the moment they are delivered.</p>

<h2>9. Your items</h2>
<p>Please remove personal belongings from pockets before collection; we cannot accept responsibility for items left in them. Tell us about anything delicate, dyed, hand-finished or already damaged, so the laundry can treat it appropriately or decline it.</p>
<p>Some garments cannot be cleaned safely. Where a laundry judges that to be the case, the piece is returned uncleaned and not charged for.</p>

<h2>10. If something goes wrong</h2>
<p>You can raise a complaint from the order, with photographs. Complaints are given a reference and a status you can follow, and we will contact you about it. Categories include damage, a missing item, cleaning quality, lateness, driver conduct and payment.</p>
<p>Where we are responsible for loss or damage, we will put it right — by re-cleaning, by refund, or by compensation appropriate to the item's value. We may ask for proof of value.</p>

<h2>11. Refunds</h2>
<p>A refund request is reviewed by a person. If it is approved, the refund is paid back by the route you paid by, or credited to your Laundo wallet. We will tell you the outcome either way.</p>

<h2>12. Your wallet</h2>
<p>Your wallet holds a balance you can spend on orders. Every movement in and out is listed, and the balance is the sum of that list. A top-up is credited only once the payment provider confirms it. Wallet balances are not transferable and are not interest-bearing.</p>

<h2>13. Discounts and offers</h2>
<p>One discount applies per order: a discount code or a promotional offer, not both. Where both would apply the offer takes precedence and we tell you so rather than silently ignoring the code. Codes may carry a minimum order value, a maximum discount, an expiry and a limit on how many times they can be used, in total or by you.</p>

<h2>14. Inviting friends</h2>
<p>You have a referral code to share. Where a referral reward is in effect, both you and the person you invited receive it after their first paid order is complete — not on sign-up. Rewards have an expiry. We may withhold a reward where we believe an account was created to claim one.</p>

<h2>15. Repeat schedules</h2>
<p>You can set a weekly, fortnightly or monthly rhythm. We will <strong>ask you</strong> before each one — a schedule never places an order by itself. You can pause, resume or cancel it at any time.</p>

<h2>16. Ratings and reviews</h2>
<p>After an order you may rate it and leave a comment. We use ratings to manage our partner laundries and drivers. Please keep comments factual; we may remove content that is abusive or identifies someone unfairly.</p>

<h2>17. What we are not responsible for</h2>
<p>We are not responsible for loss you could not reasonably have expected, for delays caused by events outside our control, or for damage arising from a fault or fragility in an item that was not disclosed to us. Nothing in these terms limits any right you have under Egyptian consumer law.</p>

<h2>18. Suspending an account</h2>
<p>We may suspend or close an account where it is used fraudulently, where payment due to us is not made, or where our staff or drivers are abused. We will tell you why.</p>

<h2>19. Changes to these terms</h2>
<p>We may change these terms. The current version is always the one published here, and material changes will be brought to your attention in the app. Changes do not affect an order you have already placed.</p>

<h2>20. Governing law</h2>
<p>These terms are governed by the laws of the Arab Republic of Egypt, and the courts of Egypt have jurisdiction.</p>

<h2>21. Contacting us</h2>
<p>Our current contact details are published on our website and in the app, under «Contact us».</p>
HTML;
    }

    private function termsAr(): string
    {
        return <<<'HTML'
<p>الشروط دي بتنظّم استخدامك لتطبيقات لاندو وموقعه، وكل طلب بتعمله من خلالهم. وبطلبك أول طلب بتكون موافق عليها.</p>

<h2>١. إحنا مين</h2>
<p>لاندو خدمة استلام وتوصيل غسيل بتعمل في نطاق القاهرة الكبرى. التنظيف بتقوم بيه مغاسل شريكة. وإحنا مسؤولين قصادك عن الطلب: الاستلام، والسعر اللي بيتقالك، والتنظيف اللي بنرتّبه، والتسليم.</p>

<h2>٢. حسابك</h2>
<p>الحساب بيتعرّف برقم موبايلك، وبيتأكد بكود لمرة واحدة. حافظ على رقمك وعلى وصولك ليه؛ والطلبات اللي بتيجي من حساب مؤكَّد بتتعامل على إنها منك. بلّغنا فورًا لو شكيت إن حد تاني وصل لحسابك.</p>
<p>الحسابات لمن عنده ١٨ سنة أو أكتر.</p>

<h2>٣. إنشاء الطلب</h2>
<p>بتختار الخدمة، وبتحدّد القطع اللي هتبعتها، وبتدّي عنوان الاستلام وميعاده. الرقم اللي بيظهرلك في اللحظة دي <strong>تقديري</strong> ومبني على القطع اللي انت حدّدتها. مش السعر النهائي.</p>
<p>مواعيد الاستلام والتوصيل فترات، مش مواعيد بالدقيقة. المندوب اللي في الشارع ماينفعش يوعد بدقيقة، فإحنا بنلتزم بالفترة وبنقولك لما يكون في الطريق.</p>

<h2>٤. العدّ والتسعير وموافقتك</h2>
<p>ده جوهر الخدمة، والبند اللي يستاهل تقراه مرتين.</p>
<ul>
<li>المندوب بيسجّل القطع وقت الاستلام. والمغسلة الشريكة بتعدّها تاني لما توصل، قطعة قطعة.</li>
<li>كل قطعة بتتسعّر من قائمة أسعارنا المعلنة — نفس القائمة الموجودة على موقعنا. الأسعار بالقطعة. مابنسعّرش بالوزن.</li>
<li>تفصيل القطع والإجمالي النهائي بيوصلوك، و<strong>الطلب بيقف عند الخطوة دي</strong>. مفيش حاجة بتتنضّف لحد ما تتصرّف.</li>
<li>تقدر <strong>توافق</strong> على السعر، أو <strong>تطلب إعادة العدّ</strong>، أو تسأل عن بند. إعادة العدّ مجانية ومفيش حدّ لعدد المرات.</li>
<li>ولو ماوافقتش خالص، مفيش حاجة بتتنضّف ومفيش حاجة بتتحسب عليك.</li>
</ul>
<p>السعر اللي توافق عليه بيتنسخ على طلبك. وأي تغيير بعد كده في أسعارنا المعلنة ماينفعش يأثّر على طلب وافقت عليه بالفعل.</p>
<p>بعض الخدمات — المفروشات الثقيلة مثلًا — بيتحدد سعرها بعد المعاينة مش من قائمة القطعة. والسعر ده بيمرّ على نفس الموافقة.</p>

<h2>٥. الإلغاء</h2>
<p>تقدر تلغي مجانًا في أي وقت لحد اللحظة اللي المندوب فيها بياخد قطعك. بعد كده الطلب بيمشي لآخره — لكن برضه انت اللي توافق على السعر قبل ما حاجة تتنضّف.</p>
<p>ولو العدّ مااتفقناش عليه، ممكن نرجّعلك قطعك من غير تنظيف. في الحالة دي مفيش تنظيف بيتحسب، لكن رسوم الاستلام والتوصيل تفضل مستحقة، لأن الرحلات اتعملت.</p>

<h2>٦. رسوم الاستلام والتوصيل</h2>
<p>الرسوم بتتحسب من المسافة بين عنوانك والمغسلة المخصصة، وبحدّ أدنى لمنطقتك. والاستلام من عنوان والتوصيل لعنوان تاني بيتحسب رحلتين وبيتحسب على الأساس ده. والرسوم بتظهرلك كبند مستقل قبل ما توافق.</p>

<h2>٧. الدفع</h2>
<p>الدفع مستحق لما طلبك يتسلّم لك. بتدفع للمندوب كاش عند بابك، بعد ما قطعك تبقى معاك. وطرق الدفع من التطبيق هتتضاف؛ ولما تتضاف، الطريقة اللي تختارها وأي رسوم خاصة بيها هتظهرلك قبل ما توافق.</p>
<p>ولو فيه رسوم مرتبطة بطريقة دفع معيّنة، بتظهر كبند مستقل وتقدر تتجنّبها بالدفع بطريقة تانية.</p>

<h2>٨. التسليم عند بابك</h2>
<p>انت بتختار، للاستلام وللتسليم كل واحد لوحده، تسلّم القطع بنفسك ولا تتسيب عند بابك. ولو طلبت إنها تتسيب، بتكون على مسؤوليتك من لحظة تسليمها.</p>

<h2>٩. قطعك</h2>
<p>من فضلك فضّي جيوبك من أي حاجة شخصية قبل الاستلام؛ مش بنقدر نتحمّل مسؤولية الحاجات اللي بتتسيب فيها. وقولنا على أي حاجة حسّاسة أو مصبوغة أو مشغولة بالإيد أو فيها تلف من الأصل، عشان المغسلة تتعامل معاها صح أو ترفضها.</p>
<p>وفيه ملابس ماينفعش تتنضّف بشكل آمن. ولو المغسلة قدّرت كده، القطعة بترجعلك من غير تنظيف ومن غير حساب.</p>

<h2>١٠. لو حصلت مشكلة</h2>
<p>تقدر تقدّم شكوى من الطلب، مع صور. الشكاوى بتاخد رقم مرجعي وحالة تقدر تتابعها، وإحنا هنتواصل معاك بخصوصها. والتصنيفات بتشمل التلف، وقطعة ناقصة، وجودة التنظيف، والتأخير، وسلوك المندوب، والدفع.</p>
<p>ولو كنا مسؤولين عن فقد أو تلف، هنصلّحها — بإعادة التنظيف، أو باسترداد، أو بتعويض مناسب لقيمة القطعة. وممكن نطلب إثبات للقيمة.</p>

<h2>١١. الاسترداد</h2>
<p>طلب الاسترداد بيتراجع من شخص. ولو اتوافق عليه، بيتردّ بنفس الطريقة اللي دفعت بيها، أو بيتضاف لمحفظتك في لاندو. وهنقولك النتيجة في الحالتين.</p>

<h2>١٢. محفظتك</h2>
<p>محفظتك فيها رصيد تقدر تستخدمه في الطلبات. وكل حركة داخلة أو خارجة مسجّلة، والرصيد هو مجموع القائمة دي. والإضافة بتتقيّد بس لما مزوّد الدفع يأكّدها. أرصدة المحفظة مش قابلة للتحويل ومابتديش فوائد.</p>

<h2>١٣. الخصومات والعروض</h2>
<p>خصم واحد لكل طلب: كود خصم أو عرض ترويجي، مش الاتنين. ولو الاتنين ينطبقوا، العرض هو اللي بيتقدّم وإحنا بنقولك كده بدل ما نتجاهل الكود بالساكت. والأكواد ممكن يكون لها حدّ أدنى لقيمة الطلب، أو حدّ أقصى للخصم، أو تاريخ انتهاء، أو حدّ لعدد مرات الاستخدام، إجمالًا أو منك.</p>

<h2>١٤. دعوة الأصدقاء</h2>
<p>عندك كود دعوة تشاركه. ولو كان فيه مكافأة دعوة سارية، انت والشخص اللي دعوته بتاخدوها بعد ما أول طلب مدفوع ليه يكتمل — مش وقت التسجيل. والمكافآت لها تاريخ انتهاء. وممكن نمنع مكافأة لو شكّينا إن حساب اتعمل عشان يستفيد منها.</p>

<h2>١٥. الطلبات المتكررة</h2>
<p>تقدر تحدّد إيقاع كل أسبوع أو كل أسبوعين أو كل شهر. وإحنا <strong>هنسألك</strong> قبل كل مرة — الجدول مابيعملش طلب من نفسه أبدًا. وتقدر توقفه أو ترجّعه أو تلغيه في أي وقت.</p>

<h2>١٦. التقييمات</h2>
<p>بعد الطلب تقدر تقيّمه وتكتب ملاحظاتك. وإحنا بنستخدم التقييمات في إدارة المغاسل الشريكة والمندوبين. من فضلك خلّي الملاحظات واقعية؛ وممكن نشيل أي محتوى مسيء أو بيشير لشخص بشكل غير عادل.</p>

<h2>١٧. اللي مش مسؤولين عنه</h2>
<p>مش مسؤولين عن خسارة ماكانش متوقّع حدوثها بشكل معقول، ولا عن تأخير سببه أحداث خارج سيطرتنا، ولا عن تلف ناتج عن عيب أو هشاشة في قطعة ماتقالتلناش عليها. ومفيش حاجة في الشروط دي بتقيّد أي حق ليك في قانون حماية المستهلك المصري.</p>

<h2>١٨. إيقاف الحساب</h2>
<p>ممكن نوقف أو نقفل حساب لو اتستخدم بشكل احتيالي، أو لو مبلغ مستحق لينا ماتدفعش، أو لو حصل إساءة لموظفينا أو مندوبينا. وهنقولك السبب.</p>

<h2>١٩. تغيير الشروط</h2>
<p>ممكن نغيّر الشروط دي. والنسخة السارية دايمًا هي المنشورة هنا، وأي تغيير جوهري هنلفت نظرك ليه في التطبيق. والتغييرات مابتأثرش على طلب عملته بالفعل.</p>

<h2>٢٠. القانون المطبق</h2>
<p>الشروط دي بتخضع لقوانين جمهورية مصر العربية، والمحاكم المصرية هي المختصة.</p>

<h2>٢١. التواصل معنا</h2>
<p>بيانات التواصل الحالية بتاعتنا منشورة على موقعنا وفي التطبيق، تحت «تواصل معنا».</p>
HTML;
    }

    // -------------------------------------------------------------- Privacy

    private function privacyEn(): string
    {
        return <<<'HTML'
<p>This explains what Laundo collects, why, who sees it and how long we keep it. It covers the Laundo apps and website.</p>

<h2>1. What we collect</h2>
<ul>
<li><strong>Your account.</strong> Your name, mobile number and, if you give one, an email address. Your mobile number identifies your account and is verified by a one-time code.</li>
<li><strong>Your addresses.</strong> The addresses you save — city, area, street, building, floor, flat, a landmark, any note for the driver, and a map location. The map location is needed because the collection and delivery charge is calculated from distance.</li>
<li><strong>Your orders.</strong> What you sent, the service, the counts and prices, the window you chose, how you paid, and the order's history.</li>
<li><strong>Photographs and signatures.</strong> Drivers photograph the items at handover, and take a signature at your door on collection and delivery. Photographs you attach to a complaint are stored with it.</li>
<li><strong>Contact with us.</strong> Complaints, questions you ask about an order, and ratings and comments you leave.</li>
<li><strong>Device information.</strong> A device token, so we can send you notifications, and basic technical information from your requests.</li>
</ul>

<h2>2. Why we use it</h2>
<p>To carry out your order — assigning a laundry, routing a driver, calculating the charge, taking payment. To keep you informed about the order. To answer complaints and handle refunds. To keep the service secure and prevent abuse. And to understand how the service is used, so we can improve it.</p>
<p>We process this information because it is necessary to provide the service you asked for, to meet our legal obligations, and, where you have given it, on the basis of your consent — for example for notifications.</p>

<h2>3. Location</h2>
<p>Two kinds of location are involved and they are different.</p>
<ul>
<li><strong>Your addresses.</strong> A pin you set once per address, stored with that address.</li>
<li><strong>The driver's location.</strong> While a driver is actively on a journey for your order, their position is updated so you can follow it on the map. This is the driver's location, not yours, and it is collected only while a journey is under way.</li>
</ul>

<h2>4. Who sees your information</h2>
<ul>
<li><strong>The partner laundry</strong> assigned to your order sees what it needs to do the work — the order, its pieces, and the reference. It does not receive your full address book.</li>
<li><strong>Your driver</strong> sees the journey they are on: the address, your name, a contact number for that journey, and any note you left. The contact number is available for the journey, not afterwards.</li>
<li><strong>Our own staff</strong> see what their role allows. Access is limited by role, and a partner laundry's staff can only see their own laundry's orders.</li>
<li><strong>Service providers</strong> we rely on — hosting, SMS delivery for verification codes, notification delivery and, when it is live, payment processing. They act on our instructions.</li>
<li>We do not sell your information, and we do not share it for anyone else's advertising.</li>
</ul>
<p>We may disclose information where the law requires it, or to establish or defend a legal claim.</p>

<h2>5. Notifications</h2>
<p>We send notifications about your order — collection, the price waiting for your approval, delivery. You can turn categories off in the app; messages that are part of carrying out an order cannot be turned off, because without them you would not know your order was waiting for you.</p>

<h2>6. Payment</h2>
<p>Cash paid at your door is recorded by the driver as an amount collected; no card details are involved. When in-app payment is available, card details are handled by the payment provider and not stored by us — we keep the provider's reference and the result, so we can show you the payment and reconcile it.</p>

<h2>7. How long we keep it</h2>
<p>Order records are kept for as long as we need them to run the service and meet accounting and tax obligations. Some records are pruned on a shorter cycle: notification delivery logs, stored payment provider responses, and device tokens that are no longer valid. If you close your account we delete or anonymise what we are not required to keep.</p>

<h2>8. Your rights</h2>
<p>You can ask us for a copy of the information we hold about you, ask us to correct it, ask us to delete it, or object to a particular use. You can withdraw consent for notifications at any time. Contact us using the details published in the app and we will respond.</p>
<p>Where you ask us to delete information we are required to keep — an invoice, for example — we will tell you what we must keep and why.</p>

<h2>9. Keeping it safe</h2>
<p>Access to information is limited by role, passwords are stored hashed and never in readable form, and traffic between the apps and our servers is encrypted. No system is perfectly secure; if a breach affects you we will tell you.</p>

<h2>10. Children</h2>
<p>The service is not intended for people under 18 and we do not knowingly collect their information.</p>

<h2>11. Where information is held</h2>
<p>Information is held on servers operated for us, and some of our service providers operate outside Egypt. Where information is transferred outside Egypt we take steps to see that it remains protected to the standard described here.</p>

<h2>12. Changes</h2>
<p>We may update this policy. The current version is always the one published here, and material changes will be brought to your attention in the app.</p>

<h2>13. Contacting us</h2>
<p>For anything about your information, or to exercise any of the rights above, use the contact details published on our website and in the app.</p>
HTML;
    }

    private function privacyAr(): string
    {
        return <<<'HTML'
<p>الصفحة دي بتوضّح لاندو بيجمع إيه، وليه، ومين بيشوفه، وبنحتفظ بيه قد إيه. وبتغطّي تطبيقات لاندو والموقع.</p>

<h2>١. إحنا بنجمع إيه</h2>
<ul>
<li><strong>حسابك.</strong> اسمك ورقم موبايلك، ولو دخّلت بريد إلكتروني. ورقم موبايلك هو اللي بيعرّف حسابك وبيتأكد بكود لمرة واحدة.</li>
<li><strong>عناوينك.</strong> العناوين اللي بتحفظها — المدينة والمنطقة والشارع والعمارة والدور والشقة وعلامة مميزة وأي ملاحظة للمندوب وموقع على الخريطة. والموقع على الخريطة ضروري لأن رسوم الاستلام والتوصيل بتتحسب بالمسافة.</li>
<li><strong>طلباتك.</strong> اللي بعته، والخدمة، والأعداد والأسعار، والميعاد اللي اخترته، وطريقة الدفع، وتاريخ الطلب.</li>
<li><strong>الصور والتوقيعات.</strong> المندوبين بيصوّروا القطع وقت التسليم، وبياخدوا توقيع عند بابك وقت الاستلام والتسليم. والصور اللي ترفقها بشكوى بتتخزّن معاها.</li>
<li><strong>تواصلك معانا.</strong> الشكاوى، والأسئلة اللي بتسألها عن طلب، والتقييمات والملاحظات اللي بتكتبها.</li>
<li><strong>بيانات الجهاز.</strong> رمز للجهاز عشان نقدر نبعتلك إشعارات، ومعلومات تقنية أساسية من طلباتك.</li>
</ul>

<h2>٢. بنستخدمها ليه</h2>
<p>عشان ننفّذ طلبك — تخصيص مغسلة، وتوجيه مندوب، وحساب الرسوم، وتحصيل الدفع. وعشان نخليك عارف بحالة الطلب. وعشان نردّ على الشكاوى ونتعامل مع الاسترداد. وعشان نحافظ على أمان الخدمة ونمنع إساءة الاستخدام. وعشان نفهم الخدمة بتتستخدم إزاي فنحسّنها.</p>
<p>وإحنا بنعالج البيانات دي لأنها ضرورية لتقديم الخدمة اللي طلبتها، وللالتزام بواجباتنا القانونية، وفي الحالات اللي أذنت فيها — الإشعارات مثلًا — على أساس موافقتك.</p>

<h2>٣. الموقع الجغرافي</h2>
<p>فيه نوعين موقع، وهُمّ مختلفين.</p>
<ul>
<li><strong>عناوينك.</strong> دبّوس بتحدّده مرة واحدة لكل عنوان، وبيتخزّن مع العنوان.</li>
<li><strong>موقع المندوب.</strong> وهو في رحلة فعلية لطلبك، موقعه بيتحدّث عشان تقدر تتابعه على الخريطة. وده موقع المندوب، مش موقعك، وبيتجمع بس وقت الرحلة.</li>
</ul>

<h2>٤. مين بيشوف بياناتك</h2>
<ul>
<li><strong>المغسلة الشريكة</strong> المخصصة لطلبك بتشوف اللي محتاجاه للشغل — الطلب وقطعه ورقمه المرجعي. ومابتستلمش دفتر عناوينك.</li>
<li><strong>المندوب</strong> بيشوف الرحلة اللي هو فيها: العنوان، واسمك، ورقم للتواصل خاص بالرحلة دي، وأي ملاحظة سيبتها. ورقم التواصل متاح للرحلة، مش بعدها.</li>
<li><strong>موظفينا</strong> بيشوفوا اللي دورهم بيسمح بيه. والوصول محدود بالدور، وموظفي المغسلة الشريكة مابيشوفوش غير طلبات مغسلتهم.</li>
<li><strong>مزوّدي الخدمة</strong> اللي بنعتمد عليهم — الاستضافة، وإرسال رسائل أكواد التأكيد، وتوصيل الإشعارات، ومعالجة الدفع لما تشتغل. وهُمّ بيعملوا بتوجيهاتنا.</li>
<li>إحنا مابنبيعش بياناتك، ومابنشاركهاش لإعلانات أي حد تاني.</li>
</ul>
<p>وممكن نفصح عن بيانات لو القانون طلب كده، أو لإثبات أو الدفاع عن مطالبة قانونية.</p>

<h2>٥. الإشعارات</h2>
<p>بنبعت إشعارات عن طلبك — الاستلام، والسعر اللي مستنّي موافقتك، والتسليم. وتقدر تقفل تصنيفات منها من التطبيق؛ لكن الرسايل اللي جزء من تنفيذ الطلب ماينفعش تتقفل، لأن من غيرها مش هتعرف إن طلبك مستنّيك.</p>

<h2>٦. الدفع</h2>
<p>الكاش اللي بتدفعه عند بابك بيتسجّل من المندوب كمبلغ محصّل؛ مفيش أي بيانات بطاقة في الموضوع. ولما الدفع من التطبيق يشتغل، بيانات البطاقة بيتعامل معاها مزوّد الدفع ومابنخزّنهاش إحنا — بنحتفظ برقم المزوّد المرجعي وبالنتيجة، عشان نعرف نوريك الدفعة ونطابقها.</p>

<h2>٧. بنحتفظ بيها قد إيه</h2>
<p>سجلات الطلبات بنحتفظ بيها طول ما محتاجينها لتشغيل الخدمة والالتزام بالواجبات المحاسبية والضريبية. وبعض السجلات بتتنضّف على دورة أقصر: سجلات توصيل الإشعارات، وردود مزوّد الدفع المخزّنة، ورموز الأجهزة اللي بقت غير صالحة. ولو قفلت حسابك، بنمسح أو نجهّل اللي مش مطالبين بالاحتفاظ بيه.</p>

<h2>٨. حقوقك</h2>
<p>تقدر تطلب مننا نسخة من البيانات اللي عندنا عنك، أو تطلب تصحيحها، أو تطلب مسحها، أو تعترض على استخدام معيّن. وتقدر تسحب موافقتك على الإشعارات في أي وقت. تواصل معانا بالبيانات المنشورة في التطبيق وإحنا هنردّ.</p>
<p>ولو طلبت مسح بيانات إحنا مطالبين بالاحتفاظ بيها — فاتورة مثلًا — هنقولك إحنا لازم نحتفظ بإيه وليه.</p>

<h2>٩. أمان البيانات</h2>
<p>الوصول للبيانات محدود بالدور، وكلمات السر مخزّنة مشفّرة ومش مقروءة أبدًا، والاتصال بين التطبيقات وسيرفراتنا مشفّر. ومفيش نظام آمن بشكل كامل؛ ولو حصل خرق يأثّر عليك هنبلّغك.</p>

<h2>١٠. الأطفال</h2>
<p>الخدمة مش موجّهة لمن هُم أقل من ١٨ سنة، ومابنجمعش بياناتهم بمعرفتنا.</p>

<h2>١١. البيانات بتتحفظ فين</h2>
<p>البيانات بتتحفظ على سيرفرات بتُشغَّل لصالحنا، وبعض مزوّدي الخدمة بتاعينا بيعملوا خارج مصر. ولو اتنقلت بيانات خارج مصر، بناخد خطوات نتأكد إنها تفضل محميّة بنفس المستوى الموصوف هنا.</p>

<h2>١٢. التغييرات</h2>
<p>ممكن نحدّث السياسة دي. والنسخة السارية دايمًا هي المنشورة هنا، وأي تغيير جوهري هنلفت نظرك ليه في التطبيق.</p>

<h2>١٣. التواصل معنا</h2>
<p>لأي حاجة بخصوص بياناتك، أو لممارسة أي حق من الحقوق فوق، استخدم بيانات التواصل المنشورة على موقعنا وفي التطبيق.</p>
HTML;
    }
}
