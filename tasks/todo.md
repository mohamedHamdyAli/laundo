# شاشة «إيرادات المغاسل» — Laundry Revenue

الهدف: شاشة للسوبر أدمن تعرض لكل مغسلة إجمالي الأوردرات، إجمالي ما دفعه العملاء،
عمولة المنصة، ومستحقات المغسلة — مع إمكانية تسجيل خصم بسبب مكتوب، على نفس فكرة
شاشة Total Revenue المرفقة.

## القرارات المعمارية

- **بند مستقل تحت مجموعة «الفلوس»** (طلب المالك)، مفتاح القائمة `laundry_revenue`.
- **`LaundryRevenue` موديل صلاحيات بلا جدول** — نفس أسلوب `Report::class`
  («Not a table — a permission subject»). يولّد `laundry_revenue.{view,…}`.
- **الخصم كيان حقيقي**: جدول `laundry_deductions` + موديل `LaundryDeduction`.
- **الأرقام تُقرأ من `order_settlements`، لا تُعاد حسابها.** الجدول يخزّن بالفعل
  `basis` / `commission_amount` / `laundry_amount` / `tax_amount` / `status`.
- **لا `withoutGlobalScopes()`**: الـ scopes الحالية هي بوابة السوبر أدمن
  (`currentId() === null` = بلا قيد). لو مُنحت الصلاحية لمالك مغسلة يرى صفه فقط.
- **تاريخ واحد لكل الأعمدة: `orders.created_at`** — حتى يتصالح الصف مع نفسه.
  (تقرير الإيرادات العام يؤرّخ بـ `paid_at` لغرض مختلف؛ لا نخلط الاثنين هنا.)
- **الخصم سجل مطالبات، لا يحرّك المحفظة.** محفظة لاراندو ترفض الرصيد السالب،
  فالتمثيل الأمين هو دفتر خصومات يُطرح من «الصافي المستحق». يُعكس ولا يُحذف.
- **الكتابة خلف `setting.update`** لا `laundry.update` — نفس حدّ العمولة في
  `MoneyBoundaryTest`: الطرف الدافع لا يملك المقبض.

## الأعمدة — من الاسكرين المرجعي إلى لاوندو

| المرجع | لاوندو |
| --- | --- |
| ID / VENDOR | `laundries.id`، الاسم (JSON) + `laundries.email` |
| BRANCHES | **مناطق التغطية** `zones_count` — لا يوجد مفهوم فروع |
| TOTAL BOOKINGS | عدد الأوردرات في المدى |
| COMPLETED / CANCELLED / PENDING | `status=completed` / `cancelled+returned` / الباقي |
| USER PAID | `sum(coalesce(final_total, estimated_total))` حيث `payment_status='paid'` |
| BOOKING VAT | `sum(order_settlements.tax_amount)` |
| VENDOR ENTITLEMENTS | `sum(laundry_amount)` حيث `status <> 'cancelled'` |
| VENDOR GETS | `sum(laundry_amount)` حيث `status = 'settled'` |
| PLATFORM COMMISSION | `sum(commission_amount)` حيث `status <> 'cancelled'` |
| DEDUCTION / REASON | `laundry_deductions` حيث `status='applied'` |
| ACTIONS | ✂ إضافة خصم · ↺ عكس الخصومات |

## الملفات

### إنشاء
- [ ] `database/migrations/…_create_laundry_deductions_table.php`
- [ ] `app/Modules/Payment/Models/LaundryDeduction.php` (+ `BelongsToLaundry`)
- [ ] `app/Modules/Payment/Models/LaundryRevenue.php` (موديل صلاحيات بلا جدول)
- [ ] `app/Modules/Payment/Repositories/LaundryRevenueRepository.php` (الاستعلامات الخام)
- [ ] `app/Modules/Payment/Services/LaundryRevenueService.php` (`shredData`-شكل: rows + summary)
- [ ] `app/Modules/Payment/Controllers/LaundryRevenueController.php`
      (`index`, `search`, `deduct`, `reverse`, `export`)
- [ ] `app/Modules/Payment/Requests/LaundryDeductionRequest.php`
- [ ] `resources/views/admin/laundry_revenue/index.blade.php`
- [ ] `resources/views/admin/laundry_revenue/partials/_laundry_revenue_table_body.blade.php`
- [ ] `tests/Feature/Dashboard/LaundryRevenueTest.php`

### تعديل
- [ ] `config/dashboard.php` — تسجيل `LaundryRevenue::class`
- [ ] `config/menu.php` — `groups.money.items.laundry_revenue` + `icons`/`titles`/`routes`
- [ ] `resources/lang/ar.json` — عنوان القائمة وكل نص `__()` جديد
- [ ] `routes/web.php` — مجموعة `LaundryRevenueController` بالصلاحيات
- [ ] `Changelog.md`

### تشغيل
- [ ] `php artisan migrate`
- [ ] `php artisan db:seed --class=PermissionSeeder`

## الفخاخ الواجب تفاديها

- `laundries.name` عمود **json** → البحث يمر عبر `Searchable` (طيّ الحالة).
- البحث يُقرأ `$request->get('query')` — أبداً `$request->query`.
- `search()` داخل `if ($request->ajax())` وإلا 200 فارغ.
- `stack-head` و `data-stack` يأخذان نفس `--stack-cols`؛ `errorHtml` بدل `colspan`.
- `extraParams` **دالة** لا كائن، وإلا تجمّد قيمة الفلتر عند تحميل الصفحة.
- `permission="…"` نص حرفي بلا `:` في مكوّنات Blade.
- `moneyFormat()` للمبالغ، `humanDate()` للتواريخ (التحويل الزمني عند العرض فقط).
- مودال الخصم **بلا `needs-validation`** (نمط مودال العمولة).
- معالج الزر **مفوَّض** `$(document).on('click', …)` لأن البحث يستبدل الصفوف.
- `TranslationCoverageTest` يكسر البناء على عنوان قائمة بلا ترجمة عربية.
- `SearchWiringTest` يتحقق أن كل selector يطابق عنصراً في نفس الـ view.

## المراجعة
(تُملأ بعد التنفيذ)

---

## المراجعة (تم التنفيذ 2026-09-16)

كل بنود الخطة اتنفذت. الصفحة شغالة على `/admin/laundry-revenue` كبند «إيرادات
المغاسل» تحت مجموعة «الفلوس».

### التحقق
- **1334 اختبار / 4326 تأكيد — كلها خضراء** (المجموعة الكاملة، ~6.5 دقيقة)،
  منها 20 اختبار جديد في `LaundryRevenueTest`.
- **PHPStan level 5: نظيف.** Pint: نظيف.
- **الصفحة اتجربت فعليًا** بالمتصفح إنجليزي وعربي: الكروت، الفلاتر، البحث
  AJAX، مودال الخصم، التراجع، و RTL.
- أرقام محسوبة يدويًا وطابقت: أساس 2100 → عمولة 210، مستحق 1890، محصّل 810،
  ضريبة 294. بعد خصم 60 → الصافي 750 والكارت 1830.

### أخطاء اتلقطت أثناء التجربة الحية (مكنش فيه اختبار يمسكها)
1. `fa fa-scissors` مش موجودة في Font Awesome 5 — الزر كان مربع فاضي. بقت `fa fa-cut`.
2. المودال مكنش بيفتح: الزر بيملأ الفورم بس مفيش حاجة بتنادي Bootstrap.
   اتظبط بـ `bootstrap.Modal.getOrCreateInstance(...).show()` زي مودال العمولة.
3. **صيغ الجمع العربية كانت بترجّع الصيغة الغلط.** لارافيل بيرجّع index من 0 لـ 5
   للعربي، والترجمة بصيغتين مفيهاش حاجة في 2–5، فبيقع على صيغة 0 لكل عدد ما عدا
   1 اللي بيقع على صيغة 1. يعني الترتيب العربي لازم يكون
   **[الصيغة العامة] | [صيغة الواحد]** — عكس الإنجليزي. «25 areas» كانت بتطلع
   «منطقة واحدة».
4. رسالة التأكيد كانت «كل 1 خصومات» — بقت `trans_choice`.

### قرارات اتاخدت وقت التنفيذ
- **`LaundryRevenue` موديل صلاحيات بلا جدول**، نفس أسلوب `Report::class`.
  الخصم كيان حقيقي منفصل (`laundry_deductions`).
- **العرض `laundry_revenue.view` / الكتابة `setting.update`** — الطرف الدافع
  مايملكش المقبض.
- **الخصم مش بيحرّك المحفظة**: `WalletService` بيرفض السحب لو الرصيد مايكفيش،
  والمحفظة مافيهاش رصيد سالب. فالخصم مطالبة بتتطرح من الصافي المستحق.
- الصف بيسمح بصافي سالب (حالة حقيقية لازم حد يشوفها)، والكارت بيتفرمل عند صفر.

### مطلوب عند النشر
```bash
php artisan migrate
php artisan db:seed --class=PermissionSeeder
php artisan route:clear && php artisan config:clear
```
وبعدها إدي صلاحية `laundry_revenue.view` لأي دور غير السوبر أدمن محتاجها.

### اللي اتساب بره عن قصد
- الخصم مايكتبش في `wallet_transactions` (السبب فوق). لو مطلوب إن الفلوس تتسحب
  فعليًا من محفظة صاحب المغسلة، ده شغل تاني محتاج حالة «رصيد مستحق للمنصة».
- عمود «الفروع» في الاسكرين المرجعي اتحوّل لـ «مناطق التغطية» — لاوندو مفيهاش فروع.
