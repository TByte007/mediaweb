#!/usr/bin/env node
/** Patch avbridge dist/player.js: Firefox-only swscale YUV→RGBA before VideoFrame. */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
const target = [
  path.resolve(process.cwd(), "node_modules/avbridge/dist/player.js"),
  path.join(root, "tmp/avbridge-player-build/node_modules/avbridge/dist/player.js"),
].find((p) => fs.existsSync(p));
if (!target) {
  console.error("avbridge dist/player.js not found (install avbridge@2.13.0 under tmp/avbridge-player-build)");
  process.exit(1);
}

let src = fs.readFileSync(target, "utf8");
if (src.includes("toRgbaFrame")) {
  console.log("Already patched:", target);
  process.exit(0);
}

const sws = `
  // MediaWeb: Firefox drawImage(I420) corrupts — swscale to RGBA in Wasm first.
  const firefoxRgba = typeof navigator !== "undefined" && /Firefox\\//.test(navigator.userAgent);
  let swsCtx = 0, swsSrcPtr = 0, swsDstPtr = 0, swsKey = "";
  async function freeSws() {
    if (swsCtx) try { await libav.sws_freeContext(swsCtx); } catch {}
    if (swsSrcPtr) try { await libav.av_frame_free_js(swsSrcPtr); } catch {}
    if (swsDstPtr) try { await libav.av_frame_free_js(swsDstPtr); } catch {}
    swsCtx = swsSrcPtr = swsDstPtr = 0;
    swsKey = "";
  }
  async function toRgbaFrame(f) {
    const w = f.width | 0, h = f.height | 0, fmt = f.format;
    if (!w || !h || fmt === libav.AV_PIX_FMT_RGBA) return f;
    const key = \`\${w}x\${h}:\${fmt}\`;
    if (!swsCtx || swsKey !== key) {
      await freeSws();
      swsCtx = await libav.sws_getContext(w, h, fmt, w, h, libav.AV_PIX_FMT_RGBA, 2, 0, 0, 0);
      if (!swsCtx) throw new Error("sws_getContext failed");
      swsSrcPtr = await libav.av_frame_alloc();
      swsDstPtr = await libav.av_frame_alloc();
      swsKey = key;
    }
    await libav.ff_copyin_frame(swsSrcPtr, f);
    await libav.av_frame_unref(swsDstPtr);
    const rc = await libav.sws_scale_frame(swsCtx, swsDstPtr, swsSrcPtr);
    if (rc < 0) throw new Error(\`sws_scale_frame failed (\${rc})\`);
    const out = await libav.ff_copyout_frame(swsDstPtr);
    out.pts = f.pts;
    out.ptshi = f.ptshi;
    return out;
  }
`;

// Unique to fallback startDecoder (not hybrid).
const anchor = `or use a lighter strategy (native, remux, hybrid) instead.\`
    );
  }
  let bsfCtx = null;`;
if (!src.includes(anchor)) {
  console.error("fallback decoder anchor not found in", target);
  process.exit(1);
}
src = src.replace(anchor, `or use a lighter strategy (native, remux, hybrid) instead.\`
    );
  }
${sws}  let bsfCtx = null;`);

const oldVf = `        const vf = bridge.laFrameToVideoFrame(f, { timeBase: [1, 1e6] });`;
if (!src.includes(oldVf)) {
  console.error("laFrameToVideoFrame call not found");
  process.exit(1);
}
src = src.replace(
  oldVf,
  `        const frameForVf = firefoxRgba ? await toRgbaFrame(f) : f;
        const vf = bridge.laFrameToVideoFrame(frameForVf, { timeBase: [1, 1e6] });`,
);

const destroyAnchor = `(err) => console.error("[avbridge] decoder pump failed:", err)
  );
  return {
    async destroy() {
      destroyed = true;
      pumpToken++;
      try {
        await pumpRunning;
      } catch {
      }
      try {
        if (bsfCtx) await libav.av_bsf_free(bsfCtx);
      } catch {
      }`;
if (!src.includes(destroyAnchor)) {
  console.error("destroy() anchor not found");
  process.exit(1);
}
src = src.replace(
  destroyAnchor,
  `(err) => console.error("[avbridge] decoder pump failed:", err)
  );
  return {
    async destroy() {
      destroyed = true;
      pumpToken++;
      try {
        await pumpRunning;
      } catch {
      }
      try {
        await freeSws();
      } catch {
      }
      try {
        if (bsfCtx) await libav.av_bsf_free(bsfCtx);
      } catch {
      }`,
);

fs.writeFileSync(target, src);
console.log("Patched", target);
