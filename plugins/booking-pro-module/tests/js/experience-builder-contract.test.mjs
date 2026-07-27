import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const source = fs.readFileSync(
  new URL("../../modules/experience-builder/assets/admin/index.jsx", import.meta.url),
  "utf8"
);

test("builder exposes the four phase-two modules", () => {
  for (const type of ["text", "image", "audio", "video"]) {
    assert.match(source, new RegExp(`${type}:`));
  }
});

test("builder supports accessible ordering and drag sorting", () => {
  assert.match(source, /Omhoog verplaatsen/);
  assert.match(source, /Omlaag verplaatsen/);
  assert.match(source, /draggable/);
  assert.match(source, /onDrop/);
});

test("builder supports duplicate remove disable and collapse", () => {
  assert.match(source, /Dupliceren/);
  assert.match(source, /window\.confirm/);
  assert.match(source, /Module actief/);
  assert.match(source, /aria-expanded/);
  assert.match(source, /useState\(Boolean\(initiallyOpen\)\)/);
  assert.match(source, /Al toegevoegd/);
  assert.match(source, /needsConfiguration/);
});

test("builder protects dirty edits and uses conflict-aware revision", () => {
  assert.match(source, /beforeunload/);
  assert.match(source, /expected_revision/);
  assert.match(source, /revision: 0, modules: \[\]/);
  assert.match(source, /X-WP-Nonce/);
  assert.match(source, /role="status"/);
});

test("builder never shadows the browser document object", () => {
  assert.doesNotMatch(source, /const \[document,\s*setDocument\]/);
  assert.match(source, /const \[moduleDocument,\s*setModuleDocument\]/);
  assert.match(source, /window\.document\.getElementById/);
});
