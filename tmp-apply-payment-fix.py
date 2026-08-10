#!/usr/bin/env python3
"""Apply previous-order payment breakdown popup fix to the frontend repo."""
from pathlib import Path

FRONTEND = Path("/Users/mac/Documents/projects/centrix-erp-frontend-web")

HELPERS = r'''
/** True when any payment_adjustment is still a background provisional tender. */
export function previousOrderAdjustmentsAreProvisional(adjustments) {
  if (!Array.isArray(adjustments) || adjustments.length === 0) return false;
  return adjustments.some((row) => row?._provisional === true);
}

/**
 * Rebuild top-up/return delta from provisional rows after original_order_total rebase.
 *
 * @param {object|null|undefined} cart
 * @param {object|null|undefined} sourceSale
 * @param {{ cashRound?: boolean }} [options]
 */
export function paymentDeltaFromProvisionalAdjustments(cart, sourceSale = null, options = {}) {
  const rows = (Array.isArray(cart?.payment_adjustments) ? cart.payment_adjustments : []).filter(
    (row) => row?._provisional === true && Number(row?.amount) > 0,
  );
  if (!rows.length) {
    return { amount: 0, type: null, originalTotal: 0, newTotal: 0 };
  }
  const type = String(rows[0].adjustment_type ?? "").toLowerCase() === "return" ? "return" : "topup";
  const amount =
    Math.round(
      rows
        .filter((row) => String(row.adjustment_type ?? "").toLowerCase() === type)
        .reduce((sum, row) => sum + (Number(row.amount) || 0), 0) * 100,
    ) / 100;
  if (!(amount > 0)) {
    return { amount: 0, type: null, originalTotal: 0, newTotal: 0 };
  }
  const newTotal = Number(
    summarizeLocalPosCart(cart, { cashRound: Boolean(options.cashRound) }).amountDue ?? 0,
  );
  const originalFromCart = Number(cart?.original_order_total);
  const original =
    Number.isFinite(originalFromCart) && originalFromCart > 0
      ? originalFromCart
      : Number(sourceSale?.order_total ?? sourceSale?.amount_paid ?? 0);
  const impliedOriginal =
    type === "return"
      ? Math.round((newTotal + amount) * 100) / 100
      : Math.round((newTotal - amount) * 100) / 100;
  const baselineLooksRebased =
    Math.abs(Number(original) - Number(newTotal)) < 0.02 &&
    Math.abs(impliedOriginal - newTotal) > 0.02;
  const originalTotal = baselineLooksRebased ? impliedOriginal : original;
  return {
    amount,
    type,
    originalTotal,
    newTotal,
    signedDelta: type === "return" ? -amount : amount,
  };
}
'''

ENSURE_FN = r'''  async function ensurePreviousOrderPaymentAdjustment(cartNow, options = {}) {
    if (!cartNow?.held_order_num) return cartNow;
    if (!cartNow?.superseded_sale_id && !cartNow?.offline_client_sale_uuid) {
      return cartNow;
    }
    // Payment method / top-up / return only when the cashier actually changed the order.
    if (!options.force && !editedOrderHasLocalDraftChanges(cartNow)) {
      return cartNow;
    }
    let delta = computePreviousOrderEditPaymentDelta(editSourceSale, cartNow, {
      cashRound: enablePosCashRounding,
    });
    // Background sync can rebase original_order_total while tenders are still
    // provisional — rebuild the prompt delta from those rows so Alt+P / F8 still ask.
    if (
      (!delta.type || !(Number(delta.amount) > 0)) &&
      previousOrderAdjustmentsAreProvisional(cartNow.payment_adjustments)
    ) {
      delta = paymentDeltaFromProvisionalAdjustments(cartNow, editSourceSale, {
        cashRound: enablePosCashRounding,
      });
    }
    // No bill change → never prompt (even if a no-op keystroke left the cart dirty).
    if (!delta.type || !(Number(delta.amount) > 0)) return cartNow;

    const matched = previousOrderAdjustmentsMatchDelta(cartNow.payment_adjustments, delta);
    const provisionalOnly = previousOrderAdjustmentsAreProvisional(
      cartNow.payment_adjustments,
    );

    // Background autosave / offline queue: never open the payment dialog mid-edit.
    // Stash a provisional cash adjustment; Alt+P / F8 / F10 still collect the real tender.
    if (options.provisional) {
      if (matched && provisionalOnly) return cartNow;
      if (matched && !provisionalOnly) return cartNow;
      const methodRaw = String(
        cartNow.payment_method_code ?? editSourceSale?.payment_method_code ?? "CASH",
      )
        .trim()
        .toUpperCase();
      const method_code = !methodRaw || methodRaw === "CREDIT" ? "CASH" : methodRaw;
      const adjustments = [
        {
          adjustment_type: delta.type,
          method_code,
          amount: Math.round(Number(delta.amount) * 100) / 100,
          _provisional: true,
        },
      ];
      const next = withEditDraftDirty({ ...cartNow, payment_adjustments: adjustments });
      cartRef.current = next;
      setCart(next);
      void savePreviousOrderEditDraft(next).catch(() => {});
      return next;
    }

    // Cashier already confirmed this exact tender mix — skip the dialog.
    // Provisional autosave cash must NEVER skip Alt+P / F8 confirmation.
    if (matched && !provisionalOnly) return cartNow;

    const adjustments = await promptPreviousOrderPaymentAdjustment(
      delta,
      cartNow.held_order_num ?? resolvePosBrowseNumber(cartNow),
      options,
    );
    const confirmed = (Array.isArray(adjustments) ? adjustments : []).map((row) => {
      const { _provisional: _omit, ...rest } = row ?? {};
      return rest;
    });
    const next = withEditDraftDirty({ ...cartNow, payment_adjustments: confirmed });
    cartRef.current = next;
    setCart(next);
    void savePreviousOrderEditDraft(next).catch(() => {});
    return next;
  }
'''

TEST_EXTRA = r'''
describe("previousOrderAdjustmentsAreProvisional", () => {
  it("detects provisional tender rows", () => {
    expect(previousOrderAdjustmentsAreProvisional([])).toBe(false);
    expect(
      previousOrderAdjustmentsAreProvisional([
        { adjustment_type: "return", amount: 100, method_code: "CASH" },
      ]),
    ).toBe(false);
    expect(
      previousOrderAdjustmentsAreProvisional([
        { adjustment_type: "return", amount: 100, method_code: "CASH", _provisional: true },
      ]),
    ).toBe(true);
  });
});

describe("paymentDeltaFromProvisionalAdjustments", () => {
  it("rebuilds return delta when baseline was rebased to the revised total", () => {
    // original_order_total already equals newTotal (0 for empty cart) after sync rebase.
    const cart = {
      original_order_total: 0,
      payment_adjustments: [
        {
          adjustment_type: "return",
          amount: 2000,
          method_code: "CASH",
          _provisional: true,
        },
      ],
      lines: [],
    };
    const delta = paymentDeltaFromProvisionalAdjustments(cart);
    expect(delta.type).toBe("return");
    expect(delta.amount).toBe(2000);
    expect(delta.originalTotal).toBe(2000);
    expect(delta.signedDelta).toBe(-2000);
  });
});
'''


def patch_helpers():
    path = FRONTEND / "src/lib/pos-edit-payment-adjustment.js"
    text = path.read_text()
    if "previousOrderAdjustmentsAreProvisional" in text:
        print("helpers already present")
        return
    needle = "  return Math.abs(total - Number(delta.amount)) < 0.02;\n}\n"
    if needle not in text:
        raise SystemExit("helpers needle missing")
    path.write_text(text.replace(needle, needle + "\n" + HELPERS.lstrip("\n"), 1))
    print("patched helpers")


def patch_pos_screen():
    path = FRONTEND / "src/components/sales/pos-screen.jsx"
    text = path.read_text()

    old_import = """import {
  buildPaymentAdjustmentsFromCheckoutBody,
  computePreviousOrderEditPaymentDelta,
  computePreviousOrderEditSignedDelta,
  previousOrderAdjustmentsMatchDelta,
} from "@/lib/pos-edit-payment-adjustment";"""
    new_import = """import {
  buildPaymentAdjustmentsFromCheckoutBody,
  computePreviousOrderEditPaymentDelta,
  computePreviousOrderEditSignedDelta,
  paymentDeltaFromProvisionalAdjustments,
  previousOrderAdjustmentsAreProvisional,
  previousOrderAdjustmentsMatchDelta,
} from "@/lib/pos-edit-payment-adjustment";"""
    if "previousOrderAdjustmentsAreProvisional," not in text and "previousOrderAdjustmentsAreProvisional }" not in text:
        if old_import not in text:
            raise SystemExit("import block missing")
        text = text.replace(old_import, new_import, 1)
        print("patched import")
    else:
        print("import already patched")

    start = text.find("  async function ensurePreviousOrderPaymentAdjustment(cartNow, options = {}) {")
    if start < 0:
        raise SystemExit("ensure fn missing")
    end = text.find("\n  const loadCompletedPosOrders = useCallback(async () => {", start)
    if end < 0:
        raise SystemExit("ensure fn end missing")
    existing = text[start:end]
    if "_provisional: true" in existing and "provisionalOnly" in existing:
        print("ensure already patched")
    else:
        text = text[:start] + ENSURE_FN.rstrip() + "\n\n" + text[end + 1 :]
        print("patched ensurePreviousOrderPaymentAdjustment")

    old_refresh = """    const keepDirty = editedOrderHasLocalDraftChanges(active);
    const base = isServerPosCartId(active.id)
      ? detachPreviousOrderEditCartId(active)
      : active;
    const next = {
      ...base,
      // Detached edit cart — next sync restores from this live sale id once.
      server_sale_id: sale.id,
      superseded_sale_id: sale.id,
      held_order_num: Number(sale.order_num),
      // Baseline for the next top-up/return is the total just synced.
      original_order_total: Math.round(Number(sale.order_total ?? 0) * 100) / 100,
      ...(sale.pos_order_num != null
        ? { pos_order_num: Number(sale.pos_order_num) }
        : {}),
      ...(sale.pos_order_date ? { pos_order_date: sale.pos_order_date } : {}),
      ...(keepDirty ? { _editDraftDirty: true } : {}),
    };"""
    new_refresh = """    const keepDirty = editedOrderHasLocalDraftChanges(active);
    const base = isServerPosCartId(active.id)
      ? detachPreviousOrderEditCartId(active)
      : active;
    // Do not rebase the prior bill total while payment breakdown is still provisional —
    // otherwise Alt+P / F8 see a zero delta and skip the method confirmation dialog.
    const pendingProvisionalTender = previousOrderAdjustmentsAreProvisional(
      active.payment_adjustments,
    );
    const sessionOriginal = Math.round(Number(active.original_order_total ?? 0) * 100) / 100;
    const next = {
      ...base,
      // Detached edit cart — next sync restores from this live sale id once.
      server_sale_id: sale.id,
      superseded_sale_id: sale.id,
      held_order_num: Number(sale.order_num),
      ...(pendingProvisionalTender || (keepDirty && sessionOriginal > 0.009)
        ? {
            // Keep session-start baseline until cashier confirms top-up/return method.
            original_order_total: sessionOriginal > 0.009
              ? sessionOriginal
              : Math.round(Number(sale.order_total ?? 0) * 100) / 100,
          }
        : {
            // Baseline for the next top-up/return is the total just synced.
            original_order_total: Math.round(Number(sale.order_total ?? 0) * 100) / 100,
          }),
      ...(sale.pos_order_num != null
        ? { pos_order_num: Number(sale.pos_order_num) }
        : {}),
      ...(sale.pos_order_date ? { pos_order_date: sale.pos_order_date } : {}),
      ...(keepDirty ? { _editDraftDirty: true } : {}),
    };"""
    if "pendingProvisionalTender" in text:
        print("refresh already patched")
    else:
        if old_refresh not in text:
            raise SystemExit("refresh block missing")
        text = text.replace(old_refresh, new_refresh, 1)
        print("patched refreshPreviousOrderEditCartAfterSync")

    path.write_text(text)


def patch_tests():
    path = FRONTEND / "src/lib/pos-edit-payment-adjustment.test.js"
    text = path.read_text()
    old_imp = """import {
  buildPaymentAdjustmentsFromCheckoutBody,
  computePreviousOrderEditPaymentDelta,
  computePreviousOrderEditSignedDelta,
  previousOrderAdjustmentsMatchDelta,
  resolvePosPaymentMethodCode,
} from "@/lib/pos-edit-payment-adjustment";"""
    new_imp = """import {
  buildPaymentAdjustmentsFromCheckoutBody,
  computePreviousOrderEditPaymentDelta,
  computePreviousOrderEditSignedDelta,
  paymentDeltaFromProvisionalAdjustments,
  previousOrderAdjustmentsAreProvisional,
  previousOrderAdjustmentsMatchDelta,
  resolvePosPaymentMethodCode,
} from "@/lib/pos-edit-payment-adjustment";"""
    if "previousOrderAdjustmentsAreProvisional," not in text:
        if old_imp not in text:
            raise SystemExit("test import block missing")
        text = text.replace(old_imp, new_imp, 1)
        print("patched test import")
    else:
        print("test import already patched")
    if 'describe("previousOrderAdjustmentsAreProvisional"' not in text:
        text = text.rstrip() + "\n" + TEST_EXTRA
        print("patched tests")
    else:
        print("tests already patched")
    path.write_text(text)


if __name__ == "__main__":
    patch_helpers()
    patch_pos_screen()
    patch_tests()
    print("done")
