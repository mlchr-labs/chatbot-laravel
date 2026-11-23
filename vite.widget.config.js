import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Build separado do painel: gera um unico public/widget.js (IIFE) com o CSS
// do Tailwind embutido como string (import ?inline) para injetar no Shadow DOM.
export default defineConfig({
    plugins: [tailwindcss()],
    publicDir: false,
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        outDir: 'public',
        emptyOutDir: false,
        copyPublicDir: false,
        cssCodeSplit: false,
        sourcemap: false,
        lib: {
            entry: 'resources/widget/main.js',
            name: 'ChatbotWidgetBundle',
            formats: ['iife'],
            fileName: () => 'widget.js',
        },
    },
});
