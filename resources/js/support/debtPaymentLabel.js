/**
 * Primary label for a debt-related transaction row (list / modals).
 *
 * @param {object} transaction
 * @returns {string}
 */
export function debtPaymentCategoryLine(transaction) {
  if (!transaction?.is_debt_payment) {
    return '';
  }

  const debt = transaction.debt;
  const desc = typeof transaction.description === 'string' ? transaction.description : '';

  if (debt && typeof debt === 'object') {
    const counterparty = resolveDebtPaymentCounterpartyLabel(transaction, debt);
    if (transaction.type === 'income' && counterparty) {
      return `Repayment received · ${counterparty}`;
    }
    if (transaction.type === 'expense' && counterparty) {
      return `Debt Payment · ${counterparty}`;
    }
  }

  if (desc.startsWith('Debt Payment:')) {
    const name = desc.replace('Debt Payment:', '').trim();
    if (name) {
      return `Debt Payment: ${name}`;
    }
  }

  if (transaction.type === 'income') {
    return 'Debt repayment';
  }

  return 'Debt Payment';
}

/**
 * @param {object} transaction
 * @param {object} debt
 * @returns {string|null}
 */
function resolveDebtPaymentCounterpartyLabel(transaction, debt) {
  const uid = transaction.user_id;

  if (transaction.type === 'income' && debt.creditor_id != null && Number(uid) === Number(debt.creditor_id)) {
    const fromDebtor = debt.debtor?.name?.trim();
    return fromDebtor || null;
  }

  if (transaction.type === 'expense' && debt.debtor_id != null && Number(uid) === Number(debt.debtor_id)) {
    if (debt.creditor?.name?.trim()) {
      return debt.creditor.name.trim();
    }
    if (debt.creditor_name && String(debt.creditor_name).trim()) {
      return String(debt.creditor_name).trim();
    }
    if (debt.fund?.name) {
      return debt.fund.name;
    }
  }

  if (debt.creditor_name && String(debt.creditor_name).trim()) {
    return String(debt.creditor_name).trim();
  }

  if (debt.creditor?.name) {
    return debt.creditor.name;
  }

  if (debt.fund?.name) {
    return debt.fund.name;
  }

  if (debt.description && String(debt.description).trim()) {
    return String(debt.description).trim();
  }

  return null;
}
