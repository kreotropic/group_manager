<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Maintainer tooling

Nothing in this directory ships: `build` is listed in `.nextcloudignore`, so
`krankerl package` leaves it out of the App Store tarball. It exists for
working on Group Manager, not for running it.

`l10n.py` is the routine one and its commands live in the main README's
*Translations build* section, so they stay in one place. The rest of this
file covers releasing, which has nowhere else to live.

## Releasing to the App Store

Signing needs the app certificate. `~/.nextcloud/certificates/` holds
`group_manager.key` and `group_manager.csr`; `group_manager.crt` arrives when
the certificate request PR against
[nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
is merged, as a file committed into that repository. That directory is the
path `krankerl sign` looks in, which is why the key lives there rather than
anywhere tidier. **The key is not backed up anywhere** — it signs every
future release, and losing it means requesting a new certificate and
disclosing the loss.

### 1. Pre-flight

`krankerl package` archives the **committed** tree, not the working copy —
neither uncommitted edits to tracked files nor untracked files reach the
tarball. That is a useful property (a release always corresponds to a commit)
with one sharp edge: forget to commit and it silently packages the previous
version. So commit first, then check `git status` is clean.

Bump `version` in `appinfo/info.xml` (and `package.json`/`package-lock.json`,
kept in step), and give the new version its own `CHANGELOG.md` section — the
App Store reads the section matching the release version, so a heading that
does not match ships an empty changelog.

```bash
vendor/bin/phpunit -c phpunit.xml
find lib appinfo -name '*.php' -print0 | xargs -0 -n1 php -l
python3 build/l10n.py --check
reuse lint
npm ci && npm run build && git diff --exit-code -- js/
```

### 2. Package

```bash
krankerl package
tar tzf build/artifacts/group_manager.tar.gz | wc -l
tar xzf build/artifacts/group_manager.tar.gz -O group_manager/appinfo/info.xml | grep '<version>'
```

Check the version inside the tarball rather than trusting the working copy —
it is the one place the "packages HEAD" behaviour shows up as a wrong answer.

### 3. Sign the packaged content, not the working tree

**The trap worth knowing before you hit it:** running `integrity:sign-app`
against `apps/group_manager` hashes the whole development directory —
`src/`, `tests/`, `node_modules/`, `vendor/` — none of which ship. The
signature would then list files the installed app does not have, and
`integrity:check-app` fails on every one. Sign an extracted copy of the
tarball, so the hashes cover exactly the shipped file set.

```bash
docker cp build/artifacts/group_manager.tar.gz nextcloud-app:/tmp/
docker cp ~/.nextcloud/certificates/group_manager.key nextcloud-app:/tmp/
docker cp ~/.nextcloud/certificates/group_manager.crt nextcloud-app:/tmp/
docker exec nextcloud-app sh -c '
    cd /tmp && rm -rf signroot && mkdir signroot &&
    tar xzf group_manager.tar.gz -C signroot &&
    chown -R www-data:www-data signroot group_manager.key group_manager.crt'

docker exec -u www-data nextcloud-app php /var/www/html/occ integrity:sign-app \
    --privateKey=/tmp/group_manager.key \
    --certificate=/tmp/group_manager.crt \
    --path=/tmp/signroot/group_manager

docker exec nextcloud-app sh -c 'cd /tmp/signroot && tar czf /tmp/group_manager-signed.tar.gz group_manager'
docker cp nextcloud-app:/tmp/group_manager-signed.tar.gz build/artifacts/
docker exec nextcloud-app sh -c 'rm -rf /tmp/signroot /tmp/group_manager.key /tmp/group_manager.crt /tmp/group_manager.tar.gz'
```

The `chown` matters: `occ` has to run as `www-data` (it refuses to run as
root), and `docker cp` leaves the key owned by root and mode 600. The final
`rm` matters for the same reason the key lives outside every repository.

### 4. Verify

`appinfo/signature.json` is now inside `group_manager-signed.tar.gz`. Prove
it before publishing, by installing that tarball on a disposable instance and
asking Nextcloud itself:

```bash
docker exec -u www-data <container> php occ integrity:check-app group_manager
```

Empty output means the signature covers exactly what is installed. Anything
else lists the offending files and means step 3 signed the wrong tree.

### 5. Publish

First release only, two one-time steps before the upload:
1. **Register the app** at apps.nextcloud.com (paste the `.crt` contents,
   plus a proof-of-possession signature over the literal app id):
   ```bash
   echo -n "group_manager" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/group_manager.key | openssl base64
   ```
2. **Upload the release** — the form also asks for a signature, this time
   over the tarball's bytes, not the app id:
   ```bash
   openssl dgst -sha512 -sign ~/.nextcloud/certificates/group_manager.key build/artifacts/group_manager-signed.tar.gz | openssl base64
   ```
   This signature is only valid for the exact bytes uploaded — re-generating
   the tarball afterwards invalidates it.

Upload `group_manager-signed.tar.gz` itself at
<https://apps.nextcloud.com> (account signs in with GitHub). Screenshots come
from the `<screenshot>` URLs in `info.xml`, served from this repository's
`raw.githubusercontent.com`, so they must already be pushed.

For every release *after* the first, only step 2 (upload) repeats — the
account/certificate registration is one-time.
