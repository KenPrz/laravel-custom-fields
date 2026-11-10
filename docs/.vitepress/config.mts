import { defineConfig } from 'vitepress'

// ponytail: shields.io reads the count live (it caches for about an hour).
const starsBadge =
  '<img src="https://img.shields.io/github/stars/KenPrz/laravel-custom-fields?style=social" alt="GitHub stars" style="display:block">'

export default defineConfig({
  title: 'Laravel Custom Fields',
  description: 'User-defined custom fields for Eloquent models.',
  base: '/laravel-custom-fields/',
  cleanUrls: true,
  lastUpdated: true,

  themeConfig: {
    search: { provider: 'local' },

    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'Examples', link: '/examples/production' },
      { text: 'Reference', link: '/reference/manager' },
      { text: 'Roadmap', link: '/roadmap' },
      { text: starsBadge, link: 'https://github.com/KenPrz/laravel-custom-fields/stargazers' },
    ],

    sidebar: [
      {
        text: 'Getting started',
        items: [
          { text: 'Introduction', link: '/guide/introduction' },
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Configuration', link: '/guide/configuration' },
        ],
      },
      {
        text: 'Basic usage',
        items: [
          { text: 'Model integration', link: '/guide/model-integration' },
          { text: 'Managing fields', link: '/guide/managing-fields' },
          { text: 'Field types', link: '/guide/field-types' },
          { text: 'Reading and writing values', link: '/guide/values' },
          { text: 'Validation', link: '/guide/validation' },
          { text: 'Form requests', link: '/guide/form-requests' },
          { text: 'Querying', link: '/guide/querying' },
        ],
      },
      {
        text: 'Advanced usage',
        items: [
          { text: 'Multi-tenancy and scopes', link: '/advanced/multi-tenancy' },
          { text: 'Artisan command', link: '/advanced/artisan-command' },
          { text: 'Custom field types', link: '/advanced/custom-field-types' },
          { text: 'Events', link: '/advanced/events' },
          { text: 'Caching', link: '/advanced/caching' },
          { text: 'Performance', link: '/advanced/performance' },
        ],
      },
      {
        text: 'Integrations',
        items: [
          { text: 'Building forms', link: '/integrations/forms' },
          { text: 'API resources', link: '/integrations/api-resources' },
          { text: 'Tenancy packages', link: '/integrations/tenancy' },
          { text: 'Audit log', link: '/integrations/audit-log' },
        ],
      },
      {
        text: 'Examples',
        items: [
          { text: 'Production scenarios', link: '/examples/production' },
          { text: 'API controller', link: '/examples/api-controller' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Manager methods', link: '/reference/manager' },
          { text: 'Model methods', link: '/reference/model' },
          { text: 'Database compatibility', link: '/reference/databases' },
          { text: 'Testing', link: '/reference/testing' },
          { text: 'Limitations', link: '/reference/limitations' },
          { text: 'Roadmap', link: '/roadmap' },
        ],
      },
    ],

    socialLinks: [{ icon: 'github', link: 'https://github.com/KenPrz/laravel-custom-fields' }],

    editLink: {
      pattern: 'https://github.com/KenPrz/laravel-custom-fields/edit/main/docs/:path',
    },

    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
