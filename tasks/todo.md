# The driver's own records, and telling the two apps apart

Two requests, decided with the owner:

1. The driver app's «بيانات المركبة», «رخصة القيادة» and «مستندات المركبة» screens
   each carry a save button and none of them has an endpoint. The driver may now
   edit all three — **zones stay operator-assigned**, because territory decides
   who is handed work and that is an operations decision, not a preference.
2. The FAQ, «تواصل معنا» and «تقديم شكوى» must tell a customer from a driver.

Everything the driver submits is **optional**. A document is a record of what has
been collected, not a precondition for having an account — operations onboards a
courier on the phone and photographs the licence later.

## A — the columns the design draws and the schema never had

One migration on `driver_profiles`, every column nullable:

- vehicle: `vehicle_brand`, `vehicle_model`, `vehicle_year`, `vehicle_color`
- licence: `license_type`, `license_issued_at`
- documents: `vehicle_insurance_image` + `_expiry`,
  `vehicle_inspection_image` + `_expiry`, `other_document_image`

- [x] Migration + `$fillable` + casts
- [x] `expiredDocuments()` learns the two new expiries

## B — the driver edits their own record

- [x] Extend `POST /api/v1/driver/profile` rather than adding three endpoints:
      one resource, one request class, one place the «all optional» rule lives.
      Each screen posts only its own fields; absent means untouched, so one
      screen's save cannot wipe another's.
- [x] **Zones stay refused**, and the test that pins it stays — narrowed to zones.
- [x] `GET /driver/profile` returns the new fields, and the five documents.

## C — customer and driver are not the same complainant

`ComplaintCategory` is a PHP enum with no notion of audience, and half of it is
meaningless to a driver («هدوم اتخربت»). Worse, the design's «مشكلة مع العميل»
does not exist at all — a driver has no way to complain about a customer.

- [x] `CustomerConduct` case, driver-only.
- [x] `audience()` on the enum; `GET /complaint-categories?audience=driver`,
      mirroring `GET /faqs?audience=` which already works this way.
- [x] Submitting a category the audience cannot use is refused.

## D — separate support numbers

- [x] `Driver_Hotline`, `Driver_Call`, `Driver_Whats_App`, `Driver_Email`
      settings, fields on the general settings screen, and
      `GET /app-settings?audience=driver` answering them — **falling back to the
      customer numbers when blank**, so a half-filled install still reaches
      somebody.

## E — the dashboard has to be able to set all of it

- [x] The new columns on the driver form, all optional, matching the existing
      convention: a red `*` marks required and the documents block carries none.

## F — verify

- [x] Tests per part, full suite, drive the form, then docs: reference, Postman,
      the mobile note, Changelog.

## Already done, no work needed

`GET /faqs?audience=driver` has worked since the table was created —
`enum('both','customer','driver')`. The `faqs` table is empty, which is content
for the dashboard, not code.


## Review

All six parts shipped. The pieces worth recording:

**Two things were already done and needed saying, not building.** `GET /faqs`
has taken `?audience=` since its table was created, and every document field in
the dashboard was already nullable with no `required` in the form — I proved the
second by driving the real browser before touching it, and the only 422 I could
produce was Chrome autofilling the email field of my own probe. Guarded with
tests now so nobody has to ask again.

**One real defect surfaced on the way.** «Optional» was only half true: the crud
service filtered nulls out of its payload, so a licence number typed into the
wrong driver could be set and never unset. The form posted a blank, the filter
discarded it, the screen came back unchanged. The two fields already lifted out
of that filter had a comment explaining they «must be clearable» — that was the
rule, written as an exception.

**The decision that was reversed, deliberately.** Vehicle, licence and documents
were read-only on the API, documented as «a driver editing their own licence
expiry would defeat the point of recording it». The owner's call reverses it and
the practical case is stronger: these are the things only the driver has. The
expiry was never a gate anyway — `expiredDocuments()` surfaces a lapse for a
human and does not stop assignment. Zones stayed refused, which is the line that
actually matters, because territory decides who is handed work.

**The gap nobody had reported.** Splitting complaints by audience exposed that
«مشكلة مع العميل» did not exist: every category described something done *to* a
customer, so a driver's complaint had only «أخرى» to land in, and a complaint in
«أخرى» stops being countable.

Left for content rather than code: `faqs` holds zero rows, and the four driver
support numbers are seeded empty on purpose.
