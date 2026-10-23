#!/usr/bin/env python3
"""Extract published PHIRST content and attachment references from a WXR export.

Run against the private source directory after downloading its source-export.xml.
The export and resulting data must remain outside the theme's Git repository.
"""

import json
import sys
from pathlib import Path
from xml.etree import ElementTree

NS = {
    'wp': 'http://wordpress.org/export/1.2/',
    'content': 'http://purl.org/rss/1.0/modules/content/',
    'excerpt': 'http://wordpress.org/export/1.2/excerpt/',
    'dc': 'http://purl.org/dc/elements/1.1/',
}


def main():
    directory = Path(sys.argv[1]).resolve()
    repository = Path(__file__).resolve().parents[1]
    if directory == repository or repository in directory.parents:
        raise ValueError('Keep source exports outside the Git repository.')
    result = {'items': [], 'attachments': {}}
    for _, item in ElementTree.iterparse(directory / 'source-export.xml', events=['end']):
        if item.tag != 'item':
            continue

        def value(key):
            return item.findtext(key, default='', namespaces=NS)

        kind = value('wp:post_type')
        status = value('wp:status')
        if (kind in ['page', 'post', 'staff'] and status == 'publish') or kind == 'attachment':
            entry = {
                'id': int(value('wp:post_id')), 'type': kind, 'status': status,
                'title': value('title'), 'slug': value('wp:post_name'),
                'link': value('link'), 'parent': int(value('wp:post_parent') or 0),
                'date': value('wp:post_date'), 'date_gmt': value('wp:post_date_gmt'),
                'modified': value('wp:post_modified'),
                'content': value('content:encoded'), 'excerpt': value('excerpt:encoded'),
                'author': value('dc:creator'), 'attachment_url': value('wp:attachment_url'),
                'meta': {m.findtext('wp:meta_key', namespaces=NS): m.findtext('wp:meta_value', default='', namespaces=NS)
                         for m in item.findall('wp:postmeta', NS)},
                'terms': [{'taxonomy': c.get('domain'), 'slug': c.get('nicename'), 'name': c.text or ''}
                          for c in item.findall('category')],
            }
            if kind == 'attachment':
                result['attachments'][str(entry['id'])] = entry
            else:
                result['items'].append(entry)
        item.clear()
    (directory / 'legacy-wxr.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(f'Published content: {len(result["items"])}; attachment references: {len(result["attachments"])}')


if __name__ == '__main__':
    main()
