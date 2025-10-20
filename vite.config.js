import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import path from "path";

export default defineConfig({
  plugins: [
    react({
      include: ['**/*.jsx', '**/*.tsx', '**/*.js', '**/*.ts'],
    }),
  ],
  root: "",
  build: {
    outDir: "build",
    emptyOutDir: false,
    manifest: true,
    rollupOptions: {
      input: {
        bookingBoard: path.resolve(__dirname, "assets/js/admin/booking-board/index.jsx"),
        dayPlanner: path.resolve(__dirname, "assets/js/day-planner/index.jsx"),
      },
      output: {
        entryFileNames: "js/[name].js",
        chunkFileNames: "js/[name]-[hash].js",
        assetFileNames: "assets/[name]-[hash][extname]",
      },
    },
  },
});
