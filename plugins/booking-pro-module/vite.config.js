import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import path from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export default defineConfig({
  plugins: [
    react({
      include: ['**/*.jsx', '**/*.tsx', '**/*.js', '**/*.ts'],
      jsxRuntime: 'automatic',
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
        bookingBoard: path.resolve(__dirname, "assets/js/admin/booking-board/index.jsx"),
        dayPlanner: path.resolve(__dirname, "assets/js/day-planner/index.jsx"),
        "tour-navigation": path.resolve(__dirname, "assets/js/tour-navigation.js"),
        gamificationProgress: path.resolve(__dirname, "modules/gamification/assets/progress/index.jsx"),
      },
      output: {
        format: 'es',
        entryFileNames: "js/[name].js",
        manualChunks: {
          react: ['react', 'react-dom'],
        },
        hoistTransitiveImports: false,
      },
      // Ensure proper ordering
      treeshake: {
        moduleSideEffects: true,
      },
    },
  },
});
