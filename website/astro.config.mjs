import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

const repository = 'https://github.com/OzzyCzech/icalparser';

export default defineConfig({
	site: 'https://ozzyczech.github.io',
	base: '/icalparser',
	trailingSlash: 'always',
	integrations: [
		starlight({
			title: 'PHP iCal Parser',
			description: 'A lightweight and robust iCalendar (RFC 5545) parser for PHP.',
			social: [{ icon: 'github', label: 'GitHub', href: repository }],
			sidebar: [
				{ label: 'Introduction', link: '/' },
				{
					label: 'Guides',
					items: [
						{ label: 'Reading calendars', link: '/reading/' },
						{ label: 'Values and timezones', link: '/values/' },
						{ label: 'Recurrence', link: '/recurrence/' },
						{ label: 'Creating calendars', link: '/creating/' },
						{ label: 'Parsing and limits', link: '/parsing/' },
						{ label: 'Validation', link: '/validation/' },
						{ label: 'Recipes', link: '/recipes/' },
					],
				},
				{
					label: 'Reference',
					items: [
						{ label: 'API reference', link: 'https://ozzyczech.github.io/icalparser/api/' },
						{ label: 'Upgrading from 4.x', link: '/upgrading/' },
						{ label: 'Changelog', link: '/changelog/' },
						{ label: 'Examples', link: `${repository}/tree/main/examples` },
					],
				},
			],
		}),
	],
});
