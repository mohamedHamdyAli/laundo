# تغيير في الـ API — تفضيلات الإشعارات

> **النوع:** breaking · **التاريخ:** 2026-09-13 · **يخص:** تطبيق العميل وتطبيق المندوب
>
> التغيير في endpoint واحد بس: `‎/api/v1/notification-preferences`. باقي الـ API
> زي ما هو.
>
> الـ Postman collection و[`api-reference.html`](api-reference.html) اتحدّثوا
> بالفعل، فلو بتشتغل منهم اسحب آخر نسخة.

---

## المشكلة اللي كانت بتحصل

الإشعار كان بينزل على التليفون من فايربيز عادي، وبعدين المستخدم يدوس عليه، يفتح
ليستة الإشعارات جوّه التطبيق — **يلاقيها فاضية**.

ده اللي كان في اللوج على السيرفر، متكرر:

```
196 | u11 | order_placed | push     | sent
195 | u11 | order_placed | database | skipped | muted by the user
```

السبب إن التطبيق في وقت ما نادى:

```json
PUT /api/v1/notification-preferences
{ "channel": "database", "enabled": false }
```

وقناة `database` هي **سجل** الإشعارات جوّه التطبيق — مش قناة تسليم. كتمها
مابيقللش أي إزعاج (مفيش حاجة بترن)، هو بس بيمنع الإشعار إنه يتخزّن أصلًا. فالـ
push يفضل نازل، والليستة تفضل فاضية.

---

## اللي اتغيّر

### `GET /api/v1/notification-preferences`

بيرجّع **القنوات اللي ينفع تتكتم بس** — دي `push` ومفيش غيرها.

**قبل:**

```json
{ "data": [
    { "channel": "database", "enabled": true },
    { "channel": "push",     "enabled": true }
] }
```

**بعد:**

```json
{ "data": [
    { "channel": "push", "enabled": true }
] }
```

> ⚠️ **خد بالك لو بتقرا بالترتيب.** المصفوفة بقت عنصر واحد، فأي كود بيعمل
> `data[1]` هيقع. اقرا بـ `channel` مش بالـ index.

### `PUT /api/v1/notification-preferences`

`channel` لازم يكون `push`. أي قيمة تانية بترجع **422**:

```json
{ "channel": "database", "enabled": false }
→ 422
```

رفض صريح ومش تجاهل عن قصد: لو رجّعنا 200 على إعداد مش بيعمل حاجة، التطبيق
هيفتكر إنه قفل حاجة وهي مقفلتش — وده بالظبط نوع المشكلة اللي إحنا بنقفلها هنا.

---

## اللي مطلوب في التطبيق

1. توجل «الإشعارات» في شاشة الحساب يبعت `"channel": "push"`.
2. لو فيه أي مكان لسه بيبعت `"channel": "database"` — يتشال.
3. متعرضوش قناة `database` في الشاشة أصلًا. ابنوا الشاشة من الـ `GET` نفسه
   عشان لو زادت قناة في المستقبل تظهر لوحدها.
4. اقروا الـ response بـ `channel` مش بالترتيب (البند اللي فوق).

**مش مطلوب منكم أي migration للمستخدمين الحاليين** — الصفوف القديمة اتمسحت من
الداتابيز من ناحيتنا.

---

## إزاي تتأكدوا

```bash
# لازم يرجّع عنصر واحد بس، channel = push
curl -H "Accept: application/json" -H "Authorization: Bearer <TOKEN>" \
  https://laundo.nahrdev.net/api/v1/notification-preferences

# لازم يرجّع 422
curl -X PUT -H "Accept: application/json" -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"channel":"database","enabled":false}' \
  https://laundo.nahrdev.net/api/v1/notification-preferences

# لازم يرجّع 200
curl -X PUT -H "Accept: application/json" -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"channel":"push","enabled":false}' \
  https://laundo.nahrdev.net/api/v1/notification-preferences
```

واختبار السلوك نفسه: اقفل `push`، اعمل طلب جديد، المفروض **مافيش إشعار ينزل على
التليفون** لكن `GET /api/v1/notifications` يبقى فيه الإشعار.

> استثناء واحد مقصود: الإشعارات **المعاملاتية** بتتخطّى الكتم. «السعر النهائي
> جاهز» بينزل على التليفون حتى لو `push` مقفولة، لأن الطلب بيقف لحد ما العميل
> يأكّد، ولو سكتنا مش هيعرف ليه الطلب واقف. نفس الكلام على «محتاج موعد جديد»
> و«مهمة جديدة» للمندوب.

---

## ليه التغيير ده مش مجرد patch

قناة `database` مكانتش المفروض تبقى قابلة للكتم من الأول. الليستة جوّه التطبيق
هي السجل اللي العميل بيفتحه **من** الإشعار عشان يقرا اللي اتقال له — فالسويتش
اللي بيفضّيها مابيحققش رغبة حد في إشعارات أقل.

وكان فيه عيب تاني جاي من نفس الجذر: حد الإشعارات (٣ إشعارات غير معاملاتية لكل
موضوع في الساعة) بيتحسب بعدّ صفوف `database` المرسلة. اليوزر اللي كاتم `database`
عدّاده كان متجمّد على صفر — يعني **الحد عمره ما اتطبّق عليه**. الوحيد اللي طلب
إشعارات أقل كان الوحيد اللي بيستقبلها من غير أي حد.

---

## مراجع في الكود

| الحاجة | المكان |
| --- | --- |
| القنوات اللي ينفع تتكتم | `NotificationDispatcher::MUTABLE_CHANNELS` |
| قرار الكتم | `NotificationDispatcher::allows()` |
| الـ endpoints | `App\Http\Controllers\Api\V1\NotificationController` |
| مسح الصفوف القديمة | `2026_09_13_120000_drop_unmutable_notification_preferences` |
| الإشعارات المعاملاتية | `NotificationEvent::isTransactional()` |
| التستات | `tests/Feature/Api/NotificationTest.php` |
