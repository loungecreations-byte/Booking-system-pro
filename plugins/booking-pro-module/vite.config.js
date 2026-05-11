import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import path from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Build as ES module with everything inlined - use classic JSX runtime to avoid hoisting issues
export default defineConfig({
  plugins: [
    react({
      include: ['**/*.jsx', '**/*.tsx', '**/*.js', '**/*.ts'],
      // Use classic runtime to avoid React automatic import hoisting issues
      jsxRuntime: 'classic',
    }),
  ],
  root: "",
  resolve: {
    preserveSymlinks: true,
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  esbuild: {
    // Remove console.log and console.warn in production builds
    drop: ['console', 'debugger'],
  },
  build: {
    outDir: "build",
    emptyOutDir: false,
    manifest: true,
    target: 'es2015',
    minify: 'esbuild',
    rollupOptions: {
      input: {
        activityOverview: path.resolve(__dirname, "modules/product-overview/assets/js/activity-overview/overzicht-activiteiten.tsx"),
        dayPlanner: path.resolve(__dirname, "assets/js/day-planner/index.jsx"),
      },
      output: {
        // ES module format with inlined imports
        format: 'es',
        entryFileNames: "js/[name].js",
        // Preserve module structure to avoid hoisting issues
        hoistTransitiveImports: false,
      },
      // Ensure proper ordering
      treeshake: {
        moduleSideEffects: true,
      },
    },
  },
});
