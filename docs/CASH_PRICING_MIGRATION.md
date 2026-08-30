# Cash pricing & loyalty points — migration guide

Points used to be the item currency. They are now a **loyalty discount**, and
**pesos are the official price**. This document records what changed, how the
old data was converted, and what a client has to do to keep working.

---

## 1. The two constants

| Rule | Value | Where it lives |
|---|---|---|
| Reward earned | `floor(publicSellingPrice / 100)` — ₱250 → 2 points | `App\Support\LoyaltyRules::rewardPointsFor()` |
| Redemption value | 1 point = **₱5** off | `App\Support\LoyaltyRules::discountFor()` |

`pointsDiscount = pointsUsed × ₱5` and `finalAmountDue = max(itemPrice − pointsDiscount, ₱0)`.

Both are recalculated server-side on every request. A client may preview them;
nothing a client sends about prices, discounts, balances or totals is trusted.

## 2. Money representation

All money columns are `DECIMAL(10,2)`. **No floating point is used anywhere.**
Arithmetic happens in integer centavos inside `App\Support\Money`, which parses
the DECIMAL strings textually rather than casting to float.

## 3. The three prices are separate

The old `markup_points` column meant two different things at once — the
buyer-facing catalog price *and* the admin's profit. Those have been split:

| Field | Meaning | Example |
|---|---|---|
| `items.seller_asking_price` | What the student asked | ₱200 |
| `items.acquisition_price` | What Admin agreed to pay the seller | ₱180 |
| `items.public_price` | What a buyer pays | ₱250 |
| *(derived)* `markup` | `public_price − acquisition_price` | ₱70 |
| `items.reward_points` | `floor(public_price / 100)` | 2 |

Markup is **never stored** — it is derived, so the two figures cannot drift
apart again. The profit reports were recomputed accordingly, not migrated.

## 4. Legacy data conversion

Migration `2026_08_31_000005_convert_legacy_point_prices_to_pesos.php`.

**Conversion rate: 1 legacy point = ₱1.** An item that was 200 points becomes
an item asking ₱200.00. Every converted row is stamped
`price_source = 'legacy_points'` so the origin of the number is never lost and
Admin can re-price later; rows created under the new rules carry
`price_source = 'cash'`. Publishing an item at a real peso price resets it to
`'cash'`.

Column mapping depends on how far the old item had progressed:

```
price_points  -> seller_asking_price   (always)
price_points  -> acquisition_price     (only if status was acquired/public/reserved/sold —
                                        the old flow paid the seller exactly the asking points)
markup_points -> public_price          (only if status was public/reserved/sold — the old API
                                        served markup_points as the buyer-facing price)
```

A legacy item that never reached the catalog gets **no** `public_price` and no
reward points: Admin must set a real selling price, and the normal turnover
gate still applies before it can be published.

Other effects:

- `status = 'private'` → `'pending'` (`private` was the de-facto pending state).
- Items with a `points` row of reason `sale` are marked `seller_payout_status = 'paid'` — those sellers were already paid under the old flow.
- Ledger rows are reclassified to `legacy_purchase` / `legacy_payout` / `legacy_markup`, keeping points-as-currency history out of reward reporting.
- `balance_after` is left **NULL** on legacy ledger rows. It cannot be reconstructed honestly: the old `sendPoints` flow mutated wallet balances outside a database transaction, so replaying the ledger does not reconcile with the stored `wallet_points`.
- Transactions whose buyer is an admin are flagged `is_seller_payout = true`. The old "Send Points & Finalize" wrote seller payouts into the transactions table; those are not buyer orders and are excluded from every buyer/admin order screen.

The migration is **idempotent** — it only touches rows whose peso columns are
still NULL, so re-running it changes nothing.

> **Baseline note.** The core tables (`items`, `transactions`, `points`, …) were
> created by hand and had no migrations. `2026_08_31_000001_baseline_legacy_marketplace_tables.php`
> now captures their pre-existing shape, guarded by `hasTable()` so it is a
> no-op against production, and `2026_08_31_000000_align_users_table_with_production_shape.php`
> reconciles the `users` table on fresh databases. Neither edits an existing
> migration.

## 5. Lifecycles

**Item:** `pending → acquired → public → reserved → sold`, plus `rejected`.

A student uploads at `pending` and **cannot publish their own listing** — the
old `PUT /api/items/{id}` accepted `status` and allowed exactly that; it no
longer does. Admin may publish only once turnover is verified *and* an
acquisition price is recorded *and* the public price is valid.

**Order:** `pending_payment → payment_proof_submitted → payment_verified →
reserved → ready_for_pickup → completed`, plus `cancelled` / `rejected`.

- A checkout **reserves** the item so a second buyer cannot take it.
- Reservations expire after **24 hours** (`CheckoutService::RESERVATION_HOURS`). `checkouts:expire` runs every 15 minutes via the scheduler and also has an admin endpoint; expiry cancels the order and returns the points.
- Only Admin marks an order `completed`, and **completion is the only thing that credits reward points**.
- A bill fully covered by points needs no cash and no GCash proof: it is created already `payment_verified` with method `points_full`.

## 6. Points ledger

Every wallet change goes through `App\Services\PointsLedger`, the only writer of
`users.wallet_points`. It refuses to run outside a database transaction, locks
the wallet row, and writes the balance and the ledger entry together.

Each movement carries an `idempotency_key` backed by a **UNIQUE index**
(`redeem:txn:{id}`, `reward:txn:{id}`, `refund:txn:{id}`), so a replayed request
cannot credit rewards twice, deduct twice, or complete twice — enforced by the
database, not just by application logic.

Cancellation or a rejected payment proof writes a `refund` entry returning the
redeemed points.

**Seller payout and buyer rewards are entirely separate.** Sellers are paid
cash (`POST /api/admin/items/{item}/seller-payout`, recorded on the item);
buyers earn points on completion. `POST /api/admin/send-points` is now only a
manual bonus/correction tool and no longer writes transaction rows.

## 7. API changes

### New

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/checkout/quote?item_id=&points_used=` | Server-calculated breakdown |
| POST | `/api/checkout` | Start a checkout |
| POST | `/api/checkout/{id}/payment-proof` | Upload a GCash receipt |
| POST | `/api/checkout/{id}/cancel` | Buyer cancels their own unpaid order |
| GET | `/api/admin/items/pending` | The offers page |
| POST | `/api/admin/items/{id}/acquisition-price` | Record the negotiated price |
| POST | `/api/admin/items/{id}/meetup` | Record the turnover schedule |
| POST | `/api/admin/items/{id}/verify-turnover` | Ofelia received and verified it |
| POST | `/api/admin/items/{id}/seller-payout` | Mark the seller paid in cash |
| GET | `/api/admin/items/{id}/publish-preview?public_price=` | Reward + markup preview |
| POST | `/api/admin/items/{id}/publish` / `/unpublish` / `/reject` | Catalog control |
| GET | `/api/admin/transactions/{id}` | One order |
| POST | `/api/admin/transactions/{id}/verify-payment` | Approve payment |
| POST | `/api/admin/transactions/{id}/reject-payment` | Reject proof, restore points |
| POST | `/api/admin/transactions/{id}/ready-for-pickup` | Stage for handover |
| POST | `/api/admin/transactions/{id}/complete` | Complete + credit rewards |
| POST | `/api/admin/transactions/{id}/cancel` | Cancel with a reason |
| POST | `/api/admin/transactions/expire-abandoned` | Sweep stale holds |

### Backward compatibility

Responses still include `price_points` and `markup_points`. For a published
item, `markup_points` mirrors the **peso selling price** (which is what old
buyer builds rendered as the price) — not the profit. New clients should read
`public_price` / `seller_asking_price` and ignore both legacy keys.

Requests still accept `price_points` on `POST /api/items` and treat it as a
peso amount, so the currently deployed app keeps working during rollout.
`PUT /api/admin/items/{id}` still accepts `{status: public, markup_points: N}`
from older admin builds, but routes it through the gated publish path — so an
item can never reach the catalog without verified turnover.

`payment_method` values `points` and `trade` no longer exist. `points` maps to a
cash checkout redeeming the requested points; `/api/admin/transactions/trade`
now returns full-points checkouts.

## 8. Security

- Only Admin can set the acquisition price, verify turnover, set the public price, publish, verify GCash proof, or complete/cancel a transaction — enforced by the `admin` middleware on the route group, reading the role from the Sanctum token.
- Buyer identity comes from the token, never from a body field.
- A buyer cannot purchase their own item.
- Checkout is refused for non-public, reserved, sold or unpriced items.
- Uploaded GCash proof is validated for type (JPG/PNG/PDF) and size (5 MB).

## 9. Tests

`php artisan test` — 82 tests, 312 assertions covering the acceptance cases:
₱200 upload → pending; seller sees no reward points; negotiated acquisition
price; turnover + cash payout; publish at ₱250 → 2 points; catalog shows ₱250
and "Earn 2 points"; 2 points → ₱10 discount; ₱150 − 2 pts → ₱140; ₱50 − 10 pts
→ ₱0; over-redemption refused; reserved item blocks a second buyer;
rejection/cancellation restores points; completion credits rewards once;
repeated completion does not duplicate; GCash proof requires verification;
legacy conversion behaves as documented.

> The local PHP has `pdo_sqlite` and `gd` disabled in a php.ini under Program
> Files. Run with a patched copy: `php -c <scratch>/php.ini vendor/bin/phpunit`.
