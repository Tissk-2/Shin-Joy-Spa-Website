const PHONE_PATTERN = /^[+\d][\d\s-]{7,}$/;

export function isValidPhoneNumber(value) {
  if (typeof value !== 'string') return false;

  const phone = value.trim();
  return PHONE_PATTERN.test(phone) && phone.replace(/\D/g, '').length >= 8;
}
