const CLOSEOUT_FUND_TRANSFER_PREFIX = 'Closeout transfer to fund:';

/**
 * Hard-close ledger rows that move remaining money into a fund.
 * These are not ordinary expenses; they pair with `FundMovement` type `closeout_allocation`.
 *
 * @param {object|null|undefined} tx
 * @returns {boolean}
 */
export function isCloseoutFundMovement(tx) {
  if (!tx?.is_closeout_initiated || tx.is_debt_payment || tx.type !== 'expense') {
    return false;
  }

  const movements = tx.fund_movements || tx.fundMovements || [];
  if (movements.some((movement) => movement?.type === 'closeout_allocation')) {
    return true;
  }

  return String(tx.description || '').startsWith(CLOSEOUT_FUND_TRANSFER_PREFIX);
}

/**
 * Display name of the destination fund for a closeout fund-allocation transaction.
 *
 * @param {object|null|undefined} tx
 * @returns {string}
 */
export function closeoutFundName(tx) {
  const movements = tx?.fund_movements || tx?.fundMovements || [];
  const named = movements.find((movement) => movement?.type === 'closeout_allocation' && movement?.fund?.name);
  if (named?.fund?.name) {
    return named.fund.name;
  }

  const description = String(tx?.description || '');
  if (description.startsWith(CLOSEOUT_FUND_TRANSFER_PREFIX)) {
    const name = description.slice(CLOSEOUT_FUND_TRANSFER_PREFIX.length).trim();
    if (name) {
      return name;
    }
  }

  return 'Fund';
}
