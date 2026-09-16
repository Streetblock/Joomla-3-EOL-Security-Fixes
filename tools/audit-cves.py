#!/usr/bin/env python3
"""Reproducible Joomla post-EOL CVE inventory; no target website is contacted.

Standard library only. README mentions are coverage CLAIMS, never proof of a fix.
Raw responses remain in --cache; --offline replays the exact cached snapshot.
"""
import argparse
import datetime as dt
import hashlib
import html
import json
import re
import time
import urllib.request
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = 'https://developer.joomla.org'


def plain(value):
    return ' '.join(html.unescape(re.sub(r'<[^>]*>', ' ', value)).split())


def advisories(page):
    result = []
    for block in re.split(r'<h2\b[^>]*>', page, flags=re.I)[1:]:
        match = re.search(r'href="(/security-centre/\d+-(\d{8})[^"#]*)"[^>]*>(.*?)</a>', block, re.S)
        if not match:
            continue
        fields = {}
        for item in re.findall(r'<li\b[^>]*>(.*?)</li>', block, re.S):
            text = plain(item)
            if ':' in text:
                key, value = text.split(':', 1)
                fields[key.strip()] = value.strip()
        for label in ('Description', 'Solution', 'Affected Installs'):
            section = re.search(r'<h3[^>]*>\s*' + label + r'\s*</h3>(.*?)(?=<h3|$)', block, re.S | re.I)
            if section:
                fields[label] = plain(section[1])
        display_ids = sorted(set(re.findall(r'CVE-\d{4}-\d{4,}', fields.get('CVE Number', ''))))
        linked_ids = sorted(set(re.findall(r'href="https?://(?:www\.)?(?:cve\.org|cve\.mitre\.org)/[^"\s]*?(CVE-\d{4}-\d{4,})[^"\s]*"', block)))
        ids = linked_ids or display_ids
        if linked_ids and linked_ids != display_ids:
            fields['CVE display/link discrepancy'] = dict(display=display_ids, linked=linked_ids)
        if not ids or not fields.get('Fixed Date') or not fields.get('Versions'):
            raise ValueError('Incomplete advisory parsing: ' + match[1])
        result.append(dict(url=BASE + match[1], advisory_id=match[2], title=plain(match[3]), cves=ids, **fields))
    return result


def scores(cve):
    values = []
    for kind, metrics in cve.get('metrics', {}).items():
        for metric in metrics:
            data = metric.get('cvssData', {})
            if 'baseScore' in data:
                values.append(dict(version=data.get('version', kind), source=metric.get('source'),
                                   score=data['baseScore'], vector=data.get('vectorString')))
    return values


def affected_hint(advisory):
    if advisory.get('SubProject', '').upper() != 'CMS':
        return 'framework-needs-code-review'
    ranges = re.findall(r'(\d+\.\d+\.\d+)\s*[-\u2013]\s*(\d+\.\d+\.\d+)', advisory['Versions'])
    version = lambda x: tuple(map(int, x.split('.')))
    if any(version(lo) <= (3, 10, 12) <= version(hi) for lo, hi in ranges):
        return 'joomla3-in-advisory-range'
    if ranges and all(version(lo) > (3, 10, 12) or version(hi) < (3, 10, 12) for lo, hi in ranges):
        return 'outside-advisory-range-needs-confirmation'
    return 'unresolved-range'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--since', default='2023-08-17')
    parser.add_argument('--until', default=dt.date.today().isoformat())
    parser.add_argument('--cache', type=Path, required=True)
    parser.add_argument('--offline', action='store_true')
    parser.add_argument('--output', type=Path, default=ROOT / 'docs/cve-inventory.json')
    args = parser.parse_args()
    args.cache.mkdir(parents=True, exist_ok=True)
    sources = []

    def fetch(url, filename, payload=None):
        path = args.cache / filename
        request_data = json.dumps(payload).encode() if payload is not None else None
        if not path.exists():
            if args.offline:
                raise RuntimeError('Missing cached response: ' + str(path))
            for attempt in range(3):
                try:
                    req = urllib.request.Request(url, data=request_data, headers={
                        'User-Agent': 'Joomla-EOL-security-audit/1.0', 'Content-Type': 'application/json'})
                    with urllib.request.urlopen(req, timeout=50) as response:
                        path.write_bytes(response.read())
                    break
                except Exception:
                    if attempt == 2:
                        raise
                    time.sleep(7 * (attempt + 1))
        raw = path.read_bytes()
        sources.append(dict(url=url, cache_file=filename, sha256=hashlib.sha256(raw).hexdigest(),
                            request_sha256=hashlib.sha256(request_data).hexdigest() if request_data else None))
        return raw.decode('utf-8-sig')

    items = {}
    reached_cutoff = False
    for start in range(0, 500, 25):
        url = BASE + '/security-centre.html?' + ('limit=100&' if start == 0 else '') + 'start=' + str(start)
        page = fetch(url, 'joomla-index-' + str(start) + '.html')
        rows = advisories(page)
        if not rows:
            raise RuntimeError('No advisories parsed at offset ' + str(start))
        for row in rows:
            if args.since <= row['Fixed Date'] <= args.until:
                for cve in row['cves']:
                    items[cve] = row
        print('Joomla offset', start, 'dates', min(r['Fixed Date'] for r in rows), 'to', max(r['Fixed Date'] for r in rows), flush=True)
        if min(r['Fixed Date'] for r in rows) < args.since:
            reached_cutoff = True
            break
    if not reached_cutoff:
        raise RuntimeError('Pagination did not reach the requested cutoff')

    nvd = {}
    start = 0
    while True:
        url = 'https://services.nvd.nist.gov/rest/json/cves/2.0?keywordSearch=joomla&resultsPerPage=2000'
        if start:
            url += '&startIndex=' + str(start)
        response = json.loads(fetch(url, 'nvd-joomla' + ('-' + str(start) if start else '') + '.json'))
        for wrapper in response['vulnerabilities']:
            cve = wrapper['cve']
            if args.since <= cve['published'][:10] <= args.until or cve['id'] in items:
                nvd[cve['id']] = cve
        start += len(response['vulnerabilities'])
        if start >= response['totalResults']:
            break
        if not response['vulnerabilities']:
            raise RuntimeError('NVD pagination stalled')
        if not args.offline:
            time.sleep(7)

    keyword_total = response['totalResults']
    # NVD keywordSearch examines descriptions, not vendor fields. Many Joomla CNA
    # descriptions omit the word Joomla, so keyword-only results are incomplete.
    cpe_url = 'https://services.nvd.nist.gov/rest/json/cves/2.0?virtualMatchString=cpe:2.3:a:joomla:*&resultsPerPage=2000'
    response = json.loads(fetch(cpe_url, 'nvd-joomla-cpe.json'))
    if len(response['vulnerabilities']) != response['totalResults']:
        raise RuntimeError('CPE query needs pagination; refusing a partial report')
    for wrapper in response['vulnerabilities']:
        cve = wrapper['cve']
        if args.since <= cve['published'][:10] <= args.until or cve['id'] in items:
            nvd[cve['id']] = cve
    missing = sorted(set(items) - set(nvd))
    for offset in range(0, len(missing), 100):
        batch = missing[offset:offset + 100]
        # Supported NVD API batch lookup also catches unanalysed records without CPEs.
        url = 'https://services.nvd.nist.gov/rest/json/cves/2.0?cveIds=' + ','.join(batch)
        response = json.loads(fetch(url, 'nvd-official-' + hashlib.sha256(','.join(batch).encode()).hexdigest()[:12] + '.json'))
        returned = {v['cve']['id'] for v in response['vulnerabilities']}
        if returned != set(batch):
            raise RuntimeError('NVD ID lookup incomplete or ignored query: ' + str(set(batch) - returned))
        for wrapper in response['vulnerabilities']:
            nvd[wrapper['cve']['id']] = wrapper['cve']
        if not args.offline:
            time.sleep(7)

    # Audit the dependencies shipped with the official 3.10.12 archive, not the
    # unknown installed extensions or an assumed Composer update on the live site.
    lock_url = 'https://raw.githubusercontent.com/joomla/joomla-cms/3.10.12/composer.lock'
    lock = json.loads(fetch(lock_url, 'joomla-3.10.12-composer.lock'))
    queries = []
    for package in lock['packages']:
        if package['version'].startswith('dev-'):
            queries.append({'commit': package['source']['reference']})
        else:
            queries.append({'package': {'name': package['name'], 'ecosystem': 'Packagist'},
                            'version': package['version'].lstrip('v')})
    osv = json.loads(fetch('https://api.osv.dev/v1/querybatch', 'osv-composer-batch.json', {'queries': queries}))
    if len(osv['results']) != len(queries):
        raise RuntimeError('OSV batch result count mismatch')
    dependencies = []
    dep_cves = {}
    for package, result in zip(lock['packages'], osv['results']):
        findings = []
        for match in result.get('vulns', []):
            identifier = match['id']
            finding = json.loads(fetch('https://api.osv.dev/v1/vulns/' + identifier, 'osv-' + identifier + '.json'))
            if args.since <= finding['published'][:10] <= args.until:
                findings.append(finding)
                for alias in finding.get('aliases', []):
                    if alias.startswith('CVE-'):
                        dep_cves.setdefault(alias, []).append(package['name'])
        dependencies.append(dict(package=package['name'], version=package['version'],
                                 source=package.get('source'), findings=findings))
    missing_dependencies = sorted(set(dep_cves) - set(nvd))
    if missing_dependencies:
        url = 'https://services.nvd.nist.gov/rest/json/cves/2.0?cveIds=' + ','.join(missing_dependencies)
        response = json.loads(fetch(url, 'nvd-dependencies.json'))
        if {v['cve']['id'] for v in response['vulnerabilities']} != set(missing_dependencies):
            raise RuntimeError('Dependency NVD lookup incomplete; use a fresh cache')
        for wrapper in response['vulnerabilities']:
            nvd[wrapper['cve']['id']] = wrapper['cve']

    # Retain the stock-version baseline and query packaged versions separately.
    packaged_dependencies = []
    installed_path = ROOT / 'files/libraries/vendor/composer/installed.json'
    if installed_path.exists():
        installed = json.loads(installed_path.read_text(encoding='utf-8'))['packages']
        queries = [({'commit': p['source']['reference']} if p['version'].startswith('dev-') else
                    {'package': {'name': p['name'], 'ecosystem': 'Packagist'}, 'version': p['version'].lstrip('v')})
                   for p in installed]
        payload = {'queries': queries}
        query_hash = hashlib.sha256(json.dumps(payload).encode()).hexdigest()[:16]
        current_osv = json.loads(fetch('https://api.osv.dev/v1/querybatch', 'osv-packaged-' + query_hash + '.json', payload))
        if len(current_osv['results']) != len(installed):
            raise RuntimeError('Packaged dependency OSV result count mismatch')
        for package, match in zip(installed, current_osv['results']):
            packaged_dependencies.append(dict(package=package['name'], version=package['version'],
                findings=[json.loads(fetch('https://api.osv.dev/v1/vulns/' + v['id'], 'osv-' + v['id'] + '.json'))
                          for v in match.get('vulns', [])]))

    review_path = ROOT / 'docs/cve-review.json'
    reviews = json.loads(review_path.read_text(encoding='utf-8')) if review_path.exists() else {}
    readme = (ROOT / 'README.md').read_text(encoding='utf-8')
    rows = []
    for identifier in sorted(set(nvd) | set(items)):
        cve = nvd.get(identifier, {})
        advisory = items.get(identifier)
        metrics = scores(cve)
        score = max((m['score'] for m in metrics), default=None)
        priority = 'UNKNOWN' if score is None else 'CRITICAL' if score >= 9 else 'HIGH' if score >= 7 else 'MEDIUM' if score >= 4 else 'LOW'
        refs = [ref['url'] for ref in cve.get('references', [])]
        descriptions = [d['value'] for d in cve.get('descriptions', []) if d['lang'] == 'en']
        review = reviews.get(identifier)
        stale = None
        if review:
            stale = review.get('nvd_modified_at_review') != cve.get('lastModified')
            for path, digest in review.get('evidence_sha256', {}).items():
                local = ROOT / path
                stale = stale or not local.exists() or hashlib.sha256(local.read_bytes()).hexdigest() != digest
        rows.append(dict(cve=identifier, published=cve.get('published'), modified=cve.get('lastModified'),
                         priority=priority, max_cvss=score, metrics=metrics, advisory=advisory,
                         dependencies=dep_cves.get(identifier, []), review=review, review_stale=stale,
                         affects_hint=affected_hint(advisory) if advisory else 'nvd-only-needs-triage',
                         readme_claim=identifier in readme or bool(advisory and advisory['advisory_id'] in readme),
                         description=' '.join(descriptions), references=refs,
                         nvd_configurations=cve.get('configurations', [])))
    result = dict(generated_utc=dt.datetime.now(dt.timezone.utc).isoformat(), since=args.since, until=args.until,
                  sources=sources, dependencies=dependencies, packaged_dependencies=packaged_dependencies,
                  nvd_keyword_total=keyword_total, official_cve_count=len(items),
                  limitations=['Keyword search is not an inventory of installed third-party extensions or all bundled dependencies.',
                               'README mentions are claims only; code review and targeted tests are required.',
                               'Outside an advisory range does not prove an EOL branch unaffected; confirm code paths.',
                               'Maximum available CVSS used for triage; sources and versions remain separate.'], records=rows)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    reviewed = [r for r in rows if r['advisory'] or r['dependencies']]
    report = ['# Post-EOL CVE review matrix', '',
              'Generated from `cve-inventory.json` and the manual `cve-review.json`. Window: ' + args.since + ' through ' + args.until + '.', '',
              'Maximum numeric CVSS is a conservative triage priority, not a site-specific risk score. Separate source/version vectors are retained in JSON.', '',
              'Status counts: ' + ', '.join(f'{k}: {v}' for k, v in sorted(Counter(
                  'STALE/REVIEW REQUIRED' if r['review_stale'] else r['review']['status'] if r['review'] else 'UNREVIEWED'
                  for r in reviewed).items())) + '.', '',
              '| CVE / source | Priority | Review | Code evidence / conclusion |',
              '| --- | --- | --- | --- |']
    for row in sorted(reviewed, key=lambda r: (-(r['max_cvss'] or 0), r['cve'])):
        review = row['review'] or {}
        url = row['advisory']['url'] if row['advisory'] else 'https://nvd.nist.gov/vuln/detail/' + row['cve']
        status = 'STALE/REVIEW REQUIRED' if row['review_stale'] else review.get('status', 'UNREVIEWED')
        evidence = []
        for path in review.get('evidence', []):
            if path.startswith('absent:'):
                evidence.append('Absent: `' + path[7:] + '`')
            else:
                target = '../' + path if path.startswith('files/') else 'https://github.com/joomla/joomla-cms/blob/3.10.12/' + path
                evidence.append('[' + path + '](' + target + ')')
        detail = review.get('reason', 'Requires code review') + '<br>' + '<br>'.join(evidence)
        report.append('| [' + row['cve'] + '](' + url + ') | ' + row['priority'] + ' ' + str(row['max_cvss']) +
                      ' | ' + status + ' | ' + detail.replace('|', '\\|') + ' |')
    report.extend(['', 'Other NVD candidates: ' + str(len(rows) - len(reviewed)) +
                   '. Applicability requires the installed extension inventory; they are not silently counted as fixed or unaffected.', ''])
    args.output.with_name('cve-matrix.md').write_text('\n'.join(report), encoding='utf-8')
    print('Saved', len(rows), 'post-EOL candidates;', len(items), 'official CVEs;', len(nvd), 'NVD matches', flush=True)
    for row in sorted(rows, key=lambda r: -(r['max_cvss'] or 0)):
        if row['advisory'] and row['affects_hint'] != 'outside-advisory-range-needs-confirmation':
            print(row['cve'], row['priority'], row['max_cvss'], row['affects_hint'],
                  'README-CLAIM' if row['readme_claim'] else 'NO-CLAIM', row['advisory']['title'], flush=True)


if __name__ == '__main__':
    main()
