# Postman collection

| File | What it is |
|---|---|
| `Laundo API v1.postman_collection.json` | All 95 endpoints under `/api/v1`, in 20 folders |
| `Laundo Local.postman_environment.json` | `base_url`, `lang`, and the dev fixture credentials |

Import both (Postman → Import → drag the two files in), pick the **Laundo — Local**
environment, and run **04 · Auth — customer → Login**.

## How the auth wiring works

The collection authenticates with a bearer token at the collection level, so every
request inherits `{{token}}` unless it says otherwise:

- public routes carry an explicit **No Auth**;
- the three driver folders override the token to `{{driver_token}}`.

**Login**, **Verify OTP** and **Driver login** each write their token into the matching
collection variable from a test script, so nothing has to be copied by hand. Requests
that create an address, an order, a complaint or a repeat schedule do the same with the
new id, and the prompts / notifications / driver-tasks lists capture the first row they
return. Every request also asserts the response carries the `key` + `code` envelope.

## Dev credentials

The environment ships pointing at `DevFixturesSeeder`'s accounts, all with the password
`password`:

```
php artisan db:seed --class=DevFixturesSeeder   # local / testing only
```

- customer — `01055556666`
- driver — `01066660001`

OTP codes are never returned in a response on any environment. Read them from the log
(`storage/logs/laravel.log`) and put the value in the `otp_code` variable.

## Keeping it in sync

`routes/api.php` is the source of truth; the collection is maintained alongside it. After
adding or renaming a route, check for drift:

```bash
php artisan route:list --path=api --json | php -r '
$routes = json_decode(stream_get_contents(STDIN), true);
$col = json_decode(file_get_contents("docs/postman/Laundo API v1.postman_collection.json"), true);
$lit = ["api/v1/translations/app"=>"api/v1/translations/{type}","api/v1/pages/terms"=>"api/v1/pages/{page}","api/v1/payments/webhook/fake"=>"api/v1/payments/webhook/{provider}"];
$have = [];
foreach ($col["item"] as $f) foreach ($f["item"] as $i) {
  $u = preg_replace("~\{\{[a-z_]+\}\}~", "{id}", parse_url(str_replace("{{base_url}}/", "", $i["request"]["url"]["raw"]), PHP_URL_PATH));
  $have[] = $i["request"]["method"]." ".($lit[$u] ?? $u);
}
$want = [];
foreach ($routes as $r) foreach (explode("|", $r["method"]) as $m) if (!in_array($m, ["HEAD","OPTIONS"])) $want[] = "$m {$r["uri"]}";
$missing = array_diff($want, $have); $extra = array_diff($have, $want);
echo $missing || $extra ? "DRIFT\n  missing: ".implode(", ", $missing)."\n  extra: ".implode(", ", $extra)."\n" : "in sync (".count($want)." routes)\n";
'
```

`$lit` maps the three paths where the collection uses a real example value in place of a
route parameter (`/translations/{type}`, `/pages/{page}`, `/payments/webhook/{provider}`).

## Editing it

Postman rewrites the whole file on export, which makes the diff unreadable. For a small
change — a new field in a body, a corrected description — edit the JSON directly and
re-import. Export over the top of these files only when the change is large enough that
a churned diff is worth it.
