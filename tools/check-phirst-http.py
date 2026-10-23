#!/usr/bin/env python3
"""Check every destination URL from verify-phirst-migration.php locally."""

import concurrent.futures
import json
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit


def main():
    directory = Path(sys.argv[1]).resolve()
    repository = Path(__file__).resolve().parents[1]
    if directory == repository or repository in directory.parents:
        raise ValueError('Keep reports and page snapshots outside Git.')
    report = json.loads((directory / 'verification-report.json').read_text())
    urls = sorted(set(report['urls']))
    if any(urlsplit(url).hostname != 'phirst.local' for url in urls):
        raise ValueError('This check is restricted to the local PHIRST site.')
    output = directory / 'qa-http'
    output.mkdir(exist_ok=True)

    def check(url):
        name = urlsplit(url).path.strip('/').replace('/', '_')[:180] or 'home'
        file = output / (name + '.html')
        response = subprocess.run([
            'curl', '--silent', '--show-error', '--max-time', '60',
            '-o', str(file), '-w', '%{http_code}', url,
        ], capture_output=True, text=True, check=False)
        body = file.read_text(errors='replace') if file.exists() else ''
        main_content = body.split('<main', 1)[-1].split('</main>', 1)[0]
        return {
            'url': url, 'status': response.stdout,
            'h1': len(re.findall(r'<h1\b', main_content)),
            'lorem': bool(re.search('lorem ipsum', body, re.I)),
            'fatal': bool(re.search(r'Fatal error|critical error|Twig\\Error', body)),
        }

    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
        results = list(executor.map(check, urls))
    (directory / 'http-report.json').write_text(json.dumps(results, indent=2) + '\n')
    errors = [row for row in results if row['status'] != '200' or row['h1'] != 1 or row['lorem'] or row['fatal']]
    print(f'Checked {len(results)} unique local URLs; failures: {len(errors)}')
    if errors:
        print(json.dumps(errors, indent=2))
        sys.exit(1)


if __name__ == '__main__':
    main()
