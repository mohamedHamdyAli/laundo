# توثيق المشروع - Clean-X

## 📋 نظرة عامة على المشروع

### نوع المشروع
هذا مشروع **Laravel 12** لإدارة لوحة تحكم إدارية (Admin Panel) متعددة اللغات مع دعم كامل للترجمة والتحكم في المحتوى.

### المجال التجاري (Business Domain)
المشروع عبارة عن نظام إدارة محتوى (CMS) متعدد اللغات يتضمن:
- إدارة المستخدمين والصلاحيات
- إدارة اللغات والترجمات (Panel, Mobile, Web)
- إدارة الفئات (Categories) مع دعم الفئات الفرعية
- إدارة البنرات (Banners)
- إدارة صفحات التعريف (Intros)
- إدارة الدول والمدن (Countries & Cities)
- إدارة الإعدادات العامة (Settings)
- نظام متعدد اللغات مع دعم RTL

---

## 🏗️ البنية المعمارية (Architecture)

### نمط التصميم المعماري
المشروع يستخدم **Modular Architecture** مع تطبيق نمط **Repository Pattern** و **Service Layer Pattern**.

### هيكل المشروع

```
app/
├── Helpers/              # Helper Functions (Auto-loaded)
│   ├── Helpers.php      # Helper functions عامة
│   ├── ApiResponse.php  # Response helpers للـ API
│   └── LanguageHelper.php # Language management helpers
│
├── Http/
│   ├── Controllers/     # Controllers عامة
│   │   ├── HomeController.php
│   │   └── Admin/
│   │       └── LanguageController.php
│   ├── Middleware/      # Custom Middleware
│   │   ├── Authenticate.php
│   │   ├── SetLocale.php
│   │   └── UserRoleMiddleware.php
│   └── Requests/        # Form Requests
│
├── Models/              # Models عامة
│   └── Language.php
│
├── Modules/             # ⭐ الوحدات النمطية (Modular Structure)
│   ├── Banner/
│   ├── Category/
│   ├── City/
│   ├── Country/
│   ├── Intro/
│   ├── Setting/
│   └── User/
│
├── Services/            # Services عامة
│   └── ResponseService.php
│
└── Trait/
    └── Scopes/
        └── Searchable.php
```

### هيكل الوحدة النمطية (Module Structure)
كل وحدة نمطية تحتوي على:

```
ModuleName/
├── Controllers/
│   └── ModuleController.php    # Controller للوحدة
├── Models/
│   └── Module.php              # Eloquent Model
├── Repositories/
│   └── ModuleRepository.php   # Repository Pattern
├── Services/
│   └── moduleCrudService.php   # Business Logic
└── Requests/
    └── ModuleRequest.php       # Form Validation
```

---

## 🎯 أنماط البرمجة والاتفاقيات (Coding Patterns & Conventions)

### 1. Repository Pattern
- **الغرض**: فصل منطق الوصول للبيانات عن منطق الأعمال
- **الاستخدام**: جميع عمليات قاعدة البيانات تتم عبر Repository
- **مثال**:
```php
// Repository
class CategoryRepository {
    public function findById($id) {
        return Category::findOrFail($id);
    }
    
    public function create(array $data) {
        return Category::create($data);
    }
}
```

### 2. Service Layer Pattern
- **الغرض**: احتواء منطق الأعمال (Business Logic)
- **الاستخدام**: جميع العمليات المعقدة تتم في Service
- **مثال**:
```php
// Service
class categoryCrudService {
    public function addNew(array $request) {
        return DB::transaction(function () use ($request) {
            $request['image'] = uploadOrUpdateImage(...);
            $request['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
            return $this->categoryRepository->create($request);
        });
    }
}
```

### 3. Form Request Validation
- **الغرض**: فصل قواعد التحقق (Validation Rules)
- **الاستخدام**: كل Controller يستخدم Request class منفصلة
- **مثال**:
```php
class CategoryRequest extends FormRequest {
    public function rules(): array {
        if ($this->getMethod() === 'PUT') {
            return ['name' => 'nullable|array', ...];
        }
        return ['name' => 'required|array', ...];
    }
}
```

### 4. Dependency Injection
- **الاستخدام**: Constructor Injection في Controllers و Services
- **مثال**:
```php
class CategoryController {
    public function __construct(
        private readonly categoryCrudService $categoryCrudService
    ) {}
}
```

### 5. Database Transactions
- **الاستخدام**: جميع عمليات الكتابة تتم داخل `DB::transaction()`
- **الغرض**: ضمان تكامل البيانات (Data Integrity)

### 6. Helper Functions
- **الموقع**: `app/Helpers/Helpers.php`
- **Auto-loading**: يتم تحميلها تلقائياً عبر `composer.json`
- **الاستخدام**: Functions عامة مثل:
  - `uploadOrUpdateImage()` - رفع الصور
  - `getCurrentLocale()` - الحصول على اللغة الحالية
  - `getLocalizedValue()` - الحصول على القيمة المترجمة
  - `getSettingValue()` - الحصول على قيمة الإعدادات

---

## 🌍 نظام متعدد اللغات (Multi-Language System)

### الميزات
1. **دعم RTL**: دعم اللغات من اليمين لليسار
2. **ثلاثة أنواع من ملفات الترجمة**:
   - `{code}.json` - Panel translations
   - `{code}_mobile.json` - Mobile app translations
   - `{code}_web.json` - Web translations
3. **Cache System**: جميع اللغات مخزنة في Cache
4. **JSON Storage**: الترجمة مخزنة في JSON files في `resources/lang/`

### آلية العمل
- **Middleware**: `SetLocale` middleware يحدد اللغة من Session
- **Helper Functions**: 
  - `getCurrentLocale()` - الحصول على اللغة الحالية
  - `getLocalizedValue($model, $attribute)` - للحصول على القيمة المترجمة
  - `clearLanguageCache()` - مسح Cache اللغات

### نماذج البيانات متعددة اللغات
- **التخزين**: JSON في قاعدة البيانات
- **مثال**: `name` column يحتوي على `{"en": "Category", "ar": "فئة"}`
- **الوصول**: عبر Accessor في Model
```php
public function getNameAttribute($value) {
    return json_decode((string) $value);
}
```

---

## 📦 الوحدات النمطية (Modules)

### 1. User Module
- **الوظيفة**: إدارة المستخدمين
- **الميزات**: 
  - CRUD operations
  - Status toggle
  - Role management (admin/user)
  - Search functionality

### 2. Language Module
- **الوظيفة**: إدارة اللغات والترجمات
- **الميزات**:
  - إضافة/تعديل/حذف اللغات
  - إدارة ملفات الترجمة (Panel, Mobile, Web)
  - تحميل ملفات JSON
  - تعيين اللغة الافتراضية
  - دعم RTL

### 3. Category Module
- **الوظيفة**: إدارة الفئات
- **الميزات**:
  - دعم الفئات الهرمية (Parent-Child)
  - رفع الصور
  - Status management
  - عرض الفئات الفرعية

### 4. Banner Module
- **الوظيفة**: إدارة البنرات الإعلانية
- **الميزات**: CRUD operations مع رفع الصور

### 5. Intro Module
- **الوظيفة**: إدارة صفحات التعريف
- **الميزات**: CRUD operations

### 6. Country & City Modules
- **الوظيفة**: إدارة الدول والمدن
- **الميزات**: CRUD operations مع علاقة Country-City

### 7. Setting Module
- **الوظيفة**: إدارة الإعدادات العامة
- **الميزات**:
  - General Settings
  - Privacy & Terms
  - Social Media Links
  - Cache-based retrieval

---

## 🔧 التقنيات المستخدمة (Tech Stack)

### Backend
- **Framework**: Laravel 12
- **PHP Version**: ^8.2
- **Database**: MySQL (افتراضي)
- **ORM**: Eloquent

### Frontend
- **CSS Framework**: Bootstrap 5.2.3
- **CSS Preprocessor**: Sass
- **JavaScript**: Vanilla JS + Axios
- **Build Tool**: Vite 6.2.4
- **UI Framework**: Tailwind CSS 4.0.0

### Development Tools
- **Code Quality**: 
  - PHPStan (Level 5)
  - PHP CS Fixer
  - Laravel Pint
  - Rector
- **Testing**: PHPUnit
- **IDE Helper**: Laravel IDE Helper

### Packages
- `laravel/ui` - Authentication scaffolding
- `barryvdh/laravel-ide-helper` - IDE autocompletion

---

## 📝 اتفاقيات كتابة الكود (Coding Conventions)

### 1. تسمية الملفات والكلاسات
- **Controllers**: `PascalCase` (مثال: `CategoryController.php`)
- **Models**: `PascalCase` (مثال: `Category.php`)
- **Services**: `camelCase` (مثال: `categoryCrudService.php`)
- **Repositories**: `PascalCase` (مثال: `CategoryRepository.php`)
- **Requests**: `PascalCase` (مثال: `CategoryRequest.php`)

### 2. تسمية الدوال والمتغيرات
- **Functions**: `camelCase` (مثال: `uploadOrUpdateImage()`)
- **Variables**: `camelCase` (مثال: `$categoryRepository`)
- **Constants**: `UPPER_SNAKE_CASE` (مثال: `CACHE_LANGUAGE`)

### 3. تسمية Routes
- **Pattern**: `admin.{module}.{action}`
- **مثال**: `admin.category.index`, `admin.category.store`

### 4. Response Format
- **Success**: 
```php
return redirect()->route('admin.category.index')
    ->with('success', __('Added Successfully'));
```
- **AJAX Response**:
```php
return response()->json([
    'table' => $table,
    'pagination' => $pagination
]);
```

### 5. Database Conventions
- **Table Names**: `snake_case`, plural (مثال: `categories`)
- **Column Names**: `snake_case` (مثال: `parent_id`, `created_at`)
- **Foreign Keys**: `{table}_id` (مثال: `category_id`)

### 6. Status Management
- **Enum Values**: `active`, `inactive`
- **Toggle Method**: `toggleStatus()` في Service

### 7. Image Handling
- **Upload Function**: `uploadOrUpdateImage($image, $directory, $existingPath)`
- **Delete Function**: `DeleteImage($path)`
- **Storage**: `public` disk
- **Path Pattern**: `images/{module}/{type}`

---

## 🔐 نظام المصادقة والصلاحيات (Authentication & Authorization)

### Authentication
- **Driver**: Laravel Default (Session-based)
- **Middleware**: `auth` middleware
- **Routes**: محمية بـ `Route::middleware(['auth', 'user-role:admin'])`

### Authorization
- **Middleware**: `UserRoleMiddleware`
- **Roles**: `admin`, `user`
- **Usage**: `user-role:admin` في routes

### User Model
- **Fields**: `name`, `email`, `phone`, `role`, `image_profile`, `status`
- **Hidden**: `password`, `remember_token`
- **Scopes**: `scopeAvailableUsers()` - للحصول على المستخدمين فقط

---

## 💾 قاعدة البيانات (Database)

### Migrations
- **Location**: `database/migrations/`
- **Naming**: `YYYY_MM_DD_HHMMSS_create_{table}_table.php`
- **Features**: 
  - Soft Deletes (حيث مطلوب)
  - Foreign Keys
  - Indexes

### Models
- **Traits**: 
  - `Searchable` - للبحث في النماذج
  - `SoftDeletes` - للحذف الناعم (حيث مطلوب)
- **Relationships**: 
  - `belongsTo`, `hasMany`, `hasOne`
  - مثال: `Category::parent()`, `Category::children()`

### Caching Strategy
- **Language Cache**: `rememberForever()` للغات
- **Settings Cache**: `rememberForever()` للإعدادات
- **Cache Keys**: 
  - `default_language`
  - `available_locales`
  - `setting_{key}`

---

## 🎨 الواجهة الأمامية (Frontend)

### Views Structure
```
resources/views/
├── admin/              # Admin panel views
│   ├── {module}/
│   │   ├── index.blade.php
│   │   ├── create.blade.php
│   │   ├── edit.blade.php
│   │   ├── show.blade.php
│   │   ├── forms/
│   │   ├── partials/
│   │   └── shared/
│   └── ...
├── auth/              # Authentication views
├── components/        # Reusable components
└── layouts/          # Layout files
```

### Components
- `action-buttons.blade.php` - أزرار الإجراءات
- `action-button-lang.blade.php` - أزرار اللغة
- `status-toggle-button.blade.php` - زر تبديل الحالة

### Layouts
- `main.blade.php` - Layout الرئيسي
- `sidebar.blade.php` - القائمة الجانبية
- `topbar.blade.php` - الشريط العلوي
- `footer.blade.php` - التذييل

---

## 🚀 العمليات الشائعة (Common Operations)

### 1. إضافة وحدة جديدة
1. إنشاء مجلد في `app/Modules/{ModuleName}/`
2. إنشاء Controller, Model, Repository, Service, Request
3. إضافة Routes في `routes/web.php`
4. إنشاء Migrations
5. إنشاء Views

### 2. إضافة حقل متعدد اللغات
1. إضافة column في Migration (JSON type)
2. إضافة في `$fillable` في Model
3. إضافة Accessor في Model:
```php
public function getNameAttribute($value) {
    return json_decode((string) $value);
}
```
4. في Service: `json_encode($request['name'], JSON_UNESCAPED_UNICODE)`

### 3. إضافة صورة
1. استخدام `uploadOrUpdateImage()` في Service
2. إضافة validation في Request: `'image' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048'`
3. استخدام `DeleteImage()` عند الحذف

### 4. إضافة Search
1. استخدام `Searchable` trait في Model
2. في Repository: `Model::search($query, ['column1', 'column2'])->paginate()`

---

## 📚 Helper Functions المرجعية

### Image Helpers
- `uploadOrUpdateImage($image, $directory, $existingPath)` - رفع أو تحديث صورة
- `DeleteImage($path)` - حذف صورة
- `getImageDashboardUrl($url)` - الحصول على رابط الصورة للـ dashboard
- `getImageassetUrl($urls)` - الحصول على رابط الصورة

### Language Helpers
- `getCurrentLocale()` - الحصول على اللغة الحالية
- `getLocalizedValue($model, $attribute)` - الحصول على القيمة المترجمة (API)
- `getLocalizedValueDashboard($model, $attribute)` - للحصول على القيمة المترجمة (Dashboard)
- `getDefaultLanguage($col_name)` - الحصول على اللغة الافتراضية
- `getAllLanguageWithoutDefault()` - الحصول على جميع اللغات عدا الافتراضية
- `clearLanguageCache($code)` - مسح Cache اللغة
- `rebuildLanguageCache()` - إعادة بناء Cache اللغات

### Settings Helpers
- `getSettingValue($key)` - الحصول على قيمة الإعداد (مع Cache)

### Validation Helpers
- `failedValidation($validator)` - إرجاع خطأ التحقق المخصص

### Utility Helpers
- `formatFileSize($bytes)` - تنسيق حجم الملف
- `humanDate($date, $format)` - تنسيق التاريخ
- `randomCode($length)` - إنشاء كود عشوائي
- `moneyFormat($amount, $currency)` - تنسيق المال

---

## 🔄 Response Service

### Methods
- `ResponseService::successResponse($message, $data, $customData, $code)` - Response نجاح
- `ResponseService::errorResponse($message, $data, $code, $e)` - Response خطأ
- `ResponseService::validationError($message, $data)` - خطأ التحقق
- `ResponseService::logErrorResponse($e, $logMessage, $responseMessage, $jsonResponse)` - تسجيل خطأ
- `ResponseService::toggleStatus($model, $status)` - تبديل الحالة

---

## 📋 API Response Format

### Success Response
```json
{
    "key": "success",
    "data": {},
    "msg": "Message",
    "code": 200
}
```

### Error Response
```json
{
    "key": "fail",
    "msg": "Error message",
    "code": 400
}
```

### Validation Error
```json
{
    "key": "Invalid data sent",
    "msg": "Error message",
    "code": 422
}
```

---

## 🛠️ Development Workflow

### Running the Project
```bash
# Install dependencies
composer install
npm install

# Run development server
composer dev  # Runs server, queue, logs, and vite concurrently
```

### Code Quality
```bash
# PHPStan analysis
composer stan

# Code formatting
./vendor/bin/pint

# Tests
composer test
```

### Cache Management
- **Clear Cache**: `php artisan cache:clear`
- **Clear Config**: `php artisan config:clear`
- **Clear Language Cache**: استخدام `clearLanguageCache()` helper

---

## 📌 ملاحظات مهمة

1. **Transactions**: جميع عمليات الكتابة يجب أن تكون داخل `DB::transaction()`
2. **Caching**: اللغات والإعدادات مخزنة في Cache بشكل دائم
3. **JSON Encoding**: استخدام `JSON_UNESCAPED_UNICODE` عند encoding JSON
4. **Image Validation**: حجم الصورة لا يتجاوز 5MB
5. **Status Values**: فقط `active` أو `inactive`
6. **Localization**: جميع النصوص يجب أن تكون قابلة للترجمة
7. **Soft Deletes**: استخدام Soft Deletes حيث مطلوب
8. **Search**: استخدام `Searchable` trait للبحث

---

## 🔍 البحث في الكود

### استخدام Searchable Trait
```php
// في Model
use Searchable;

// في Repository
public function search($query, $perPage = 10) {
    return Model::search($query, ['name', 'description'])->paginate($perPage);
}
```

---

## 📞 الاتصال والمساعدة

هذا الملف يعمل كمرجع شامل لفهم المشروع، بنيته المعمارية، وأنماط البرمجة المستخدمة. استخدمه كدليل عند إضافة ميزات جديدة أو تعديل الكود الموجود.

---

**آخر تحديث**: تم إنشاء هذا التوثيق بعد تحليل شامل للمشروع
**الإصدار**: 1.0.0
