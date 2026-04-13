import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel ClickHouse',
  description: 'A ClickHouse database driver for Laravel with Eloquent, Query Builder, and Schema Builder support.',
  base: '/laravel-clickhouse/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/laravel-clickhouse/logo.svg' }],
  ],

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/installation' },
      {
        text: 'Resources',
        items: [
          { text: 'GitHub', link: 'https://github.com/laravel-clickhouse/laravel-clickhouse' },
          { text: 'Packagist', link: 'https://packagist.org/packages/laravel-clickhouse/laravel-clickhouse' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
        ],
      },
      {
        text: 'Usage',
        items: [
          { text: 'Query Builder', link: '/guide/query-builder' },
          { text: 'Eloquent', link: '/guide/eloquent' },
          { text: 'Schema', link: '/guide/schema' },
          { text: 'Parallel Queries', link: '/guide/parallel-queries' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Advanced', link: '/guide/advanced' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/laravel-clickhouse/laravel-clickhouse' },
    ],

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/laravel-clickhouse/laravel-clickhouse/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
