<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Maintainer tooling

Nothing in this directory ships: `build` is listed in `.nextcloudignore`, so
`krankerl package` leaves it out of the App Store tarball. It exists for
working on Group Manager, not for running it.

| File | What it is |
|---|---|
| `l10n.py` | Translation build; its commands live in the main README's *Translations build* section, so they stay in one place. |
| `nc-instance.sh` | `up` / `test` / `down` / `ls` for one disposable Nextcloud instance per supported version. |
| `docker-compose.nc.yml` | The instance itself (Nextcloud + an LDAP test directory), parameterised by `NC_VERSION` / `NC_PORT`. Not meant to be run by hand. |
| `api-check.py` | Drives the running app over HTTP; what `nc-instance.sh test` runs after the unit suite. |

The rest of this file covers checking a Nextcloud version and releasing,
which have nowhere else to live.

## Checking the app against a Nextcloud version

Declaring a `<nextcloud min-version max-version>` range without running
anything on its ends is a guess, so each version gets its own throwaway
instance. Each has its own project name, containers, port and volume, so
they run side by side and cannot disturb the development instance on 8080.

```bash
build/nc-instance.sh up 33      # first run pulls images and installs: a couple of minutes
build/nc-instance.sh test 33    # unit suite, then the API check
build/nc-instance.sh down 33    # -v: without it the next `up` resumes the old instance
build/nc-instance.sh ls
```

| NC | Port |
|---|---|
| 31 | 8110 |
| 32 | 8111 |
| 33 | 8112 |
| 34 | 8113 |
| 35 | 8114 |

Admin login is `ncadmin` / `groupmgr-ncNN-verify` (NN = the version). To try
a version that is not in the table, add it to `VERSIONS` / `PORTS` in the
script.

### What `up` does

- **Mounts this app only**, and installs Team Folders (`groupfolders`) from
  the App Store, so each version gets the release built for it (19.x for
  NC 31 … 23.x for NC 35). One copy in a shared `apps/` directory could not
  satisfy every version.
- **Points `user_ldap` at a test directory.** The `ldap` service is a public
  image pre-loaded with the Planet Express users and groups (`ship_crew`,
  `admin_staff`, …), so the LDAP paths (read-only groups, the DN, expanding
  an LDAP group into a local one) can be exercised without a directory of
  your own. The API check relies on that data (`ship_crew` has three
  members).
- **Widens the app's `info.xml` inside the container** when asked for a
  version outside the range it declares. Nextcloud refuses to enable an app
  outside its declared range, and `occ app:enable --force` waives only
  `max-version`, never `min-version`. `up` writes a copy of `appinfo/info.xml`
  with the `<nextcloud>` range set to exactly that version (into
  `build/.generated/`, git-ignored) and bind-mounts it read-only over the real
  one. The file in the repo is untouched, and `up` says so when it does this.
  That is what let this matrix answer "would this work on NC 35?" before it
  was declared.
- **Recreates the app container when it already existed**, because a
  single-file bind mount follows the file's inode: anything that replaces
  `info.xml` on the host (a `git` checkout, merge or rebase, an editor's
  atomic save) silently detaches the widened copy from a running container.
- **Runs `occ upgrade` when needed.** Every release bumps `<version>`, which
  leaves an existing instance in "requires upgrade"; occ then refuses
  everything but `upgrade`, `app:enable` included.

### What `test` covers

`api-check.py` drives the running app over HTTP: local and LDAP groups side
by side, LDAP groups refusing changes, adding/removing/expanding/pasting
members, group folders (create, duplicate-name rejection, assign to local
and LDAP groups, permissions, quota, the ACL flag), access control, and the
admin page and its bundle. Every object it creates carries a per-run suffix
and is removed at the end, so it can be re-run. Checks that cannot run
because Team Folders is not enabled are reported as `SKIP` and do not fail
the run; a failing group detail is a failure of its own, not a skip: this
is exactly the check that caught NC 31's original 500 (see the `Fixed` entry
in `CHANGELOG.md`: `FolderAssignmentService` used to assume the `FolderManager`
API that only groupfolders 20+ provides, so opening any group's detail view
crashed once Team Folders 19.x was installed).

`test` needs `vendor/` (PHPUnit); the host has no PHP, so it installs it
with the official composer image on first use.

### Last verified

Run on 2026-10-03 against 0.4.0 (group admins included):

| NC | PHP | Team Folders | unit | API check | admin race |
|---|---|---|---|---|---|
| 31.0.14 | 8.3.30 | 19.1.20 | 111/111 | 83/83 | PASS |
| 32.0.15 | 8.3.35 | 20.1.18 | 111/111 | 83/83 | PASS |
| 33.0.9 | 8.4.26 | 21.0.15 | 111/111 | 83/83 | PASS |
| 34.0.4 | 8.5.11 | 22.0.6 | 111/111 | 83/83 | PASS |
| 35.0.0 | 8.5.10 | 23.0.1 | 111/111 | 83/83 | PASS |

Every instance ran PHP 8.3 or newer (that is all the images ship), so
nothing here says anything about PHP 8.1, which `info.xml` still declares as
the minimum. Re-run the matrix whenever the declared range changes or a new
Nextcloud is released. A real-browser check of the admin page's Members and
Folders tabs exists only as a session scratchpad script, not in this repo;
worth bringing in properly if this matrix is kept up going forward.

## Releasing to the App Store

Signing needs the app certificate. `~/.nextcloud/certificates/` holds
`group_manager.key` and `group_manager.csr`; `group_manager.crt` arrives when
the certificate request PR against
[nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
is merged, as a file committed into that repository. That directory is the
path `krankerl sign` looks in, which is why the key lives there rather than
anywhere tidier. **The key is not backed up anywhere**: it signs every
future release, and losing it means requesting a new certificate and
disclosing the loss.

### 1. Pre-flight

`krankerl package` archives the **committed** tree, not the working copy:
neither uncommitted edits to tracked files nor untracked files reach the
tarball. That is a useful property (a release always corresponds to a commit)
with one sharp edge: forget to commit and it silently packages the previous
version. So commit first, then check `git status` is clean.

Bump `version` in `appinfo/info.xml` (and `package.json`/`package-lock.json`,
kept in step), and give the new version its own `CHANGELOG.md` section: the
App Store reads the section matching the release version, so a heading that
does not match ships an empty changelog.

If the declared Nextcloud range changed, or a new Nextcloud or Team Folders
release came out since the last release, re-run `build/nc-instance.sh test <version>`
for each version first (see above): the unit suite mocks Team Folders classes
and cannot catch a change in its actual API.

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

Check the version inside the tarball rather than trusting the working copy:
it is the one place the "packages HEAD" behaviour shows up as a wrong answer.

### 3. Sign the packaged content, not the working tree

**The trap worth knowing before you hit it:** running `integrity:sign-app`
against `apps/group_manager` hashes the whole development directory
(`src/`, `tests/`, `node_modules/`, `vendor/`), none of which ship. The
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
2. **Upload the release**: the form also asks for a signature, this time
   over the tarball's bytes, not the app id:
   ```bash
   openssl dgst -sha512 -sign ~/.nextcloud/certificates/group_manager.key build/artifacts/group_manager-signed.tar.gz | openssl base64
   ```
   This signature is only valid for the exact bytes uploaded; re-generating
   the tarball afterwards invalidates it.

Upload `group_manager-signed.tar.gz` itself at
<https://apps.nextcloud.com> (account signs in with GitHub). Screenshots come
from the `<screenshot>` URLs in `info.xml`, served from this repository's
`raw.githubusercontent.com`, so they must already be pushed.

For every release *after* the first, only step 2 (upload) repeats; the
account/certificate registration is one-time.
