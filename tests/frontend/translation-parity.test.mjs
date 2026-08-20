import assert from 'node:assert/strict';
import test from 'node:test';

import translations from '../../src/Data/translations.js';

function recursiveKeys(value, prefix = '') {
  return Object.entries(value).flatMap(([key, child]) => {
    const path = prefix ? `${prefix}.${key}` : key;

    return child && typeof child === 'object' && !Array.isArray(child)
      ? recursiveKeys(child, path)
      : [path];
  }).sort();
}

test('Indonesian and English UI dictionaries have recursive key parity', () => {
  assert.deepEqual(recursiveKeys(translations.id), recursiveKeys(translations.en));
});

test('verification and recovery UI copy exists in both languages', () => {
  const requiredKeys = [
    'auth.forgot.title',
    'auth.forgot.sub',
    'auth.forgot.submit',
    'auth.forgot.pending',
    'auth.forgot.sent',
    'auth.reset.title',
    'auth.reset.sub',
    'auth.reset.submit',
    'auth.reset.pending',
    'auth.verify.title',
    'auth.verify.sub',
    'auth.verify.sent',
    'auth.verify.resend',
    'auth.verify.resending',
    'auth.verify.logout',
  ];

  for (const locale of ['id', 'en']) {
    for (const key of requiredKeys) {
      assert.equal(typeof translations[locale][key], 'string', `${locale} translation missing: ${key}`);
      assert.notEqual(translations[locale][key].trim(), '', `${locale} translation is blank: ${key}`);
    }
  }
});
