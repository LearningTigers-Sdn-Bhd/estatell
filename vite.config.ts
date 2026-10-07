import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny, local } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('Geist', {
                    weights: [400, 500, 600],
                }),
                // The PDF font styles, from the same files dompdf uses, for the printing preview. Not preloaded:
                // a browser downloads them only on a page that uses them.
                ...(
                    [
                        ['Arimo', ['Arimo-Regular', 'Arimo-Bold']],
                        ['Inter', ['Inter-Regular', 'Inter-Bold']],
                        ['Lora', ['Lora-Regular', 'Lora-Bold']],
                        ['Nunito', ['Nunito-Regular', 'Nunito-Bold']],
                        ['Playfair Display', ['PlayfairDisplay-Bold']],
                        ['Caveat', ['Caveat-Bold']],
                    ] as const
                ).map(([family, files]) =>
                    local(family, {
                        variants: files.map((file) => ({
                            src: `resources/fonts/${file}.ttf`,
                            weight: file.endsWith('Bold') ? 700 : 400,
                        })),
                        preload: false,
                        optimizedFallbacks: false,
                    }),
                ),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/types/generated.d.ts',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.agents/**',
            '.ai/**',
            '.amp/**',
            '.claude/**',
            '.github/**',
            '.grok/**',
            '.mcp.json',
            '.zed/**',
            'AGENTS.md',
            'boost.json',
            'composer.json',
            'opencode.json',
            'resources/js/components/ui/*',
            'resources/js/types/generated.d.ts',
            'resources/js/types/typescript-transformer-manifest.json',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
