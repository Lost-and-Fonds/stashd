#!/usr/bin/env python3
import json
import os
import re
import subprocess
import urllib.error
import urllib.request

OWNER = 'Lost-and-Fonds'
REPOSITORY = 'stashd'
IMAGE = 'ghcr.io/lost-and-fonds/stashd'
API = f'https://api.github.com/repos/{OWNER}/{REPOSITORY}'
TOKEN = os.environ['GITHUB_TOKEN']


def request(method, url, body=None):
    data = None if body is None else json.dumps(body).encode()
    headers = {
        'Accept': 'application/vnd.github+json',
        'Authorization': f'Bearer {TOKEN}',
        'X-GitHub-Api-Version': '2022-11-28',
    }
    if data is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req) as response:
            return None if response.status == 204 else json.load(response)
    except urllib.error.HTTPError as error:
        raise RuntimeError(f'GitHub API {method} {url} returned HTTP {error.code}: {error.read().decode()}') from error


def run(*args):
    subprocess.run(args, check=True)


def versioned_tags():
    refs = subprocess.check_output(['git', 'tag', '--list', 'v1.0.*'], text=True).splitlines()
    mapping = {}
    for old in refs:
        match = re.fullmatch(r'v1\.0\.(\d+)(-.+)?', old)
        if match is None:
            continue
        new = f'v0.1.{match.group(1)}{match.group(2) or ""}'
        commit = subprocess.check_output(['git', 'rev-parse', f'{old}^{{commit}}'], text=True).strip()
        existing = subprocess.run(['git', 'rev-parse', f'{new}^{{commit}}'], text=True, capture_output=True)
        if existing.returncode == 0 and existing.stdout.strip() != commit:
            raise RuntimeError(f'{new} already points at a different commit')
        if existing.returncode != 0:
            run('git', 'tag', new, commit)
        mapping[old] = (new, commit)

    stable = sorted(int(re.fullmatch(r'v1\.0\.(\d+)', tag).group(1)) for tag in mapping if re.fullmatch(r'v1\.0\.(\d+)', tag))
    if stable != list(range(stable[-1] + 1)):
        raise RuntimeError('Stable Core tags are not a continuous v1.0.0..v1.0.N series')
    print(f'Mapped {len(mapping)} tags; stable series v1.0.0..v1.0.{stable[-1]}; next Core version v0.1.{stable[-1] + 1}')
    for old, (new, commit) in mapping.items():
        print(f'{old} -> {new} {commit}')
    return mapping


def retag_git(mapping):
    targets = [new for new, _ in mapping.values()]
    if targets:
        run('git', 'push', 'origin', *[f'refs/tags/{tag}' for tag in targets])


def update_releases(mapping):
    releases = request('GET', f'{API}/releases?per_page=100')
    for release in releases:
        old = release['tag_name']
        if old not in mapping:
            continue
        new = mapping[old][0]
        payload = {'tag_name': new}
        if release.get('name'):
            payload['name'] = release['name'].replace(old, new).replace('Stashd 1.0.', 'Stashd 0.1.')
        if release.get('body'):
            payload['body'] = re.sub(r'\bv1\.0\.(?=\d)', 'v0.1.', release['body']).replace('Stashd 1.0.', 'Stashd 0.1.')
        request('PATCH', f'{API}/releases/{release["id"]}', payload)
        print(f'Release {old} -> {new}')


def registry_tags():
    token_url = f'https://ghcr.io/token?scope=repository:lost-and-fonds/stashd:pull'
    with urllib.request.urlopen(token_url) as response:
        registry_token = json.load(response)['token']
    req = urllib.request.Request(
        'https://ghcr.io/v2/lost-and-fonds/stashd/tags/list?n=1000',
        headers={'Authorization': f'Bearer {registry_token}'},
    )
    with urllib.request.urlopen(req) as response:
        return set(json.load(response)['tags'])


def package_versions():
    url = f'https://api.github.com/orgs/{OWNER}/packages/container/{REPOSITORY}/versions?per_page=100'
    versions = []
    while url:
        req = urllib.request.Request(url, headers={
            'Accept': 'application/vnd.github+json',
            'Authorization': f'Bearer {TOKEN}',
            'X-GitHub-Api-Version': '2022-11-28',
        })
        with urllib.request.urlopen(req) as response:
            versions.extend(json.load(response))
            link = response.headers.get('Link', '')
        url = next((part[part.find('<') + 1:part.find('>')] for part in link.split(',') if 'rel="next"' in part), '')
    return versions


def retag_images():
    tags = registry_tags()
    images = sorted((int(match.group(1)), tag) for tag in tags if (match := re.fullmatch(r'v1\.0\.(\d+)', tag)))
    if not images:
        raise RuntimeError('No existing v1.0.N images found in GHCR')

    for number, old in images:
        new = f'v0.1.{number}'
        run('docker', 'buildx', 'imagetools', 'create', '--annotation', f'index:org.opencontainers.image.version={new}', '--tag', f'{IMAGE}:{new}', f'{IMAGE}:{old}')
        print(f'Image {old} -> {new}')

    newest = images[-1][0]
    newest_tag = f'v0.1.{newest}'
    run('docker', 'buildx', 'imagetools', 'create', '--tag', f'{IMAGE}:v0.1', '--tag', f'{IMAGE}:v0', '--tag', f'{IMAGE}:latest', f'{IMAGE}:{newest_tag}')
    print(f'Updated v0.1, v0, and latest to {newest_tag}')

    old_refs = re.compile(r'^v1\.0\.\d+(?:-.+)?$')
    for version in package_versions():
        old_tags = [tag for tag in version['metadata']['container']['tags'] if old_refs.fullmatch(tag) or tag in {'v1', 'v1.0'}]
        if old_tags:
            request('DELETE', f'https://api.github.com/orgs/{OWNER}/packages/container/{REPOSITORY}/versions/{version["id"]}')
            print(f"Deleted old image version {version['id']} tags={','.join(old_tags)}")

    remaining = registry_tags()
    stale = sorted(tag for tag in remaining if old_refs.fullmatch(tag) or tag in {'v1', 'v1.0'})
    if stale:
        raise RuntimeError(f'Old GHCR tags remain: {stale}')
    missing = [f'v0.1.{number}' for number, _ in images if f'v0.1.{number}' not in remaining]
    if missing or any(alias not in remaining for alias in ('v0.1', 'v0', 'latest')):
        raise RuntimeError(f'GHCR tag verification failed: missing={missing}')
    print(f'Verified {len(images)} version tags and new aliases; removed old aliases and version tags')


def delete_old_git_tags(mapping):
    old = list(mapping)
    if old:
        run('git', 'push', 'origin', *[f':refs/tags/{tag}' for tag in old])

    remaining = subprocess.check_output(['git', 'ls-remote', '--tags', 'origin'], text=True)
    remote = {line.split('refs/tags/', 1)[1] for line in remaining.splitlines() if 'refs/tags/' in line and not line.endswith('^{}')}
    stale = sorted(tag for tag in old if tag in remote)
    if stale:
        raise RuntimeError(f'Old Git tags remain on origin: {stale}')
    print(f'Removed {len(old)} old Git tags from origin')


if __name__ == '__main__':
    mapping = versioned_tags()
    retag_git(mapping)
    update_releases(mapping)
    retag_images()
    delete_old_git_tags(mapping)
