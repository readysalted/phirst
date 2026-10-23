#!/usr/bin/env python3
"""Cache public PHIRST source content for a repeatable, offline WP import.

The destination must be outside the theme repository. No authentication or
changes to the legacy site are required. Existing cache files are reused.
"""

import argparse
import concurrent.futures
import json
import hashlib
import re
import subprocess
from html import unescape
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlsplit, unquote

SOURCE = 'https://phirst.nihr.ac.uk'


def download(url, target):
    if target.is_file() and target.stat().st_size:
        return target
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_suffix(target.suffix + '.part')
    subprocess.run([
        'curl', '--fail', '--location', '--silent', '--show-error',
        '--retry', '2', '--connect-timeout', '20', '--max-time', '90',
        url, '-o', str(temporary),
    ], check=True)
    temporary.replace(target)
    return target


def fetch_collection(kind, directory):
    items = []
    page = 1
    while True:
        target = directory / 'api' / f'{kind}-{page}.json'
        download(f'{SOURCE}/wp-json/wp/v2/{kind}?per_page=100&page={page}&_embed=wp:featuredmedia', target)
        current = json.loads(target.read_text())
        if not isinstance(current, list):
            raise ValueError(f'Invalid {kind} response: {current}')
        items.extend(current)
        if len(current) < 100:
            break
        page += 1
    return items


class SourceLinks(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = set()

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        for key in ['src', 'href', 'data-src']:
            if attrs.get(key):
                self.urls.add(urljoin(SOURCE, attrs[key]).split('#')[0])


def collect_linked_content(manifest, directory):
    urls = set()
    wxr_file = directory / 'legacy-wxr.json'
    if wxr_file.exists():
        wxr = json.loads(wxr_file.read_text())
        references = set()
        for item in wxr['items']:
            for value in item['meta'].values():
                urls.update(unescape(url).replace('http://phirst.nihr.ac.uk/', SOURCE + '/') for url in re.findall(r'https?://phirst\.nihr\.ac\.uk/wp-content/uploads/[^\s<>\"\']+', value))
                if value.isdigit():
                    references.add(value)
                references.update(re.findall(r's:\d+:"(\d+)"', value))
            references.update(re.findall(r'wp-image-(\d+)', item['content']))
        for reference in references:
            attachment = wxr['attachments'].get(reference)
            if attachment:
                urls.add(attachment['attachment_url'])
    for file in (directory / 'html').glob('*.html'):
        parser = SourceLinks()
        parser.feed(file.read_text(errors='replace'))
        urls.update(parser.urls)

    manifest['staff'] = []
    for url in sorted(urls):
        parsed = urlsplit(url)
        if parsed.netloc == 'phirst.nihr.ac.uk' and re.fullmatch(r'/staff/[^/]+/', parsed.path):
            slug = parsed.path.strip('/').split('/')[-1]
            file = directory / 'html' / f'staff-{slug}.html'
            try:
                download(url, file)
                manifest['staff'].append({'slug': slug, 'link': url, 'file': str(file.relative_to(directory))})
                parser = SourceLinks()
                parser.feed(file.read_text(errors='replace'))
                urls.update(parser.urls)
            except subprocess.CalledProcessError:
                print(f'Unavailable staff profile: {url}', flush=True)
    print(f'Linked staff profiles: {len(manifest["staff"])}', flush=True)

    for kind in ['pages', 'posts']:
        for item in manifest[kind]:
            for media in item.get('_embedded', {}).get('wp:featuredmedia', []):
                if media.get('source_url'):
                    urls.add(media['source_url'])
            for image in item.get('yoast_head_json', {}).get('og_image', []):
                if image.get('url'):
                    urls.add(image['url'])

    assets = {}
    for url in sorted(urls):
        parsed = urlsplit(url)
        if parsed.netloc != 'phirst.nihr.ac.uk' or '/wp-content/uploads/' not in parsed.path:
            continue
        filename = unquote(parsed.path.rsplit('/', 1)[-1])
        if not re.search(r'\.(png|jpe?g|gif|svg|webp|avif|pdf|docx?|xlsx?|pptx?|zip|mp[34]|wav)$', filename, re.I):
            continue
        digest = hashlib.sha256(url.encode()).hexdigest()[:12]
        assets[url] = {'file': f'assets/{digest}-{filename}', 'filename': filename}

    failures = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
        jobs = {executor.submit(download, url, directory / spec['file']): url for url, spec in assets.items()}
        for index, future in enumerate(concurrent.futures.as_completed(jobs), start=1):
            try:
                future.result()
            except subprocess.CalledProcessError:
                failures.append(jobs[future])
            if index % 40 == 0 or index == len(jobs):
                print(f'Media/documents cached: {index}/{len(jobs)}', flush=True)
    for url in failures:
        del assets[url]
    manifest['assets'] = assets
    manifest['unavailableAssets'] = failures
    print(f'Unavailable source assets: {len(failures)}', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    directory = args.directory.resolve()
    repository = Path(__file__).resolve().parents[1]
    if directory == repository or repository in directory.parents:
        parser.error('Keep source snapshots outside the Git repository.')

    manifest = {'source': SOURCE, 'pages': [], 'posts': []}
    for kind in ['pages', 'posts', 'theme', 'categories', 'tags']:
        manifest[kind] = fetch_collection(kind, directory)
        print(f'{kind}: {len(manifest[kind])}', flush=True)

    jobs = [(item['link'], directory / 'html' / f'{kind}-{item["id"]}.html')
            for kind in ['pages', 'posts'] for item in manifest[kind]]
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
        for index, future in enumerate([executor.submit(download, *job) for job in jobs], start=1):
            future.result()
            if index % 20 == 0 or index == len(jobs):
                print(f'HTML snapshots: {index}/{len(jobs)}', flush=True)

    collect_linked_content(manifest, directory)
    (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    print('Source manifest saved. Original HTML and metadata remain outside Git.', flush=True)


if __name__ == '__main__':
    main()
