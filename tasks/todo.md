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
