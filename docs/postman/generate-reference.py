# -*- coding: utf-8 -*-
"""يبني صفحة مرجع الـ API بالعربي.

البارامترات متاخدة من قواعد التحقق الموجودة فعلاً في FormRequests والكنترولرز،
عشان الصفحة ماتوصفش حاجة الكود بطّل يعملها.
"""
import io

OUT = 'docs/api-reference.html'


def P(name, req, typ, note=''):
    return (name, req, typ, note)


GROUPS = [
("الصحة", "Health", "نداءان بلا أي أثر جانبي، مفيدين كاختبار سريع إن الخدمة شغالة.", [
  ("GET", "/ping", None, "الخدمة شغالة ولا لأ، وبترجّع وقت السيرفر.", [], []),
  ("GET", "/me", "any", "التوكن ده بتاع مين — id، الاسم، الموبايل، الإيميل، الدور، الحالة.", [],
   ["أصغر من <code>/profile</code> عن قصد. ده بيجاوب «التوكن لسه صالح وأنا مين؟» في نداء واحد عند فتح التطبيق، و<code>/profile</code> شاشة الحساب."]),
]),

("المحتوى والإعدادات", "Content &amp; settings", "عامة بلا توكن. كل اللي التطبيق محتاجه قبل ما حد يسجّل.", [
  ("GET", "/languages", None, "اللغات المفعّلة، والافتراضية معلّمة، والاتجاه RTL محدد.", [], []),
  ("GET", "/translations/{type}", None, "نصوص مرفوعة من الداشبورد، عشان الكلام يتغيّر من غير إصدار جديد.",
   [P("type", "path", "app | web", "مجموعة مقفولة. <code>panel</code> مرفوض — ده ملف الداشبورد نفسه بألف نص."),
    P("code", "query", "string", "كود اللغة. لو مبعتّهوش بياخد لغة الطلب.")],
   ["<code>strings</code> فاضية ده رد طبيعي مش خطأ: تطبيق مرفوعلوش نصوص بيرجع لنصوصه المدمجة."]),
  ("GET", "/banners", None, "الكاروسيل الكبير فوق في الهوم.", [],
   ["كل بانر معاه <code>target_type</code> (<code>none</code> | <code>service</code> | <code>coupon</code>) و<code>target_value</code>. الاتنين دول اللي بيخلّوا الكارت قابل للضغط؛ بانر بلا وجهة يبقى صورة.",
    "مرتّب بـ<code>sort_order</code> ثم <code>id</code>. كان <code>latest('id')</code>، يعني الأحدث دايمًا الأول ومفيش طريقة تترتب من اللوحة."]),
  ("GET", "/offers", None, "كاروسيل «عروض متميزة» تحت في الهوم.", [],
   ["<strong>endpoint منفصلة عن <code>/banners</code> عن قصد.</strong> الهوم فيها كاروسيلين بشكلين كروت مختلفين، وقايمة واحدة مسطّحة كانت بتخلّي التطبيق يخمّن البانر ده بيروح فين والأوبريشن مش قادرة تنقل كارت من واحد للتاني.",
    "بيرجّع العروض السارية بس: نشطة، وجوه <code>starts_at</code>/<code>ends_at</code> لو محدّدين. العرض الموسمي بيختفي لوحده.",
    "<code>badge</code> هي شارة «خصم 20%»، <strong>مشتقة من الكوبون المرتبط</strong> مش مكتوبة باليد — فمستحيل تقول 20% والكود يدي 15. وبترجع <code>null</code> لو مفيش كوبون <strong>أو</strong> لو الكوبون هيترفض (موقوف، بره تاريخه، أو مستنفد)، فالكارت عمره ما يعلن خصم الكاشير هيرفضه.",
    "<code>action</code> نفس شكل <code>/banners</code>: <code>{type, value}</code> أو <code>null</code>. و<code>ends_at</code> بصيغة ISO 8601 أو <code>null</code>، لـ«ينتهي بعد …»."]),
  ("GET", "/intros", None, "شاشات الترحيب.", [],
   ["مرتّبة بـ<code>order</code> ثم <code>id</code>، فترتيب النقاط تحت الشرائح مضمون ومابيتغيّرش بين زيارة وزيارة."]),
  ("GET", "/journey-steps", None, "كروت «رحلتك معنا بسيطة» في الهوم.", [],
   ["منفصلة عن <code>/intros</code> عن قصد وإن كان الشكل قريب: الأونبوردينج تسلسل شاشة كاملة بيتمرّر مرة واحدة قبل وجود حساب، ودول كروت في الشاشة اللي بتتفتح كل يوم.",
    "<strong>الرقم اللي جانب كل كارت مش في الرد.</strong> هو الترتيب في الأراي، فالتطبيق بيرقّم اللي وصله — لو كان عمود كان ممكن الـ«3» توصل تانية.",
    "مرتّبة بـ<code>sort_order</code> ثم <code>id</code>، والنشطة بس."]),
  ("GET", "/app-settings", None, "بيانات التواصل، العملة، الضريبة، أرقام الدعم، السوشيال.",
   [], ["قايمة مسموح بيها بالاسم، مش جدول الإعدادات مصبوب كله. <code>Country_Id</code> والضريبة الداخلية وصور الأغلفة مستبعدين عن قصد.",
        "<code>currency</code> كود ISO من تلات حروف (<code>EGP</code> افتراضيًا)، بيتظبط من اللوحة. التطبيق يفورمِت أسعاره بيه — تخمين مختلف عن اللوحة معناه سعر العميل شايفه بشكلين."]),
  ("GET", "/pages/{page}", None, "صفحة نصية واحدة في المرة.",
   [P("page", "path", "about | privacy | terms", "أي حاجة تانية 404.")],
   ["منفصلة عن <code>/app-settings</code> لأن كل واحدة حيطة HTML، وشاشة الحساب مش بتحتاج غير واحدة."]),
  ("GET", "/faqs", None, "«الأسئلة الشائعة».",
   [P("audience", "query", "customer | driver", "اختياري.")],
   ["من غير <code>audience</code> بيرجّع الكل بدل ما يخمّن من التوكن. المندوب اللي بيسأل بيقبض إمتى والعميل اللي بيسأل هياخد هدومه إمتى مينفعش يقروا قايمة بعض."]),
  ("GET", "/complaint-categories", None, "المجموعة المقفولة لفورم الشكوى.", [],
   ["<code dir=\"ltr\">damaged_item · missing_item · not_clean · late · driver_conduct · payment · app_problem · other</code>"]),
  ("GET", "/referral-terms", None, "كود الدعوة بيدي إيه، قبل ما يبقى فيه حساب أصلاً.", [],
   ["<code>active: false</code> لما مفيش مكافأة متظبّطة — ساعتها اخفي الحقل بدل ما توعد بخصم مش هييجي."]),
]),

("الكتالوج", "Catalogue", "عامة. الخدمات وأسعار القطع.", [
  ("GET", "/services", None, "الخدمات الأربعة بمدة كل واحدة وطريقة تسعيرها.", [],
   ["<code>pricing_mode</code> إما <code>per_item</code> أو <code>quote</code>. الخدمة التقديرية («تنظيف جاف») ملهاش أسعار خالص — بتتسعّر بعد معاينة القطع."]),
  ("GET", "/catalog", None, "الفئات والقطع وسعر كل قطعة في كل خدمة.",
   [P("service_id", "query", "int", "خدمة واحدة بس. لازم تكون موجودة."),
    P("category_id", "query", "int", "فئة واحدة بس، عبر الخدمات الراجعة.")],
   ["من غير فلتر بيرجّع الجريد كامل، وده اللي شاشة «الاسعار» عايزاه: العميل بيقارن الخدمات ببعض، ونداء لكل خدمة يبقى نداء لكل ضغطة.",
    "الفلاتر للويزارد، اللي عارف الخدمة أصلاً ومحتاج عمود واحد مش الجدول كله. والاتنين بيتجمعوا.",
    "<strong>id مش موجود = 422</strong>، مش رجوع صامت للكل: توسيع الفلتر في صمت هو اللي بيخلّي سعر تنظيف جاف يظهر في شاشة غسيل وكي.",
    "خدمة تقديرية بترجع لوحدها بـ<code>categories</code> فاضية — مفيش أسعار ده الرد الصح ليها، مش خدمة ناقصة."]),
]),

("المناطق والمواعيد", "Geography &amp; scheduling", "عامة. اللي فورم العنوان وخطوة الموعد محتاجينه.", [
  ("GET", "/cities", None, "المدن المفعّلة بمناطقها.",
   [P("city_id", "query", "int", "مدينة واحدة بس. لازم تكون موجودة.")],
   ["الافتراضي بيملا الـdropdown الاتنين في نداء واحد: بيتملوا مع بعض، ونداء تاني بين اختيار المدينة وظهور المناطق يبقى spinner في نص الفورم.",
    "<code>city_id</code> للحالة اللي العميل فيها عارف المدينة — عنوان محفوظ بيتعدّل، أو فورم بيعيد تحميل المناطق بعد ما المدينة اتغيّرت.",
    "مدينة موجودة بس <code>inactive</code> بترجّع قايمة فاضية، فـ<code>city_id</code> ماينفعش يوصل لمنطقة وقفنا نخدمها."]),
  ("GET", "/time-slots", None, "النوافذ اللي العميل يقدر يختار إن المندوب ييجيله فيها.",
   [P("type", "query", "pickup | delivery", "اختياري. بيرجّع نوافذ الطرف ده زائد كل نافذة <code>both</code>."),
    P("date", "query", "date", "لو بعتّه بترجع <code>remaining</code> و<code>is_full</code> لليوم ده.")],
   ["<strong>دي مواعيد زيارة المنصة، مش مواعيد عمل المغسلة.</strong> المغسلة ملهاش مواعيد عمل في النظام أصلاً. النافذة هي التلات ساعات اللي <em>المندوب</em> هييجي فيها للعميل، والعميل هو اللي بيختارها — «02:00 مساءً – 05:00 مساءً» زي ما في الديزاين.",
    "نوافذ مش وقت بالدقيقة لأن المندوب ماشي على خط سير ومايقدرش يوعد بـ16:07. خمسة بيغطّوا اليوم: 09–12، 12–15، 15–18، 18–21، 21–24.",
    "كل نافذة معاها <code>applies_to</code> — <code>pickup</code> أو <code>delivery</code> أو <code>both</code> — وطلب إنشاء الأوردر بيطبّقها، فنافذة تسليم بس ماينفعش تتبعت كاستلام.",
    "النافذة المختارة بتعمل حاجتين: بتقول للعميل يستنى إمتى، وبتحدد <code>due_at</code> على الرجل — وده المعنى الوحيد لـ«متأخرة» في تطبيق المندوب.",
    "<strong>السعة بقت مطبّقة.</strong> استلام وتسليم في نفس النافذة = زيارتين لبابين، فالاتنين بيتحسبوا. الطلب الملغي بيرجّع مكانه. <code>remaining: null</code> معناها بلا حد، و<code>0</code> معناها اختار نافذة تانية — والاتنين لازم يترسموا مختلفين."]),
]),

("تسجيل الدخول — العميل", "Auth — customer", "عامة، وكل واحدة ليها حد استخدام لوحدها.", [
  ("POST", "/auth/register", None, "بينشئ الحساب ويبعت أول كود.",
   [P("name", "مطلوب", "string", "٬191 حرف كحد أقصى"),
    P("phone", "مطلوب", "string", "E.164 مع مفتاح الدولة، <code dir=\"ltr\">+201012345678</code>. أي دولة، والمفتاح إلزامي. وفريد"),
    P("email", "اختياري", "email", "فريد لو اتبعت"),
    P("zone_id", "اختياري", "int", "لازم يكون موجود"),
    P("password", "مطلوب", "string", "8 على الأقل، ومعاه <code>password_confirmation</code>"),
    P("accepted_terms", "مطلوب", "boolean", "لازم يكون صح"),
    P("referral_code", "اختياري", "string", "24 حرف — «رمز الدعوة»")],
   ["الحساب مش هيقدر يسجّل دخول قبل تأكيد الكود.",
    "<strong>الرقم دولي:</strong> المفتاح إلزامي وأي دولة مقبولة. <code dir=\"ltr\">01012345678</code> بيترفض بـ422 — رقم يُقرأ كمصري وإيطالي في نفس الوقت مينفعش يكون هوية حساب.",
    "<code>accepted_terms</code> بقى <strong>يُسجَّل</strong> في <code>accepted_terms_at</code> بلحظته، مش بيتحقق منه ويترمي. الديزاين محتاج checkbox صريح — لينكات الفوتر لوحدها مش موافقة.",
    "<strong>خصم واحد للطلب.</strong> العميل يا بيكتب برومو كود، يا بيدخل من كارت «عروض متميزة» في الهوم — مش الاتنين. الأوفر بيغلب لأنه اللي الكارت وعده بيه، والكود اللي اتكتب بيترفض <strong>برسالة</strong> مش بيتجاهل في صمت: إن العميل يتحاسب بسعر الأوفر وهو فاكر إن خصم تاني اتطبّق أسوأ النتايج التلاتة. والقيد على <strong>خصمين</strong> مش على العروض: أوفر بيشاور على خدمة مالوش كوبون، فبرومو كود جانبه ماشي.",
    "<code>referral_code</code> <strong>مش</strong> بيتفحص في جدول المستخدمين: كود مكتوب غلط ماينفعش يمنع تسجيل، وتأكيد إن كود موجود ده طريقة للتخمين. الكود المجهول بيتجاهل في صمت."]),
  ("POST", "/auth/verify-otp", None, "بيأكد الكود ويرجّع التوكن.",
   [P("phone", "مطلوب", "string", ""), P("code", "مطلوب", "string", "6 أرقام")], []),
  ("POST", "/auth/resend-otp", None, "بيبعت كود جديد.", [P("phone", "مطلوب", "string", "")],
   ["الحد <code>otp</code>. البعت رخيص على المهاجم وغالي علينا، فمحدود بشكل منفصل عن التحقق."]),
  ("POST", "/auth/login", None, "موبايل وباسورد، بيرجّع توكن Sanctum.",
   [P("phone", "مطلوب", "string", ""), P("password", "مطلوب", "string", "")],
   ["مرفوض لموبايل غير مؤكد ولحساب موقوف، والاتنين بيتبلّغوا منفصلين عشان التطبيق يقدر يقول حاجة مفيدة."]),
  ("POST", "/auth/forgot-password", None, "بيبعت كود إعادة تعيين.", [P("phone", "مطلوب", "string", "")], []),
  ("POST", "/auth/verify-reset-code", None, "بيأكد كود الاستعادة ويرجّع تذكرة.",
   [P("phone", "مطلوب", "string", ""), P("code", "مطلوب", "string", "6 أرقام")],
   ["بيرجّع <code>reset_token</code> (64 حرف hex) و<code>expires_in</code> بالثواني.",
    "الكود <strong>بيتأكل هنا</strong>، فماينفعش يتبعت تاني على خطوة الباسورد. "
    "الخطوتين كانوا نداء واحد بالموبايل والكود والباسورد مع بعض، يعني شاشة التحقق "
    "مكانت بتتحقق من حاجة — بتشيل الأرقام الستة لحد ما شاشة الباسورد تبعتهم.",
    "التذكرة <strong>مش</strong> توكن وصول: ماتنفعش تفتح أي endpoint تاني، استخدام واحد، وصلاحية محدودة."]),
  ("POST", "/auth/reset-password", None, "بيحط باسورد جديد بالتذكرة.",
   [P("reset_token", "مطلوب", "string", "64 حرف، من verify-reset-code"),
    P("password", "مطلوب", "string", "8 على الأقل، مؤكد")],
   ["مفيش <code>phone</code> ومفيش <code>code</code>: التذكرة لوحدها بتحدد الحساب، "
    "وطلب الاتنين مع بعض بيفتح احتمال إنهم يتعارضوا.",
    "استخدام واحد، وبيلغي كل توكنات الوصول للحساب — تغيير الباسورد بالظبط هو اللحظة "
    "اللي لازم تتقطع فيها جلسة حد تاني ماسكها."]),
  ("POST", "/auth/logout", "customer", "بيلغي التوكن الحالي بس.", [], []),
]),

("الملف الشخصي", "Profile", "حساب العميل نفسه.", [
  ("GET", "/profile", "customer", "شاشة الحساب.", [], []),
  ("POST", "/profile", "customer", "تعديل الاسم أو الإيميل أو النوع أو الصورة.",
   [P("name", "اختياري", "string", "191 حرف"), P("email", "اختياري", "email", "فريد"),
    P("gender", "اختياري", "male | female", ""),
    P("image_profile", "اختياري", "file", "jpg/png/jpeg/gif/svg، 2 ميجا")],
   ["<strong>POST مش PUT</strong> — لأنه بيحمل ملف، و PHP مش بيقرا multipart في PUT فالرفع بيوصل فاضي. ده السبب في كل تعديل بـPOST في الـAPI ده."]),
  ("PUT", "/profile/password", "customer", "تغيير الباسورد.",
   [P("current_password", "مطلوب", "string", ""), P("password", "مطلوب", "string", "8 على الأقل، مؤكد")], []),
  ("DELETE", "/profile", "customer", "إغلاق الحساب.", [],
   ["حذف ناعم. الطلبات والمدفوعات والشكاوى سجلات لحاجات حصلت فعلاً ومبتختفيش مع الحساب."]),
]),

("العناوين", "Addresses", "«العناوين المحفوظة» ومنتقي الخريطة.", [
  ("GET", "/addresses", "customer", "كل العناوين المحفوظة، والافتراضي الأول.", [], []),
  ("POST", "/addresses", "customer", "حفظ عنوان جديد.",
   [P("label", "اختياري", "string", "«المنزل» / «العمل» / نص حر"),
    P("city_id", "اختياري", "int", ""), P("zone_id", "اختياري", "int", "لازم منطقة مفعّلة"),
    P("street", "مطلوب", "string", "500 حرف"), P("building", "اختياري", "string", ""),
    P("floor", "اختياري", "string", ""), P("apartment", "اختياري", "string", ""),
    P("landmark", "اختياري", "string", "«علامة مميزة»"),
    P("notes", "اختياري", "string", "1000 حرف — ملاحظة العميل على المكان نفسه"),
    P("driver_note", "اختياري", "string", "500 حرف — «ملاحظة للمندوب»، تعليمة ثابتة على العنوان"),
    P("contact_phone", "اختياري", "string", "صيغة مصرية. لو فاضي بياخد رقم الحساب."),
    P("lat", "مطلوب", "number", "‎-90..90"), P("lng", "مطلوب", "number", "‎-180..180"),
    P("is_default", "اختياري", "boolean", "")],
   ["الإحداثيات مطلوبة مش اختيارية: رسوم التوصيل بتتقاس من الدبوس، والعنوان اللي مفيهوش دبوس ماينفعش يتسعّر ولا يتوزّع على مندوب.",
    "<code>notes</code> و<code>driver_note</code> حقلين مختلفين والاتنين بيرجعوا في الـpayload. الأول ملاحظة العميل لنفسه، والتاني التعليمة اللي اللي هيوصل بيقرأها — مكتوبة مرة واحدة هنا فكل طلب على العنوان ده بياخدها، وهي اللي بتظهر لمسؤول التوزيع في اللوحة. و<code>driver_note</code> بتاع <strong>الطلب</strong> حاجة تانية: للتوصيلة دي بس، ومبيلغيش اللي على العنوان."]),
  ("GET", "/addresses/{id}", "customer", "عنوان واحد.", [], []),
  ("PUT", "/addresses/{id}", "customer", "تعديله.", [], ["نفس حقول الإنشاء. مفيش ملف، فده PUT حقيقي."]),
  ("DELETE", "/addresses/{id}", "customer", "حذفه.", [],
   ["مرفوض طول ما فيه طلب شغال بيستخدمه — الحذف هيسيب المندوب من غير مكان يروحله."]),
  ("PUT", "/addresses/{id}/default", "customer", "خليه الافتراضي.", [], []),
]),

("الطلبات", "Orders", "الويزارد والقايمة والتتبع.", [
  ("GET", "/orders", "customer", "القايمة بتبويبات الديزاين الأربعة.",
   [P("tab", "query", "all | active | completed | cancelled", "الافتراضي <code>all</code>."),
    P("per_page", "query", "int", "الافتراضي 15، بحد أقصى 50.")],
   ["كل صف فيه <code>pickup_slot</code> و<code>qr</code>. الاتنين كانوا في التفاصيل بس، يعني كارت «طلبك الحالي» في الهوم — وهو صف من القايمة دي — مكانش يقدر يرسم وقت الاستلام ولا زرار «مسح QR» من غير نداء تاني لكل طلب.",
    "<code>pickup_slot</code> <strong>نطاق</strong> زي «04:00 PM – 06:00 PM» مش وقت واحد: سواق على خط سير مش بيقدر يوعد بالدقيقة، وعشان كده المواعيد مبنية كنوافذ."]),
  ("POST", "/orders/quote", "customer", "معاينة السعر. مبيحفظش أي حاجة.",
   [P("service_id", "مطلوب", "int", "خدمة مفعّلة"),
    P("items", "مطلوب", "array", "ممكن تكون فاضية للخدمة التقديرية"),
    P("items[].item_id", "مطلوب", "int", ""), P("items[].qty", "مطلوب", "int", "1..999"),
    P("pickup_address_id", "مطلوب", "int", ""), P("delivery_address_id", "اختياري", "int", "الافتراضي عنوان الاستلام"),
    P("coupon_code", "اختياري", "string", ""),
    P("offer_id", "اختياري", "int", "كارت «عروض متميزة» — <strong>مانع</strong> مع <code>coupon_code</code>"),
    P("payment_method", "اختياري", "cash | card | wallet | instapay", "بيأثر على الإجمالي")],
   ["اللي التسعير بيعتمد عليه بس هو المطلوب. المواعيد والملاحظات مبتغيّرش أي رقم، فمش بتتطلب هنا.",
    "الكوبون المرفوض عمره ما يوقف الطلب: <code>coupon_error</code> بيشرح، والسعر بيرجع من غير خصم — لأن ويزارد بيقف على غلطة كتابة هو ويزارد الناس بتسيبه.",
    "<code>payment_method: cash</code> بيضيف <code>cash_surcharge</code> كسطر مستقل، <strong>بعد</strong> الخصم — الكوبون بيخصم على الغسيل مش على تكلفة التعامل بالكاش.",
    "خصم واحد للطلب: <code>coupon_code</code> أو <code>offer_id</code>، مش الاتنين. الأوفر بيفوز لأنه اللي الكارت وعد بيه، والكود اللي جنبه <strong>بيترفض برسالة</strong>. والرفض بيحصل <strong>هنا كمان</strong> مش عند الإنشاء بس — خطوة المراجعة هي اللي العميل بيقرر فيها فعلاً."]),
  ("POST", "/orders", "customer", "إنشاء الطلب فعلاً.",
   [P("…", "", "", "كل اللي <code>/quote</code> بياخده، زائد:"),
    P("pickup_date", "اختياري", "date", "النهاردة أو بعدها"),
    P("delivery_date", "اختياري", "date", "في نفس يوم الاستلام أو بعده"),
    P("pickup_slot_id", "اختياري", "int", "لازم تسمح بـ<code>pickup</code>"),
    P("delivery_slot_id", "اختياري", "int", "لازم تسمح بـ<code>delivery</code>"),
    P("pickup_method", "اختياري", "door | leave", "طريقة التسليم وقت <strong>الاستلام</strong>. الافتراضي <code>door</code>"),
    P("delivery_method", "اختياري", "door | leave", "طريقة التسليم وقت <strong>التوصيل</strong>. الافتراضي <code>door</code>"),
    P("offer_id", "اختياري", "int", "كارت «عروض متميزة» اللي العميل ضغط عليه — <strong>مانع</strong> مع <code>coupon_code</code>"),
    P("driver_note", "اختياري", "string", "1000 حرف"),
    P("special_instructions", "اختياري", "string", "2000 حرف"),
    P("accepts_review_terms", "مطلوب", "boolean", "لازم يكون صح"),
    P("photos[]", "اختياري", "file[]", "لحد 5 صور، multipart بس")],
   ["<code>accepts_review_terms</code> هو كل مرحلة المراجعة في حقل واحد: العميل بيوافق إن السعر ده تقديري وإن النهائي هييجي بعد عدّ القطع. تاريخ الموافقة هو اللي بيفرق في النزاع، فبيتسجّل مش بس بيتفحص.",
    "الأربع رحلات بتتعمل مع الطلب مش بعدين — أول حاجة لازم تحصل لطلب جديد إن حد يروح ياخده.",
    "لو النافذة اتملت بيرجع <strong>422</strong> على <code>pickup_slot_id</code> عشان الويزارد يعلّم النافذة بالأحمر بدل بانر فوق الفورم كله."]),
  ("GET", "/orders/{id}", "customer", "التفاصيل الكاملة: السطور، الصور، العناوين، المواعيد، التسعير، الـQR.", [],
   ["<code>qr</code> هو التوكن اللي ورا «إظهار رمز الاستلام (QR)».",
    "<code>pricing.final_*</code> بتفضل null لحد ما المغسلة تعدّ القطع.",
    "<code>pricing.cash_surcharge</code> هو اللي اتحسب على <em>الطلب ده</em> — تغيير الإعداد بعدين مبيعدّلوش.",
    "<code>pickup_method</code> و<code>delivery_method</code> منفصلين — طريقة التسليم بتُسأل مرة لكل رحلة. خام (<code>door</code>/<code>leave</code>) والتطبيق بيترجمهم.",
    "<code>offer_id</code> بيقول الطلب ده جاء من أنهي كارت عروض — الإسناد اللي عشانه وجهات الأوفر مجموعة مقفولة."]),
  ("GET", "/orders/{id}/track", "customer", "الخط الزمني وكارت المندوب.", [],
   ["<code>driver</code>: الاسم، الصورة، الرجل اللي شايلها، و<code>rating</code> — متوسط تقييمات <strong>التوصيل</strong> لوحدها، مش الغسيل أبدًا. <code>null</code> مش <code>0</code> لحد ما حد يقيّمه.",
    "<code>driver.location</code> هي النقطة الحية، وبترجع null في أربع حالات مقصودة: مفيش مندوب متعيّن، الرجل مش منتهية عند العميل، المندوب لسه مبعتش موقعه، أو آخر قراءة بقالها أكتر من دقيقتين.",
    "مفيش رقم تليفون. إدّي رقم المندوب الشخصي لكل عميل ده قرار سياسة مش حقل."]),
  ("GET", "/orders/{id}/reorder", "customer", "«إعادة الطلب» — سلة جاهزة.", [],
   ["GET مبيعملش حاجة، عن قصد. الأسعار ممكن تكون اتحركت، وإنشاء الطلب على طول يا إما هيحاسبه بسعر إمبارح يا إما هيفاجئه بسعر النهاردة."]),
  ("PUT", "/orders/{id}/cancel", "customer", "الإلغاء، طول ما الهدوم لسه عند العميل.",
   [P("reason", "اختياري", "string", "")],
   ["الباب بيتقفل عند الاستلام. <code>can_cancel</code> على الطلب هو اللي التطبيق يرسم بيه الزرار، مش قراءة الحالة بنفسه."]),
  ("GET", "/orders/{id}/reschedule", "customer", "هل محتاج ميعاد جديد، وأنهي نوافذ متاحة.", [],
   ["<code>needs_new_time: false</code> لما مفيش حاجة مستنية. بيتحط بس بعد ما المندوب يسجّل «العميل طلب التأجيل»."]),
  ("POST", "/orders/{id}/reschedule", "customer", "اختيار الميعاد الجديد.",
   [P("slot_id", "مطلوب", "int", ""), P("date", "مطلوب", "date", "النهاردة محسوبة")],
   ["النهاردة مسموحة: عميل أجّل الساعة 9 الصبح ممكن جدًا يعوز بعد الضهر.",
    "إعادة الجدولة بتصفّر عدّاد المحاولات — عميل بيختار وقت أحسن ده مش مندوب فاشل.",
    "بيمرّ على نفس سعة النافذة، عشان النافذة المليانة ماتبقاش مفتوحة من الباب الخلفي."]),
]),

("مراجعة السعر النهائي", "Final price review", "«السعر النهائي جاهز» — بعد ما المغسلة تعدّ.", [
  ("GET", "/orders/{id}/review", "customer", "المقارنة: اللي العميل قاله مقابل اللي وصل.", [], []),
  ("POST", "/orders/{id}/confirm", "customer", "الموافقة على السعر النهائي.",
   [P("payment_method", "اختياري", "cash | card | wallet | instapay", "")],
   ["بياخد طريقة دفع لأن شاشة التأكيد في الديزاين <em>هي</em> شاشة الدفع — «ادفع الآن — 280 ج.م». تأكيد واختيار طريقة الدفع ضغطة واحدة عند العميل، ولو بقوا نداءين الطلب يقعد مؤكد ومش قادر يتدفع."]),
  ("POST", "/orders/{id}/dispute", "customer", "طلب إعادة عدّ.",
   [P("reason", "اختياري", "string", "1000 حرف")], []),
  ("GET", "/orders/{id}/queries", "customer", "«لدي استفسار عن السعر» — الأسئلة وردودها.", [], []),
  ("POST", "/orders/{id}/queries", "customer", "اسأل واحد.", [P("message", "مطلوب", "string", "2000 حرف")],
   ["مش شات. سؤال وله رد واحد، لأن خيط دعم معناه وعد بالرد المستمر ومفيش حد متفرّغ ليه."]),
]),

("التقييم", "Rating", "«ما رأيك في تجربتك؟»", [
  ("GET", "/orders/{id}/rating", "customer", "التقييم الموجود، أو هل ينفع يتحط.", [],
   ["<code>can_rate</code> و<code>available_tags</code> بيرجعوا من هنا عشان التطبيق مايكتبش الخمس شرايط في الكود."]),
  ("POST", "/orders/{id}/rating", "customer", "حط التقييم.",
   [P("overall", "مطلوب", "int", "1..5"),
    P("service_quality", "اختياري", "int", "1..5 — «جودة الخدمة»"),
    P("delivery", "اختياري", "int", "1..5 — «التوصيل والاستلام»"),
    P("timing", "اختياري", "int", "1..5 — «التوقيت»"),
    P("tags[]", "اختياري", "string[]", "<span dir=\"ltr\">fast_delivery · easy_app · great_packaging · friendly_driver · very_clean</span>"),
    P("comment", "اختياري", "string", "2000 حرف")],
   ["الطلبات المنتهية بس، و<strong>مرة واحدة</strong> — مفروضة بفهرس فريد، فضغطتين على نت بطيء ماينفعش يحرّكوا متوسط المغسلة.",
    "الجانب المتخطّى بيفضل null مش 0: «مجاوبش» و«وحش» مش نفس الحاجة.",
    "التقييم <em>للمغسلة</em>. <code>delivery</code> متسابة كعمود مستقل عشان تتنسب للمندوب، وهي اللي بتغذّي النجمة في شاشة التتبع."]),
]),

("الدفع", "Payments", None, [
  ("GET", "/payment-methods", "customer", "الطرق اللي التطبيق ده بيقبلها.", [],
   ["<code dir=\"ltr\">cash · card · wallet · instapay</code>. حفظ البطاقات مش هنا — ده محتاج بوابة دفع."]),
  ("POST", "/orders/{id}/pay", "customer", "بدء عملية دفع.",
   [P("method", "مطلوب", "cash | card | wallet | instapay", "")],
   ["بيرجّع رابط تحويل للبوابة، أو بيسدّد فورًا للمحفظة. الكاش بيتسدّد لما المندوب يسجّل الفلوس على الباب."]),
  ("GET", "/orders/{id}/payments", "customer", "كل محاولة على الطلب ده، الأحدث الأول.", [], []),
  ("POST", "/payments/webhook/{provider}", None, "البوابة بترد علينا.",
   [P("provider", "path", "string", "<code>fake</code> لحد ما نتعاقد مع واحدة حقيقية")],
   ["عام بالضرورة، ومتحقّق منه بالتوقيع مش بالتوكن. الفلوس المتحصّلة عمرها ما بترجع لأن رسالة متأخرة قالت كدا — الرجوع ده استرداد."]),
]),

("المحفظة", "Wallet", None, [
  ("GET", "/wallet", "customer", "الرصيد وحالة التجميد.", [], []),
  ("GET", "/wallet/transactions", "customer", "كشف الحساب.",
   [P("group", "query", "all | payments | additions | refunds | adjustments", ""),
    P("per_page", "query", "int", "")], []),
  ("POST", "/wallet/top-up", "customer", "«إضافة أموال».",
   [P("amount", "مطلوب", "number", "1 .. 100,000")], []),
  ("POST", "/wallet/withdraw", "customer", "«تحويل» — سحب فلوس.",
   [P("amount", "مطلوب", "number", "1 على الأقل")],
   ["بيخصم من الكشف ويسجّل طلب. <strong>مفيش أي فلوس بتتحرّك</strong> — قناة الصرف مش موجودة، وحفظ أرصدة العملاء في مصر نشاط منظّم قانونيًا ومحتاج إجابة قانونية قبل ما يشتغل بفلوس حقيقية."]),
]),

("الكوبونات", "Coupons", None, [
  ("POST", "/coupons/check", "customer", "فحص كود وشوف بيخصم كام.",
   [P("code", "مطلوب", "string", "50 حرف"), P("subtotal", "مطلوب", "number", ""),
    P("delivery_fee", "اختياري", "number", "")],
   ["<strong>POST لقراءة</strong>، لأن الرد بيعتمد على السلة: نفس الكود بيخصم مبلغ مختلف على طلب مختلف، والسلة مكانهاش في query string.",
    "الكوبون المتصرف كمكافأة دعوة بيبقى لشخص واحد. الغريب اللي يجرّبه بيتقاله مش موجود مش «مش بتاعك» — الرد التاني بيشجّع على التخمين."]),
]),

("الاستردادات", "Refunds", None, [
  ("GET", "/orders/{id}/refunds", "customer", "الاستردادات على الطلب ده.", [], []),
  ("POST", "/orders/{id}/refunds", "customer", "طلب استرداد.",
   [P("amount", "اختياري", "number", "0.01 على الأقل؛ الطلب كله لو مبعتّهوش"),
    P("reason", "مطلوب", "string", "191 حرف"), P("note", "اختياري", "string", "2000 حرف")],
   ["طلب مش دفعة. الموافقة والتسوية قرارات العمليات وبتحصل على الداشبورد."]),
]),

("الشكاوى", "Complaints", "«الشكاوي والدعم».", [
  ("GET", "/complaints", "any", "شكاوى الشخص ده.", [], []),
  ("POST", "/complaints", "any", "تقديم شكوى.",
   [P("category", "مطلوب", "enum", "التمانية اللي من <code>/complaint-categories</code>"),
    P("body", "مطلوب", "string", "5 .. 2000"),
    P("order_id", "اختياري", "int", "لازم يكون من طلبات صاحب الشكوى نفسه"),
    P("photos[]", "اختياري", "file[]", "لحد 5 صور، multipart بس")],
   ["<code>order_id</code> اختياري لأن مدخلين الشكوى مختلفين: المندوب بيشتكي من غير طلب معيّن من شاشة حسابه، والعميل بيشتكي من طلب.",
    "الرد بيحمل مرجع <code dir=\"ltr\">CMP-XXXXXXXX</code> — وده الغرض منه، لأن العمليات بترد بالتليفون ومفيش خيط رد.",
    "<code>internal_note</code> عمره ما بيظهر في أي رد للعميل."]),
  ("GET", "/complaints/{id}", "any", "شكوى واحدة بمرفقاتها وحالتها.", [], []),
]),

("تكرار الطلب", "Repeat schedules", "«أسبوعي / كل أسبوعين / شهري».", [
  ("GET", "/recurrences", "customer", "جداول العميل.", [], []),
  ("POST", "/recurrences", "customer", "إنشاء جدول.",
   [P("service_id", "مطلوب", "int", ""), P("pickup_address_id", "مطلوب", "int", ""),
    P("time_slot_id", "اختياري", "int", ""),
    P("frequency", "مطلوب", "weekly | biweekly | monthly", ""),
    P("day_of_week", "اختياري", "int", "1..7 — مطلوب إلا لو شهري"),
    P("items", "مطلوب", "array", "واحد على الأقل"), P("items[].item_id", "مطلوب", "int", ""),
    P("items[].qty", "مطلوب", "int", "1..999"),
    P("starts_on", "اختياري", "date", "بعد النهاردة")],
   ["الجدول عمره ما بيعمل طلب لوحده. بيسأل — «محتاج تغسل النهاردة؟» — والسؤال اللي محدش جاوبه بينتهي. إنشاء طلبات نيابة عن الناس ده اللي بيحوّل الاشتراك لشكوى.",
    "السؤال المؤكَّد <strong>معفي من سعة النافذة</strong>: العميل اتسأل وقال آه، والشاشة دي مفيهاش منتقي مواعيد يرجّعه ليه."]),
  ("GET", "/recurrences/prompts", "customer", "أسئلة مستنية رد.", [], []),
  ("POST", "/recurrences/prompts/{id}/confirm", "customer", "آه — بيعمل الطلب.", [], []),
  ("POST", "/recurrences/prompts/{id}/decline", "customer", "مش المرة دي.", [], []),
  ("PUT", "/recurrences/{id}/pause", "customer", "بطّل تسأل مؤقتًا.", [], []),
  ("PUT", "/recurrences/{id}/resume", "customer", "ارجع اسأل.", [], []),
  ("DELETE", "/recurrences/{id}", "customer", "احذف الجدول.", [], []),
]),

("الإشعارات والأجهزة", "Notifications", None, [
  ("GET", "/notifications", "any", "الصندوق، الأحدث الأول.", [P("per_page", "query", "int", "")], []),
  ("GET", "/notifications/unread-count", "any", "الرقم على الجرس.", [], []),
  ("POST", "/notifications/{id}/read", "any", "علّم واحد مقروء.", [], []),
  ("POST", "/notifications/read-all", "any", "علّم الكل مقروء.", [], []),
  ("POST", "/devices", "any", "سجّل الجهاز ده للإشعارات.",
   [P("token", "مطلوب", "string", "512 حرف — توكن FCM")],
   ["ابعته كل فتحة مش أول مرة بس: FCM بيغيّر التوكنات، والتوكن القديم يعني عميل بطّل يسمع منّا من غير أي خطأ في أي حتة."]),
  ("DELETE", "/devices", "any", "شيله عند تسجيل الخروج.", [P("token", "مطلوب", "string", "")], []),
  ("GET", "/notification-preferences", "any", "الشخص ده كتم إيه.", [], []),
  ("PUT", "/notification-preferences", "any", "غيّرها.", [],
   ["الأحداث المعاملاتية ماينفعش تتكتم. إشعار السعر النهائي اللي مبيوصلش بيوقّف الطلب، فمش مرشّح للصمت."]),
]),

("الدعوات", "Referrals", "«ادعُ أصدقاءك».", [
  ("GET", "/referrals", "customer", "الكود، ومين استخدمه، وكسب إيه.", [],
   ["<code>invited</code> و<code>ordered</code> مختلفين عن قصد: صديق سجّل ولسه مطلبش بيتعدّ ولسه مكسبش. إخفاء الفرق ده بيخلّي العميل يفتكر إن التسجيل ضاع."]),
]),

("المندوب — الدخول", "Driver — auth", "توكنات منفصلة عن تطبيق العميل.", [
  ("POST", "/driver/login", None, "موبايل وباسورد.",
   [P("phone", "مطلوب", "string", ""), P("password", "مطلوب", "string", "")], []),
  ("POST", "/driver/forgot-password", None, "بعت كود.", [P("phone", "مطلوب", "string", "")], []),
  ("POST", "/driver/verify-reset-code", None, "بيأكد كود الاستعادة ويرجّع تذكرة.",
   [P("phone", "مطلوب", "string", ""), P("code", "مطلوب", "string", "6 أرقام")],
   ["نفس شكل مسار العميل بالحرف، عشان التطبيقين يبنوا عقد واحد مش اتنين.",
    "الكود بيتأكل هنا فماينفعش يتبعت تاني على خطوة الباسورد.",
    "ميكانيكا التذكرة مشتركة، الجمهور لأ: تذكرة عميل <strong>مش</strong> بتفتح باسورد سواق والعكس."]),
  ("POST", "/driver/reset-password", None, "باسورد جديد بالتذكرة.",
   [P("reset_token", "مطلوب", "string", "64 حرف، من verify-reset-code"),
    P("password", "مطلوب", "string", "8 على الأقل، مؤكد")],
   ["مفيش <code>phone</code> ومفيش <code>code</code> — التذكرة لوحدها بتحدد الحساب."]),
  ("POST", "/driver/logout", "driver", "إلغاء التوكن ده.", [], []),
]),

("المندوب — الحساب", "Driver — profile", None, [
  ("GET", "/driver/profile", "driver", "المركبة والرخصة والوردية والمناطق والمستندات.", [],
   ["كل حاجة ماعدا الاسم والإيميل والصورة <strong>للقراءة بس</strong>. دي سجلات موثّقة وتوزيع مناطق؛ ومندوب يقدر يعدّل تاريخ انتهاء رخصته بنفسه بيلغي الغرض من تسجيلها.",
    "<code>documents[]</code> هي «مستندات المركبة» — الرخصة ورخصة المركبة والرقم القومي، كل واحد بـurl وتاريخ انتهاء."]),
  ("POST", "/driver/profile", "driver", "تعديل التلات حقول اللي بيملكها.",
   [P("name", "اختياري", "string", ""), P("email", "اختياري", "email", ""),
    P("image_profile", "اختياري", "file", "2 ميجا")], []),
  ("PUT", "/driver/availability", "driver", "«متاح لاستقبال المهام».",
   [P("is_available", "مطلوب", "boolean", "")],
   ["مرفوض والحساب موقوف — وإلا المندوب الموقوف يرجّع نفسه لبركة التوزيع."]),
  ("PUT", "/driver/password", "driver", "تغيير الباسورد.",
   [P("current_password", "مطلوب", "string", ""), P("password", "مطلوب", "string", "8 على الأقل، مؤكد")], []),
]),

("المندوب — الشغل", "Driver — work", "الأربع رحلات لكل طلب.", [
  ("GET", "/driver/summary", "driver", "«ملخص اليوم» — استلام، تسليم، مكتملة، متأخرة.", [], []),
  ("GET", "/driver/tasks", "driver", "قايمة المهام بفلاتر الديزاين.",
   [P("state", "query", "all | new | in_progress | completed | late", ""),
    P("kind", "query", "collection | delivery", ""),
    P("query", "query", "string", "رقم الطلب أو اسم العميل"),
    P("per_page", "query", "int", "")], []),
  ("GET", "/driver/tasks/history", "driver", "«السجل» — المنتهي والفاشل، بالمدة وسبب الفشل.",
   [P("state", "query", "completed | failed", "")], []),
  ("GET", "/driver/tasks/failure-reasons", "driver", "المجموعة المقفولة لـ«تعذر الاستلام».", [],
   ["<code dir=\"ltr\">customer_unavailable · wrong_address · customer_postponed · piece_count_mismatch · other</code>"]),
  ("GET", "/driver/tasks/{id}", "driver", "رجل واحدة بكل اللي الشاشة بترسمه.", [],
   ["أعلام بدل نصوص النوع: <code>requires_signature</code>، <code>requires_piece_count</code>، <code>collects_payment</code> — عشان التطبيق يرسم من الرجل مش يخمّن.",
    "<code>ticket</code> هي «طباعة البطاقة»: رقم الطلب، «مرجع العميل»، الخدمة، التاريخ، الوجهة، والـQR."]),
  ("POST", "/driver/tasks/{id}/start", "driver", "«بدء المهمة».", [],
   ["بيحرّك الطلب كمان، فشاشة العميل بتقول «في الطريق للاستلام» والمندوب في السكة مش بعد ما يوصل."]),
  ("POST", "/driver/tasks/{id}/verify", "driver", "مسح الـQR.",
   [P("token", "مطلوب", "string", "القيمة الممسوحة")],
   ["منفصل عن <code>complete</code> عن قصد: المندوب بيمسح لما يوصل وبيكمّل بعد التسليم، ودمجهم معناه إن رفع صورة فاشل يضيّع مسح ناجح.",
    "مقارنة بتوكن الطلب ده، مش بحث بالتوكن — المندوب بيأكد <em>الطرد ده</em>، مش بيكتشف هو ماسك طلب مين."]),
  ("POST", "/driver/tasks/{id}/complete", "driver", "«تأكيد».",
   [P("piece_count", "اختياري", "int", "0..999"),
    P("receiver_name", "اختياري", "string", "«اسم الموظف المستلم»"),
    P("collected_amount", "اختياري", "number", "الكاش المحصّل"),
    P("note", "اختياري", "string", "1000 حرف"),
    P("signature", "اختياري", "file", "صورة"),
    P("photos[]", "اختياري", "file[]", "لحد 5")],
   ["الصور اختيارية والتوقيع لأ — الديزاين كاتب «(اختياري)» على كل خانة صورة ومش كاتبها على لوحات التوقيع."]),
  ("POST", "/driver/tasks/{id}/fail", "driver", "«تعذر الاستلام».",
   [P("reason", "مطلوب", "enum", "واحد من الخمسة"),
    P("note", "اختياري", "string", "مطلوب لما السبب <code>other</code>")],
   ["<code>customer_postponed</code> بيتصرّف غير الباقي: الرجل بتقف، والميعاد بيتمسح، والعميل بيتسأل على ميعاد جديد — بدل ما الرحلة ترجع للبركة ومندوب تاني يتبعت في ميعاد العميل رافضه أصلاً."]),
  ("POST", "/driver/location", "driver", "«تتبع المندوب مباشرة».",
   [P("lat", "مطلوب", "number", "‎-90..90"), P("lng", "مطلوب", "number", "‎-180..180")],
   ["ابعت كل 30 ثانية <strong>وأنت في رحلة شغالة</strong>. من غير مهمة شغالة الرد بيبقى <code>tracking: false</code> ومفيش حاجة بتتخزّن — ودي إشارة إنك تبطّل تبعت.",
    "آخر نقطة بس. مفيش مسار متخزّن، فمفيش endpoint يرجّعه."]),
  ("GET", "/driver/earnings", "driver", "«الرصيد المعلق» واللي اتصرف.", [], []),
]),
]

ODD = [
 ("<code>/me</code> و<code>/profile</code> شكلهم بيعملوا نفس الحاجة",
  "<code>/me</code> فحص للتوكن — ست حقول، نداء واحد عند فتح التطبيق. <code>/profile</code> شاشة الحساب. لو دمجناهم، كل فتحة للتطبيق هتدفع تمن شاشة مش هتتعرض."),
 ("التعديل بـPOST مش PUT في <code>/profile</code> و<code>/driver/profile</code>",
  "الاتنين بيحملوا ملف، و PHP مش بيقرا multipart في PUT فالرفع بيوصل فاضي. أي تعديل مفيهوش ملف — العناوين، الباسورد، الإتاحة — فعلاً PUT."),
 ("<code>POST /coupons/check</code> بيقرا حاجة",
  "الرد بيعتمد على السلة — نفس الكود بيخصم مبلغ مختلف على طلب مختلف — والسلة مكانهاش في query string."),
 ("<code>GET /orders/{id}/reorder</code> مبيعملش الطلب",
  "الأسعار ممكن تكون اتحركت. إنشاء الطلب على طول يا هيحاسبه بسعر إمبارح يا هيفاجئه بسعر النهاردة، فبيرجّع سلة جاهزة والعميل يأكّد."),
 ("<code>POST /orders/{id}/confirm</code> بياخد طريقة دفع",
  "شاشة التأكيد في الديزاين هي نفسها شاشة الدفع — «ادفع الآن — 280 ج.م». لو فصلناهم، الطلب يقعد مؤكد ومش قادر يتدفع."),
 ("<code>items</code> ممكن تكون array فاضية",
  "الخدمة التقديرية («تنظيف جاف») ملهاش أسعار في الكتالوج أصلاً — بتتسعّر بعد المعاينة — فسلتها مبتطلّعش سطور مسعّرة."),
 ("<code>/wallet/withdraw</code> مبيدفعش لحد",
  "بيخصم من الكشف ويسجّل طلب. مفيش قناة صرف، وحفظ أرصدة العملاء في مصر نشاط منظّم قانونيًا — الإجابة القانونية لازم تيجي قبل ما ده يشتغل بفلوس حقيقية."),
 ("مسح الـQR مقارنة مش بحث",
  "<code>verify</code> بيقارن القيمة الممسوحة بتوكن <em>الطلب ده</em>. البحث بالتوكن كان هيخلّي المندوب يكتشف هو ماسك طلب مين، وده مش السؤال المطروح."),
 ("مفيش تأكيد أوتوماتيكي للسعر أبدًا",
  "الطلب عند <code>reviewed</code> بيستنى بلا حد. بعد 24 ساعة الطرفين بيتنبّهوا وبرضه مفيش حاجة بتحصل أوتوماتيك — الموافقة على سعر نيابة عن حد معناها نزاع."),
 ("جدول التكرار عمره ما بيعمل طلب",
  "بيسأل. والسؤال اللي محدش جاوبه بينتهي، لأن إنشاء طلبات نيابة عن الناس هو اللي بيحوّل الاشتراك لشكوى."),
 ("سعة النافذة بتتحسب بالزيارات مش بالطلبات",
  "استلام وتسليم في نفس النافذة = رحلتين لبابين. لو عدّينا الطلبات، اليوم كان هيشيل ضعف الحركة اللي اتظبّطت عليه. والطلب الملغي بيرجّع مكانه."),
]

METHOD_CLASS = {"GET": "get", "POST": "post", "PUT": "put", "DELETE": "del"}
AUTH_LABEL = {None: ("عام", "pub"), "customer": ("عميل", "cus"),
              "driver": ("مندوب", "drv"), "any": ("أي توكن", "any")}


def slug(i):
    return 'g%d' % i


parts = []
nav = []
total = 0

for i, (gname, gen, gintro, eps) in enumerate(GROUPS):
    gid = slug(i)
    nav.append('<a href="#%s"><span>%s</span><em>%d</em></a>' % (gid, gname, len(eps)))
    parts.append('<section id="%s"><header class="ghead"><h2>%s</h2>'
                 '<span class="en" dir="ltr">%s</span></header>' % (gid, gname, gen))
    if gintro:
        parts.append('<p class="gintro">%s</p>' % gintro)
    for method, path, auth, summary, params, notes in eps:
        total += 1
        alabel, acls = AUTH_LABEL[auth]
        rows = ''
        if params:
            rows = ('<table class="params"><thead><tr><th>البارامتر</th><th>الحالة</th>'
                    '<th>النوع</th><th>ملاحظات</th></tr></thead><tbody>')
            for n, req, typ, note in params:
                reqcls = {'مطلوب': 'req', 'اختياري': 'opt', 'path': 'pth', 'query': 'qry'}.get(req, 'opt')
                label = {'path': 'في المسار', 'query': 'في الرابط'}.get(req, req or '')
                rows += ('<tr><td><code dir="ltr">%s</code></td>'
                         '<td><span class="pill %s">%s</span></td>'
                         '<td class="ty" dir="ltr">%s</td><td>%s</td></tr>' % (n, reqcls, label, typ, note))
            rows += '</tbody></table>'
        notehtml = ''
        if notes:
            notehtml = '<ul class="notes">' + ''.join('<li>%s</li>' % n for n in notes) + '</ul>'
        parts.append(
            '<article class="ep" data-q="%s %s %s">'
            '<div class="eptop"><span class="m %s">%s</span>'
            '<code class="path" dir="ltr">%s</code>'
            '<span class="auth %s">%s</span></div>'
            '<p class="sum">%s</p>%s%s</article>'
            % (method.lower(), path.lower(), summary, METHOD_CLASS[method], method,
               path, acls, alabel, summary, rows, notehtml))
    parts.append('</section>')

odd_html = ''.join('<div class="odd"><h3>%s</h3><p>%s</p></div>' % (t, why) for t, why in ODD)

HTML = '''<title>مرجع Laundo API</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap">
<style>
:root {
  --navy: #0f2d52; --blue: #2563eb;
  --bg: #f6f8fa; --card: #ffffff; --line: #e2e6ea;
  --ink: #16202b; --mute: #5a6672; --soft: #edf1f5;
  --get: #2563eb; --post: #167a4e; --put: #a15c00; --del: #b42318;
  --amber-bg: #fffaf0; --amber-line: #f0d9a8; --amber-ink: #7a5410;
  --mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
  --sans: 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', sans-serif;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --navy: #dbe6f5; --blue: #7aa5f7;
    --bg: #0e1319; --card: #151c24; --line: #26313d;
    --ink: #e7edf3; --mute: #97a5b4; --soft: #1c242e;
    --get: #7aa5f7; --post: #4fbf8b; --put: #e0a96d; --del: #f08a80;
    --amber-bg: #241d10; --amber-line: #4a3a1c; --amber-ink: #e6c78a;
  }
}
:root[data-theme="dark"] {
  --navy: #dbe6f5; --blue: #7aa5f7;
  --bg: #0e1319; --card: #151c24; --line: #26313d;
  --ink: #e7edf3; --mute: #97a5b4; --soft: #1c242e;
  --get: #7aa5f7; --post: #4fbf8b; --put: #e0a96d; --del: #f08a80;
  --amber-bg: #241d10; --amber-line: #4a3a1c; --amber-ink: #e6c78a;
}
* { box-sizing: border-box; }
html { direction: rtl; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: var(--sans);
  font-size: 15px; line-height: 1.75; -webkit-font-smoothing: antialiased; }
code { font-family: var(--mono); font-size: 0.86em; unicode-bidi: embed; }
code[dir="ltr"], .path, .ty { direction: ltr; unicode-bidi: embed; }

.wrap { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 40px;
  max-width: 1180px; margin: 0 auto; padding: 0 24px; }

.masthead { background: #0f2d52; color: #fff; padding: 44px 0 40px; margin-bottom: 36px; }
.masthead .inner { max-width: 1180px; margin: 0 auto; padding: 0 24px; }
.masthead h1 { margin: 0 0 8px; font-size: 30px; font-weight: 700; color: #fff; }
.masthead p { margin: 0; color: #b9cbe4; max-width: 66ch; }
.masthead .stats { display: flex; flex-wrap: wrap; gap: 26px; margin-top: 22px; }
.masthead .stats div { border-right: 2px solid rgba(255,255,255,.22); padding-right: 12px; }
.masthead .stats b { display: block; font-size: 20px; font-variant-numeric: tabular-nums; color: #fff; }
.masthead .stats span { font-size: 12px; color: #9fb6d4; letter-spacing: .03em; }

nav.side { position: sticky; top: 16px; align-self: start; max-height: calc(100vh - 32px);
  overflow-y: auto; padding-bottom: 30px; }
nav.side a { display: flex; justify-content: space-between; align-items: center; gap: 8px;
  padding: 6px 10px; border-radius: 7px; color: var(--mute); text-decoration: none; font-size: 14px; }
nav.side a:hover { background: var(--soft); color: var(--ink); }
nav.side a em { font-style: normal; font-size: 11px; color: var(--mute); font-variant-numeric: tabular-nums; }
#filter { width: 100%; padding: 8px 11px; margin-bottom: 12px; border: 1px solid var(--line);
  border-radius: 8px; background: var(--card); color: var(--ink); font-family: var(--sans); font-size: 14px; }
#filter:focus { outline: 2px solid var(--blue); outline-offset: 1px; }

section { margin-bottom: 46px; scroll-margin-top: 16px; }
.ghead { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap;
  border-bottom: 2px solid var(--line); padding-bottom: 8px; margin-bottom: 14px; }
.ghead h2 { margin: 0; font-size: 20px; font-weight: 700; }
.ghead .en { color: var(--mute); font-size: 13px; font-family: var(--mono); }
.gintro { margin: 0 0 16px; color: var(--mute); }

.ep { background: var(--card); border: 1px solid var(--line); border-radius: 11px;
  padding: 16px 18px; margin-bottom: 12px; }
.eptop { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.m { font-family: var(--mono); font-size: 11px; font-weight: 600; letter-spacing: .06em;
  padding: 3px 7px; border-radius: 5px; border: 1px solid currentColor; }
.m.get { color: var(--get); } .m.post { color: var(--post); }
.m.put { color: var(--put); } .m.del { color: var(--del); }
.path { font-size: 14px; font-weight: 600; color: var(--ink); }
.auth { margin-right: auto; font-size: 11.5px; color: var(--mute); background: var(--soft);
  padding: 3px 10px; border-radius: 20px; }
.auth.pub { color: var(--post); }
.sum { margin: 9px 0 0; }

table.params { width: 100%; border-collapse: collapse; margin-top: 13px; font-size: 14px; }
table.params th { text-align: right; font-size: 11.5px; color: var(--mute); font-weight: 600;
  padding: 5px 9px; border-bottom: 1px solid var(--line); }
table.params td { padding: 6px 9px; border-bottom: 1px solid var(--line); vertical-align: top; }
table.params tr:last-child td { border-bottom: 0; }
.ty { font-family: var(--mono); font-size: 12px; color: var(--mute); white-space: nowrap; }
.pill { font-size: 11px; padding: 2px 8px; border-radius: 20px; white-space: nowrap; display: inline-block; }
.pill.req { background: rgba(180,35,24,.1); color: var(--del); }
.pill.opt { background: var(--soft); color: var(--mute); }
.pill.pth, .pill.qry { background: rgba(37,99,235,.1); color: var(--get); }

ul.notes { margin: 12px 0 0; padding: 0 17px 0 0; color: var(--mute); font-size: 14px; }
ul.notes li { margin-bottom: 6px; }
ul.notes li:last-child { margin-bottom: 0; }

.review { background: var(--amber-bg); border: 1px solid var(--amber-line); border-radius: 12px;
  padding: 22px 24px; margin-bottom: 46px; }
.review > h2 { margin: 0 0 4px; font-size: 20px; color: var(--amber-ink); }
.review > p.lead { margin: 0 0 18px; color: var(--amber-ink); opacity: .85; }
.odd { border-top: 1px solid var(--amber-line); padding-top: 14px; margin-top: 14px; }
.odd:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }
.odd h3 { margin: 0 0 5px; font-size: 15px; font-weight: 600; }
.odd p { margin: 0; color: var(--mute); font-size: 14px; }

footer { border-top: 1px solid var(--line); margin-top: 20px; padding: 22px 0 50px;
  color: var(--mute); font-size: 13px; }
.hidden { display: none; }

@media (max-width: 860px) {
  .wrap { grid-template-columns: 1fr; gap: 0; }
  nav.side { position: static; max-height: none; margin-bottom: 26px; }
  .auth { margin-right: 0; }
}
</style>

<div class="masthead"><div class="inner">
  <h1>مرجع Laundo API — v1</h1>
  <p>كل endpoint تحت <code dir="ltr" style="color:#cfe0f7">/api/v1</code>: بيعمل إيه، وتبعتله إيه.
     البارامترات متاخدة من قواعد التحقق الموجودة فعلاً في الكود، مش مكتوبة من الذاكرة.</p>
  <div class="stats">
    <div><b>TOTAL</b><span>endpoint</span></div>
    <div><b>21</b><span>مجموعة</span></div>
    <div><b>2</b><span>تطبيق: عميل ومندوب</span></div>
    <div><b>1</b><span>شكل رد موحّد</span></div>
  </div>
</div></div>

<div class="wrap">
<nav class="side">
  <input id="filter" type="search" placeholder="ابحث في الـendpoints…" aria-label="بحث">
  <a href="#conventions"><span>قواعد عامة</span><em></em></a>
  NAV
  <a href="#review"><span>شكلها غلط وهي مقصودة</span><em>ODDCOUNT</em></a>
</nav>

<main>
<section id="conventions">
  <header class="ghead"><h2>قواعد عامة</h2><span class="en" dir="ltr">Conventions</span></header>
  <article class="ep" data-q="envelope headers auth">
    <p class="sum" style="margin-top:0"><strong>كل رد ليه نفس الشكل</strong>، نجح أو فشل، و<code>code</code>
    دايمًا بيساوي حالة الـHTTP — فالتطبيق يقدر يفرّع على أي واحد فيهم.</p>
    <table class="params"><tbody>
      <tr><td><code dir="ltr">key</code></td><td colspan="3"><code>success</code> أو مفتاح الخطأ.</td></tr>
      <tr><td><code dir="ltr">msg</code></td><td colspan="3">رسالة مقروءة، مترجمة للغة الطلب.</td></tr>
      <tr><td><code dir="ltr">data</code></td><td colspan="3">البيانات.</td></tr>
      <tr><td><code dir="ltr">errors</code></td><td colspan="3">أخطاء التحقق مفهرسة بالحقل في حالة 422.</td></tr>
      <tr><td><code dir="ltr">meta</code></td><td colspan="3">بيانات الصفحات، في الـendpoints المقسّمة صفحات.</td></tr>
    </tbody></table>
    <ul class="notes">
      <li><code dir="ltr">Accept: application/json</code> مطلوب. و<code dir="ltr">lang: ar|en</code> اختياري وبيحدد لغة الرد.</li>
      <li>الروابط المحمية بتاخد <code dir="ltr">Authorization: Bearer &lt;token&gt;</code>. توكن العميل وتوكن المندوب منفصلين ومش بديل لبعض.</li>
      <li>أي endpoint بياخد ملف لازم يتبعت <code dir="ltr">multipart/form-data</code>.</li>
      <li>القوايم بتاخد <code dir="ltr">per_page</code> بحد أقصى 50.</li>
    </ul>
  </article>
</section>

PARTS

<section id="review" class="review">
  <h2>شكلها غلط وهي مقصودة</h2>
  <p class="lead">حاجات في الـAPI أول ما تشوفها تفتكرها غلطة — ودي أسبابها.</p>
  ODDHTML
</section>

<footer>
  متولّدة من <code dir="ltr">routes/api.php</code> ومن قواعد التحقق في الـFormRequests والكنترولرز.
  ملف البوستمان في <code dir="ltr">docs/postman/</code> فيه نفس الـTOTAL endpoint بأمثلة قابلة للتشغيل.
</footer>
</main>
</div>

<script>
(function () {
  var box = document.getElementById('filter');
  var eps = Array.prototype.slice.call(document.querySelectorAll('.ep[data-q]'));
  var secs = Array.prototype.slice.call(document.querySelectorAll('main section'));
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    eps.forEach(function (el) {
      el.classList.toggle('hidden', q !== '' && el.getAttribute('data-q').toLowerCase().indexOf(q) === -1);
    });
    secs.forEach(function (s) {
      if (s.id === 'review') return;
      var any = s.querySelector('.ep:not(.hidden)');
      s.classList.toggle('hidden', q !== '' && !any);
    });
  });
})();
</script>
'''

HTML = (HTML.replace('NAV', ''.join(nav))
            .replace('PARTS', ''.join(parts))
            .replace('ODDHTML', odd_html)
            .replace('ODDCOUNT', str(len(ODD)))
            .replace('TOTAL', str(total)))

io.open(OUT, 'w', encoding='utf-8', newline='\n').write(HTML)
print('wrote', OUT, '-', total, 'endpoints')
