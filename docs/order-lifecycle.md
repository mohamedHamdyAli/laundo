# دورة حياة الطلب — الحالات والريكوستات

> مرجع لدورة تتبّع الطلب (Track Order): كل حالة، والريكوست اللي بينتجها، ومين
> بينده عليها. اتكتب من الكود مباشرة، مش من الديزاين.

الحالات كلها متعرّفة في `app/Modules/Order/Enums/OrderStatus.php`، وجدول
الانتقالات المسموحة موجود في `allowedNext()` — مفيش قائمة تانية في أي مكان.

**قاعدة واحدة تحكم كل حاجة:** الانتقال بين الحالات بيمرّ من
`OrderStateMachine::transition()` بس. أي كود بيكتب `$order->status` مباشرة
بيتخطّى الـ validation وبيسيب `order_status_logs` ناقص — وشاشة التتبّع بتتبني من
الـ log ده، مش من الحالة الحالية.

---

## 1. الحالات والريكوست اللي بتنتج كل واحدة

| # | الحالة | مين | الريكوست | المرجع |
| --- | --- | --- | --- | --- |
| 1 | `awaiting_pickup` | العميل | `POST /api/v1/orders` | `OrderService.php:147` |
| 2 | `driver_on_way` | السائق | `POST /api/v1/driver/tasks/{id}/start` — leg 1 بس | `TaskType::startsInto()` |
| 3 | `picked_up` | السائق | `POST /api/v1/driver/tasks/{id}/complete` — leg 1 | `TaskType::completesInto()` |
| — | *(مفيش تغيير)* | السائق | `complete` على leg 2 (التسليم للمغسلة) — بيحرّك الـ task مش الطلب | `TaskType::completesInto()` |
| 4 | `reviewed` | المغسلة | `POST /admin/order/review/{id}` (البانل) | `OrderReviewService::store()` |
| 5a | `confirmed` | العميل | `POST /api/v1/orders/{id}/confirm` | `OrderReviewService::confirm()` |
| 5b | `review_disputed` | العميل | `POST /api/v1/orders/{id}/dispute` → بترجع `reviewed` بعد عدّ تاني | `OrderReviewService::dispute()` |
| 6 | `cleaning` | — | **مفيش** ⚠️ | — |
| 7 | `ready_for_delivery` | — | **مفيش** ⚠️ | — |
| — | *(مفيش تغيير)* | السائق | `complete` على leg 3 (الاستلام من المغسلة) | `TaskType::completesInto()` |
| 8 | `delivered` | السائق | `complete` على leg 4 + تحصيل الكاش لو COD | `TaskService::settlePayment()` |
| 9 | `completed` | — | **مفيش** ⚠️ | — |
| × | `cancelled` | العميل | `PUT /api/v1/orders/{id}/cancel` — مسموح قبل `picked_up` بس | `OrderService::cancel()` |
| × | `returned` | operator | **مفيش endpoint** ⚠️ | — |

الإلغاء بيقف عند الاستلام بقصد: أول ما القطع تبقى معانا الطلب بيمشي لآخره.
الشرط ده متكتوب في جدول الانتقالات نفسه (`isCancellable()` مشتقّة منه)، فمفيش
endpoint يقدر يخالفه بالغلط.

`returned` **مش إلغاء**: القطع بترجع بس رسوم التوصيل لسه مستحقة، فلو اتسمّت
`cancelled` كنا هنخسر المعلومة دي.

---

## 2. شاشة التتبّع

```
GET /api/v1/orders/{id}/track
```

`app/Http/Controllers/Api/V1/OrderController.php:166`. بترجّع:

- `code`, `status`, `status_label`, `is_active`, `can_cancel`
- `driver` — كارت المندوب (`null` بين الرحلات وقبل التعيين، والديزاين بيرسمها فاضية)
- `steps` — 6 خطوات من `OrderStatus::trackingSteps()`

الخطوات الستة: `picked_up` → `reviewed` → `confirmed` → `cleaning` →
`ready_for_delivery` → `delivered`.

كل خطوة `reached` بتتحسب من `order_status_logs` مش من الحالة الحالية — يعني الخط
بيتملي من الـ log، وطلب اتنقل بإيد من غير الـ state machine هيبان ناقص الخطوات.

`review_disputed` **مش** على الـ timeline بقصد: ده detour مش milestone، ورسمه كان
هيخلي الخط يطوّل لما الأمور تبوظ.

---

## 3. رحلات السائق — أربع legs

`app/Modules/Order/Enums/TaskType.php`. الترتيب ثابت والـ enum هو اللي يملكه:

1. `pickup_from_customer` — استلام من العميل
2. `deliver_to_laundry` — تسليم للمغسلة
3. `collect_from_laundry` — استلام من المغسلة
4. `deliver_to_customer` — تسليم للعميل

كل leg عليه نفس الأربع ريكوستات:

```
POST /api/v1/driver/tasks/{id}/start      → started   (leg 1 كمان بيحرّك الطلب لـ driver_on_way)
POST /api/v1/driver/tasks/{id}/verify     → QR scan؛ body: { token }
POST /api/v1/driver/tasks/{id}/complete   → { piece_count, receiver_name, collected_amount, note, photos[], signature }
POST /api/v1/driver/tasks/{id}/fail       → { reason من TaskFailureReason, note }
```

`verify` منفصل عن `complete` بقصد: السائق بيمسح الـ QR أول ما يوصل وبيكمّل بعد
التسليم، ودمجهم كان معناه إن فشل رفع صورة يضيّع مسح ناجح.

قواعد كل leg (`TaskType`):

| | leg 1 | leg 2 | leg 3 | leg 4 |
| --- | --- | --- | --- | --- |
| `requiresSignature()` | ✔ | — | — | ✔ |
| `countsPieces()` | ✔ | ✔ | ✔ | — |
| `collectsPayment()` | — | — | — | ✔ (COD) |
| `mediaType()` | `pickup` | `laundry` | `ready` | `delivery` |

leg 4 مش بيعدّ القطع: العدّ اتقفل في مراجعة المغسلة ووافق عليه العميل، وفتحه على
باب البيت معناه خلاف محدش هناك يقدر يحسمه.

**التعيين من البانل:**

```
POST /admin/order/task/assign/{id}     تعيين سائق          permission: order.update
POST /admin/order/task/release/{id}    إرجاع للطابور        permission: order.update
POST /admin/order/task/generate/{id}   توليد السلسلة        permission: order.update
POST /admin/order/task/dispatch/{id}   إعادة الإرسال        permission: order.update
GET  /admin/dispatch                   لوحة الرحلات المنتظرة permission: order_task.view
```

---

## 4. حاجات بتحصل على الجنب

**الإشعارات** — `OrderStateMachine::announce()` هو المكان الوحيد اللي بيشوف كل
انتقال، فالإشعار متربوط هناك مش عند الـ 8 call sites:

| الانتقال | الإشعار |
| --- | --- |
| `driver_on_way` | `driverOnWay()` |
| `reviewed` | `finalPriceReady()` — دي اللي الطلب بيقف عندها فعلاً |
| `confirmed` | `priceConfirmed()` |
| `ready_for_delivery` | `readyForDelivery()` |
| `delivered` | `delivered()` |

`cleaning` صامتة بقصد: العميل لسه مأكّد السعر من ثانية.

`announce()` مش بيرمي exception أبداً — إشعار فشل مايصحّش يرجّع الحالة اللي كان
بيوصفها.

**الأرباح** — `settleEarnings()`: أرباح السائق بتتفرج على `completed`، وبتتلغي على
`cancelled` / `returned`. في نفس الـ transaction بتاعة الانتقال.

**الفلوس** — مسار مستقل تماماً عن الحالة (`payment_status`)، عشان الدفع كاش عند
الاستلام معروض:

```
GET  /api/v1/payment-methods
POST /api/v1/orders/{id}/pay
GET  /api/v1/orders/{id}/payments
GET  /api/v1/orders/{id}/refunds
POST /api/v1/orders/{id}/refunds
```

`confirmed` معناها العميل وافق على السعر، **مش** إنه دفع.

**استفسار السعر** — `POST /api/v1/orders/{id}/queries`: سؤال مش رفض، والطلب بيفضل
مكانه بالظبط. غير `dispute` اللي بيحرّكه.

**التأجيل** — `GET|POST /api/v1/orders/{id}/reschedule`: الـ leg بيستنى، ومابيرجعش
للطابور (قبل كده كان السائق التالي بيتعرض عليه نفس الرحلة بعد ثواني من «مش دلوقتي»).

**التقييم** — `GET|POST /api/v1/orders/{id}/rating`: متاح من `delivered` كمان مش
`completed` بس، لأن العميل بقى ماسك الهدوم.

---

## 5. ⚠️ الفجوة في السيكل

`cleaning` و `ready_for_delivery` و `completed` و `returned` **مالهمش أي endpoint
ولا شاشة في البانل**.

جدول الانتقالات بيسمح بيهم، والتقارير بتعدّهم (`DashboardSummary.php:107,406,413`
و `WeeklyDigest.php:174` و `LaundryReport.php:137`)، والـ notifier عنده
`readyForDelivery()` جاهز — بس الـ tests بس هي اللي بتوصلهم، وبتنده
`$machine->transition()` مباشرة (`DriverTaskTest.php:683`، `NotificationTest.php:119`،
`PaymentTest.php:478`، `RatingTest.php:76`).

النتيجة عملياً:

- الطلب بيقف على `confirmed` بعد موافقة العميل على السعر. مفيش حاجة تخليه
  `ready_for_delivery`، فـ **leg 3 مش بيوصل لحالته الطبيعية** والعميل عمره ما هياخد
  إشعار «جاهز للتوصيل».
- الطلب بيقف على `delivered` وعمره ما يبقى `completed`، يعني **أرباح السائق مش
  بتتفرج** (`settleEarnings()` بينده `releaseFor()` على `Completed` بس)، وتبويب
  «مكتمل» في الأبلكيشن فاضي، وتقارير الإيراد بتعدّ الاتنين فبتشتغل بالصدفة.

**اللي ناقص:**

1. شاشة/endpoint للمغسلة تقول «بدأت التنظيف» (`confirmed → cleaning`) و «جاهز»
   (`cleaning → ready_for_delivery`).
2. Trigger لـ `delivered → completed` — زرار في البانل، أو تلقائي بعد التسليم بمدة
   محددة.
3. Endpoint لـ `returned` (operator-only) من شاشة الطلب.

كل واحدة فيهم لازم تعدّي على `OrderStateMachine::transition()` بـ `actorType`
مناسب (`laundry` / `admin` / `system`)، مش على `$order->update()`.
