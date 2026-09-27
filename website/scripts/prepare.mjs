// Copies the Markdown documentation of the repository into the Starlight content collection.
// docs/*.md, UPGRADING.md and CHANGELOG.md stay readable on GitHub; here the H1 becomes the title
// in the front matter and links between the files become links between the pages.
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const target = join(root, 'website/src/content/docs');
const base = '/icalparser/';
const edit = 'https://github.com/OzzyCzech/icalparser/edit/main/';

const sources = [
	...readdirSync(join(root, 'docs')).filter((file) => file.endsWith('.md') && file !== 'modernization.md').map((file) => `docs/${file}`),
	'UPGRADING.md',
	'CHANGELOG.md',
];

const slug = (file) => basename(file, '.md').toLowerCase();
const url = (file) => (slug(file) === 'index' ? base : `${base}${slug(file)}/`);
const pages = new Map(sources.map((file) => [basename(file), url(file)]));
pages.set('api/index.md', `${base}api/`);

rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });

for (const file of sources) {
	let content = readFileSync(join(root, file), 'utf8');
	const heading = content.match(/^# (.+)\n+/);
	if (!heading) {
		throw new Error(`${file} has no H1 heading.`);
	}
	content = content.slice(heading[0].length);
	content = content.replace(/\]\(([^)#\s]+\.md)(#[^)]*)?\)/g, (link, path, anchor = '') => {
		const page = pages.get(path);
		if (!page) {
			throw new Error(`${file} links to an unknown page ${path}.`);
		}
		return `](${page}${anchor})`;
	});
	const front = [
		'---',
		`title: ${JSON.stringify(heading[1])}`,
		`editUrl: ${JSON.stringify(edit + file)}`,
		'---',
		'',
	];
	writeFileSync(join(target, `${slug(file)}.md`), front.join('\n') + content);
}
console.log(`Prepared ${sources.length} pages.`);
