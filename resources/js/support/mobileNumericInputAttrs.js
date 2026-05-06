/**
 * Spread onto `<input type="number">` so iOS/Android open a digit-first keypad instead of
 * the full QWERTY keyboard with a secondary “numbers” strip.
 *
 * - `decimal` — currency, APR %, split percentages (fractional values; decimal key when needed).
 * - `numeric` — whole numbers only (rule order, counts); often maps to the large telephone-style grid on iPhone.
 *
 * Browsers map `inputmode` to the best available keyboard; there is no portable API to force
 * a literal “dial pad” while also requiring a decimal separator — use `decimal` for money.
 */
export const mobileDecimalNumberAttrs = {
    inputmode: 'decimal',
    enterkeyhint: 'done',
};

export const mobileIntegerNumberAttrs = {
    inputmode: 'numeric',
    enterkeyhint: 'done',
};
