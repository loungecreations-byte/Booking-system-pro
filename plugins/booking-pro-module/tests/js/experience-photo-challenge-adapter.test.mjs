import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const runtime = readFileSync(new URL("../../assets/js/tour-navigation.js", import.meta.url), "utf8");
const camera = readFileSync(new URL("../../modules/discovery-camera/Assets/discovery-camera.js", import.meta.url), "utf8");
const builder = readFileSync(new URL("../../modules/experience-builder/assets/admin/index.jsx", import.meta.url), "utf8");

test("builder exposes the existing Discovery Camera as an adapter", () => {
  assert.match(builder, /ai_photo_challenge: \{ label: "AI Photo Challenge"/);
  assert.match(builder, /source: "chapter_meta"/);
  assert.match(builder, /Open camera-instellingen/);
  assert.match(builder, /photo_approved/);
});

test("runtime mounts the camera in ordered module position", () => {
  assert.match(runtime, /data-photo-challenge-module-host/);
  assert.match(camera, /module\?\.type === "ai_photo_challenge"/);
  assert.match(camera, /host\.replaceChildren\(challenge\)/);
});

test("runtime replaces the loading placeholder when camera configuration fails", () => {
  assert.match(camera, /host\.replaceChildren\(fallback\)/);
  assert.match(camera, /fallback\.setAttribute\("role", "alert"\)/);
});

test("approved server result updates modular runtime state", () => {
  assert.match(camera, /ddb:photo-challenge-passed/);
  assert.match(camera, /result\.rewards\?\.module_completion/);
  assert.match(runtime, /detail\.moduleCompletion/);
  assert.match(runtime, /completion\.module_completed/);
});

test("camera challenge has a non-rewarding accessible alternative", () => {
  assert.match(camera, /data-camera-alternative/);
  assert.match(camera, /Ik kan geen camera gebruiken/);
  assert.match(camera, /nooit zelfstandig XP of voortgang toe/);
  assert.doesNotMatch(camera, /camera_alternative.*module_completed/s);
});
