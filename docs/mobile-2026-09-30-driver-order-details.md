# تغييرات API — شاشة «تفاصيل الطلب» في تطبيق السواق

**التاريخ:** ٣٠ سبتمبر ٢٠٢٦
**لـ:** فريق تطبيق السواق
**الحالة:** شغال على السيرفر من ٣٠ سبتمبر ٢٠٢٦
**بيتبعت مع:** الـPostman collection المحدّثة (`6.3 › Order details`)

---

## الخلاصة في سطرين

**endpoint جديد:** `GET /api/v1/driver/orders/{id}` بيرجّع الطلب كله اللي ورا
المهمة: القطع، مواعيد الاستلام والتسليم، المغسلة، الدفع، حالة الطلب، ومهام السواق
على الطلب ده. **ومفيش حاجة كاسرة** — التغيير التاني الوحيد حقل جديد `order_id`
على صفوف المهام.

---

## ١. `order_id` على كل صف مهمة

في `GET /driver/tasks` و`/driver/tasks/history` و`/driver/summary`
(`current_task`) و`/driver/tasks/{id}` — حقل جديد **`order_id`** جنب `order_code`.
ده الـ id اللي بتبعتوه للـ endpoint الجديد. `order_code` للعرض بس.

## ٢. `GET /api/v1/driver/orders/{id}`

```http
GET /api/v1/driver/orders/42
Accept: application/json
lang: ar
Authorization: Bearer {driver_token}
```

```json
{
  "id": 42,
  "code": "10019",
  "status": "cleaning",
  "status_label": "جاري التنظيف",
  "service": "غسيل وكي",
  "customer": { "name": "أحمد", "reference": "C-001" },
  "laundry": {
    "name": "مغسلة A",
    "address": { "street": "…", "lat": 30.0561, "lng": 31.2003, "phone": "+201011110001" }
  },
  "pickup": {
    "date": "2026-09-30", "slot": "10:00 AM – 12:00 PM", "method": "door",
    "address": { "label": "البيت", "street": "…", "building": "…", "floor": "…", "apartment": "…",
                 "landmark": "…", "lat": 30.07, "lng": 31.22, "phone": "+201055556666" }
  },
  "delivery": { "date": "2026-10-03", "slot": "…", "method": "door", "address": { "…": "…" } },
  "driver_note": null,
  "special_instructions": null,
  "laundry_note": "أعدنا العد",
  "counts_visible": false,
  "items_count": null,
  "items_phase": "final",
  "items": [ { "item_id": 1, "name": "قميص على شماعة", "qty": null } ],
  "payment": { "amount_due": 54, "method": "cash", "status": "unpaid" },
  "my_tasks": [ { "id": 7, "order_id": 42, "type": "pickup_from_customer", "sequence": 1, "status": "completed", "…": "…" } ],
  "created_at": "منذ ساعتين",
  "created_at_iso": "2026-09-30T10:12:00+03:00"
}
```

### مين يقدر يفتحه

**السواق اللي ماسك رجلة في الطلب ده بس.** غير كده ← **404** (زي مهمة سواق تاني).

- لو الرجلة **فشلت** («تعذر الاستلام») بترجع للطابور، والطلب بيطلع من عند السواق ←
  404 بعدها.
- لو الرجلة **اتلغت** (الطلب اتلغى) بتفضل في سجل السواق، والطلب بيفضل يتفتح.

### ⚠️ القطع: أسماء من غير عدد لحد ما الاستلام من المغسلة يخلص

| | قبل ما «الاستلام من المغسلة» يخلص | بعده |
|---|---|---|
| `counts_visible` | `false` | `true` |
| `items_count` | `null` | العدد النهائي |
| `items[].qty` | `null` | الكمية |
| `items[].name` | موجود | موجود |

نفس قرار صاحب المنصة بتاع `expected_pieces`: القايمة بالكميات هي نفس الرقم اللي
السواق المفروض يعدّه بنفسه. **وبتتفتح في نفس اللحظة** اللي `expected_pieces` بتاع
«التسليم للعميل» بيتفتح فيها.

- اعرضوا الأسماء، ومكان العدد اكتبوا إن العدد بيظهر بعد الاستلام من المغسلة.
- `counts_visible: false` **مش** معناه إن الطلب مفيهوش قطع.
- `items_phase`: `estimated` = قايمة العميل، `final` = عدّ المغسلة بعد المراجعة.
- الـ parser لازم يقبل `null` في `qty` و`items_count`.
- **قبل ما العدد يتفتح، القايمة دايماً قايمة العميل** (`items_phase: estimated`)،
  حتى لو المغسلة راجعت الطلب — الأصناف اللي المغسلة زوّدتها أو شالتها بتلمّح للرقم
  اللي الاستلام من المغسلة بيتقارن بيه. بعد ما يتفتح بتبقى قايمة المغسلة (`final`).

### العنوان والرقم والفلوس مع الرجلة اللي محتاجاهم بس

نفس اللي شاشة المهمة بتديه للسواق — مش أكتر:

| الحقل | بيرجع لو السواق ماسك | غير كده |
|---|---|---|
| `pickup.address` | «الاستلام من العميل» | `null` |
| `delivery.address` | «التسليم للعميل» | `null` |
| `laundry.address` | «التسليم للمغسلة» أو «الاستلام من المغسلة» | `null` |
| `payment` | «التسليم للعميل» | `null` |

- `address.phone` في باب العميل هو الرقم اللي يتكلّم عليه عند الباب — نفس
  `contact.phone` في شاشة المهمة. و`laundry.address.phone` رقم المغسلة.
- السواق اللي ماسك رجلتين المغسلة بس بياخد المواعيد والمغسلة، مش باب العميل ولا المبلغ.
- `date` و`slot` و`method` (`door` / `leave`) وإسم المغسلة موجودين دايماً. `date`
  و`slot` ممكن يبقوا `null` لو الطلب اتعمل من غير ميعاد.

### باقي الحقول

- `laundry` كله بـ`null` لو الطلب لسه ملوش مغسلة. `laundry.address.lat`/`lng`
  بـ`null` لو محدش حط دبوس المغسلة — اعرضوا العنوان كنص.
- `payment.amount_due`: سعر المغسلة بعد المراجعة، والتقدير قبلها.
- `my_tasks`: مهام **السواق ده** على الطلب، بنفس شكل صف `GET /driver/tasks`،
  عشان كل صف يفتح شاشة المهمة بتاعته. مهام السواقين التانيين مش بترجع.

## اللي محتاجينه منكم

1. زرار «تفاصيل الطلب» في شاشة المهمة يفتح `GET /driver/orders/{order_id}`.
2. القطع: الأسماء دايماً، والعدد بس لما `counts_visible` يبقى `true`.
3. `pickup.address` و`delivery.address` و`laundry.address` و`payment` ممكن يبقوا
   `null` — متفترضوش إنهم موجودين، واخفوا الجزء بتاعهم من الشاشة.
