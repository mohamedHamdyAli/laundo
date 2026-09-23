# Retrieval phase ledger

Every row measured against the **frozen** 20-task set (`tasks-known.php`),
which was not edited after Phase 0. Raw results per phase are in `results/`.

`FN+exp` is the false-negative rate counting `then_read`; `recall` counts
ranked hits only.

| Phase | Change | Top-1 | Top-3 | Top-5 | Recall | FP | FN+exp | Resp | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| 0 | baseline, frozen | 50% | 65% | 70% | 50.0% | 45.6% | 50.0% | 2055 | — |
| 1 | Blade content indexing | 45% | 70% | 75% | 52.8% | 49.4% | 47.2% | 2022 | **kept** |
| 2 | feature expansion → `then_read` | 45% | 70% | 75% | 52.8% | 49.4% | 38.9% | 2713 | **kept** |
| 3a | BM25 `b` 0.72 → 0.40 | 40% | 60% | 75% | 50.0% | 50.0% | 38.9% | 2679 | **reverted** |
| 3b | capped length ratio at 2.0 | 40% | 60% | 75% | 50.0% | 50.6% | 38.9% | 2674 | **reverted** |
| 4 | `present*`/`scope*` facets | 45% | 75% | 80% | 55.6% | 48.8% | 36.1% | 2710 | **kept** |
| 5a | unstop `app`,`src`,`blade` | 45% | 70% | 80% | 55.6% | 48.8% | 36.1% | 2710 | **reverted** |
| 5b | unstop `blade` only | 45% | 75% | 80% | 55.6% | 48.8% | 36.1% | 2710 | **kept** |
| 6 | deny `tests/Feature/SecondBrain` | 45% | 75% | 80% | 55.6% | 47.6% | 36.1% | 2705 | **kept** |
| 7 | permission-gate intent | 50% | 75% | 85% | 63.9% | 48.1% | 33.3% | 2713 | **kept** |
| 8a | route facets + middleware | 50% | 75% | 85% | 61.1% | 50.0% | 36.1% | 2728 | **reverted** |
| 8b | route facets, URI only | 50% | 75% | 85% | 63.9% | 48.1% | 33.3% | 2713 | **kept** |
| 9 | migration ↔ model `defined_by` | 50% | 75% | 85% | 63.9% | 48.1% | 33.3% | 2713 | **kept** |
| 10 | project vocabulary aliases | 50% | 75% | 85% | 63.9% | 48.8% | 33.3% | 2631 | **kept** |
| 11a | sibling threshold 0.55 | 50% | 75% | 85% | 61.1% | 49.4% | 36.1% | 2644 | **reverted** |
| 11b | sibling threshold 0.72 | 50% | 75% | 85% | 63.9% | 48.1% | 33.3% | 2621 | **kept** |

## What was reverted, and why

**Phase 3 — both variants.** The hypothesis was that BM25's length
normalisation punished `Order.php` (395.2 tokens against a 70.6 average) for
being the centre of its module. Both corrections measured *worse* on Top-1 and
Top-3, and neither fixed the task that motivated them. Instrumenting the index
showed why the diagnosis was wrong: `OrderMedia` carries a **higher** term
frequency for "order" (29.2) than `Order` itself (24.2), because a compound
name repeats the term across name, symbol and path — and it is a fifth the
length. It wins on both axes at once, and no value of `b` reorders that.

**Phase 5a — unstopping `app`.** Measured: `app` appears in 2,522 of 4,833
documents (52%, idf 0.65) because every path under `app/` contains it, and
`src` appears in none. Unstopping cost a rank and fixed nothing. `blade` was
kept unstopped on the same evidence, in the other direction: 278 documents,
idf 2.85, and it selects exactly the category the index was weakest on.

**Phase 8a — middleware in route facets.** `web`, `Authenticate` and
`EnsureDashboardRole` sit on hundreds of routes, so indexing them lifted every
route document at once: recall 63.9% → 61.1%, FP 48.1% → 50.0%, no gain.

**Phase 11a — sibling threshold 0.55.** Too aggressive; suppressed real answers
alongside near-duplicates.

## One measurement change, declared

`Runner::primaryPaths()` was extended at Phase 7 to read the file lists of
*structured* answers (`controllers`, `declared_in`, dependency edges) and not
only `results`. Before that, a `permission_gate` answer scored 0% while
returning precisely the two files the frozen truth names — the harness was
measuring the response envelope rather than whether the brain found the code.

**No truth set, task wording or classification rule was changed at any point.**

---

# Experiment: name-coverage scoring — **falsified, reverted**

A bounded multiplicative boost for a document whose whole name is covered by
the query's literal terms. Proposed after Phase 3's BM25 revert, on the reading
that `Order` losing to `OrderMedia`/`OrderItem`/`OrderSettlement` was a
*specificity* failure rather than a length one.

Two forms were implemented and measured on both sets. `Order` → `[order]`;
`OrderMedia` → `[order, media]`, uncovered by a query saying only "order".

| Variant | Known Top-1/3/5 | Known FP | **Unseen Top-1/3/5** | **Unseen FP** | Unseen FN+exp |
|---|---|---|---|---|---|
| before | 50 / 75 / 85 | 48.1% | **50 / 80 / 87** | **40.8%** | 22.4% |
| A: layer suffixes stripped | 35 / 85 / 90 | 43.6% | 50 / 77 / 83 | 39.8% | 22.4% |
| B: suffixes kept | 50 / 80 / 90 | 47.9% | **43 / 67 / 77** | **46.9%** | 28.6% |

## What it got right

Both target tasks were fixed, from absent to rank 2:

- Task 12 `add a relationship to the order model` → `Order.php` MISS → **2**
- Task 7 `add a filter dropdown to the orders list screen` → `admin/order/index.blade.php` MISS → **2**

All six required cases behaved: `Order`→`Order` rank 1 with its siblings
displaced, `Coupon`→`Coupon` rank 1 with `CouponService` at 3, `driver
earning`→`DriverEarning`/`driver_earnings`, `app settings`→`AppSettingController`.

## Why it was reverted

**It reduced held-out performance in both variants.** Variant B, the one that
kept known Top-1 intact, cost the unseen set 13 points of Top-3, 10 of Top-5
and 6.1 of false-positive rate. Variant A cost known Top-1 fifteen points.

The mechanism, for whoever tries this again:

- **Stripping layer suffixes** makes `OrderService` cover as `[order]`, so any
  query mentioning an order fully covers it — and that module's controller,
  repository and request with it. Six known tasks each slipped one rank behind
  a generic service. This is the arbitrary boost the brief warned against.
- **Keeping them** removes that, but the boost then fires on short common names
  (`Setting`, `Order`, `Zone`), which is what cost the unseen set its Top-5.

The gains landed precisely on the two tasks the signal was diagnosed from, and
the losses landed on tasks nobody had looked at. That is overfitting, and the
held-out set is the only reason it was visible at all.

**The diagnosis may still be right; name coverage is the wrong instrument.** A
name is a very short string to hang that much weight on, and "fully covered" is
a cliff where the evidence is a gradient. Anything tried next should be
measured on the unseen set *first*.
