import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const runtime = readFileSync(new URL("../../assets/js/tour-navigation.js", import.meta.url), "utf8");
const camera = readFileSync(new URL("../../modules/discovery-camera/Assets/discovery-camera.js", import.meta.url), "utf8");
const cameraCss = readFileSync(new URL("../../modules/discovery-camera/Assets/discovery-camera.css", import.meta.url), "utf8");
const tourCss = readFileSync(new URL("../../assets/css/tour-navigation.css", import.meta.url), "utf8");
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
  assert.match(camera, /currentHost\.replaceChildren\(challenge\)/);
  assert.match(camera, /host\?\.closest\("\[data-experience-modules\]"\)/);
  assert.match(camera, /root\.querySelector\("\[data-experience-modules\]"\)/);
});

test("runtime replaces the loading placeholder when camera configuration fails", () => {
  assert.match(camera, /currentHost\.replaceChildren\(fallback\)/);
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

test("modular camera layout owns its width and remains mobile first", () => {
  assert.match(tourCss, /\.tour-story-layout--modular > \.tour-experience-modules/);
  assert.match(tourCss, /grid-area: auto/);
  assert.match(camera, /ddb-camera__brief/);
  assert.match(camera, /ddb-camera__capture/);
  assert.match(cameraCss, /grid-template-columns: minmax\(16rem, 0\.8fr\) minmax\(20rem, 1\.2fr\)/);
  assert.match(cameraCss, /@media \(max-width: 640px\)[\s\S]*grid-template-columns: minmax\(0, 1fr\)/);
});
