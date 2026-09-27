#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Drives Group Manager's HTTP API against one build/nc-instance.sh instance.

The unit suite cannot reach the parts of the app that depend on other apps --
its own comment says the Team Folders classes are not autoloadable outside a
full Nextcloud bootstrap -- so this is where the Team Folders paths (create,
assign, permissions, quota, ACL flag) and the LDAP paths (read-only groups,
DN, expanding an LDAP group into a local one) get exercised against the real
thing.

    build/api-check.py <nc-version> <port>

Needs the instance from `build/nc-instance.sh up <version>`: admin login
`ncadmin` / `groupmgr-ncNN-verify`, Team Folders installed, and user_ldap
pointed at the Planet Express test directory. Every object it creates carries a
per-run suffix and is removed at the end, so it can be re-run. Exit status is
non-zero if any check failed; checks that could not run (no Team Folders
release for that Nextcloud yet) are reported as SKIP and do not fail the run.
"""
import base64
import json
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid


def main(version: str, port: str) -> int:
    base = f"http://localhost:{port}"
    container = f"groupmgr-nc{version}-app"
    admin = ("ncadmin", f"groupmgr-nc{version}-verify")
    run = uuid.uuid4().hex[:6]
    grp = f"gmchk_{run}"
    grp2 = f"gmchk2_{run}"
    ua, ub, unon = f"gmu_{run}_a", f"gmu_{run}_b", f"gmu_{run}_plain"
    mount = f"gmchk_{run}_folder"
    api = f"{base}/apps/group_manager/api"

    results = []

    def check(name, ok, detail=""):
        results.append(("PASS" if ok else "FAIL", name))
        print(f"  {'PASS' if ok else 'FAIL'} {name:<52} {'' if ok else detail}")

    def skip(name, why):
        results.append(("SKIP", name))
        print(f"  SKIP {name:<52} {why}")

    def call(method, url, body=None, auth=admin, form=False):
        data = None
        headers = {"OCS-APIRequest": "true", "Accept": "application/json"}
        if body is not None:
            if form:
                data = urllib.parse.urlencode(body).encode()
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            else:
                data = json.dumps(body).encode()
                headers["Content-Type"] = "application/json"
        if auth:
            headers["Authorization"] = "Basic " + base64.b64encode(f"{auth[0]}:{auth[1]}".encode()).decode()
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                status, raw = r.status, r.read()
        except urllib.error.HTTPError as e:
            status, raw = e.code, e.read()
        try:
            return status, json.loads(raw)
        except ValueError:
            # An HTML error page: keep it dict-shaped so checks can still .get()
            # on it and report, instead of one 500 aborting the whole run.
            return status, {"_raw": raw.decode(errors="replace")[:120]}

    def occ(*args):
        return subprocess.run(
            ["docker", "exec", "-u", "www-data", container, "php", "occ", *args],
            capture_output=True, text=True,
        )

    def uids(members):
        return sorted(m["uid"] for m in members or [])

    print(f"=== NC {version} API check ({base}, run {run}) ===")
    created_users = []
    created_folders = []
    try:
        # ---- groups: local and LDAP side by side ---------------------------
        st, d = call("GET", f"{api}/groups")
        groups = {g["id"]: g for g in d.get("groups", [])} if st == 200 else {}
        check("list: 200 with groups", st == 200 and bool(groups), f"{st} {d}")
        check("list: local group 'admin' is local", groups.get("admin", {}).get("isLocal") is True)
        crew = groups.get("ship_crew", {})
        check("list: LDAP group 'ship_crew' present, not local",
              crew.get("isLocal") is False and crew.get("backend") == "ldap", str(crew))
        check("list: LDAP group 'admin_staff' present", "admin_staff" in groups)

        st, d = call("GET", f"{api}/groups/ship_crew")
        check("ldap group: read-only flags and resolved DN",
              st == 200 and d.get("canRename") is False and d.get("canDelete") is False
              and d.get("canAddUser") is False and d.get("canRemoveUser") is False
              and str(d.get("dn", "")).endswith("dc=planetexpress,dc=com"), f"{st} {d}")
        check("ldap group: member count from the directory", d.get("memberCount") == 3, str(d.get("memberCount")))

        st, d = call("GET", f"{api}/groups/ship_crew/members")
        check("ldap group: members listed",
              st == 200 and uids(d.get("members")) == ["bender", "fry", "leela"], f"{st} {d}")

        # ---- LDAP groups refuse modification --------------------------------
        for name, method, path, body in [
            ("add member", "POST", "/groups/ship_crew/members", {"uid": "fry"}),
            ("remove member", "DELETE", "/groups/ship_crew/members/fry", None),
            ("rename", "PUT", "/groups/ship_crew", {"displayName": "x"}),
            ("delete", "DELETE", "/groups/ship_crew", None),
        ]:
            st, d = call(method, api + path, body)
            check(f"ldap group: {name} refused (403 GROUP_NOT_LOCAL)",
                  st == 403 and isinstance(d, dict) and d.get("code") == "GROUP_NOT_LOCAL", f"{st} {d}")

        # ---- local group lifecycle --------------------------------------------
        st, d = call("POST", f"{api}/groups", {"gid": grp, "displayName": f"Check {run}"})
        check("local group: create", st == 200 and d.get("isLocal") is True and d.get("canRename") is True
              and d.get("canDelete") is True, f"{st} {d}")
        st, d = call("POST", f"{api}/groups", {"gid": grp})
        check("local group: duplicate rejected (409)", st == 409 and d.get("code") == "GROUP_ALREADY_EXISTS", f"{st} {d}")
        st, d = call("DELETE", f"{api}/groups/admin")
        check("local group: 'admin' protected from deletion", st == 403 and d.get("code") == "ADMIN_GROUP_PROTECTED", f"{st} {d}")
        st, d = call("GET", f"{api}/groups/no_such_group_{run}")
        check("local group: unknown id is 404", st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")

        # ---- GM-09: a GID with '/' would be unreachable by this app's own routes ----
        st, d = call("POST", f"{api}/groups", {"gid": f"gmchk_{run}/slash"})
        check("local group: gid with slash rejected (400)",
              st == 400 and d.get("code") == "INVALID_GROUP_ID", f"{st} {d}")

        # ---- members ---------------------------------------------------------
        for u in (ua, ub, unon):
            st, _ = call("POST", f"{base}/ocs/v1.php/cloud/users",
                         {"userid": u, "password": f"Ch3ck-{uuid.uuid4().hex}"}, form=True)
            if st == 200:
                created_users.append(u)
        check("setup: local users created", len(created_users) == 3, str(created_users))

        st, d = call("POST", f"{api}/groups/{grp}/members", {"uid": ua})
        check("member: add local user", st == 200 and d.get("uid") == ua, f"{st} {d}")
        st, d = call("GET", f"{api}/groups/{grp}/members")
        check("member: listed after add", st == 200 and uids(d.get("members")) == [ua] and d.get("total") == 1
              and d.get("hasMore") is False, f"{st} {d}")
        st, d = call("POST", f"{api}/groups/{grp}/members", {"uid": f"no_such_user_{run}"})
        check("member: unknown user is 404", st == 404 and d.get("code") == "USER_NOT_FOUND", f"{st} {d}")

        # ---- GM-10: malformed pagination/tokens are a stable 400, never a 500 ----
        # pageSize, not limit: Nextcloud's own AppFramework Dispatcher
        # special-cases any controller parameter literally named "limit",
        # silently clamping it to [1, 500] with an uncaught
        # ParameterOutOfRangeException (a raw HTML 500) outside that range --
        # confirmed on NC34/35 (not NC31-33, where that special case doesn't
        # exist yet) while building this check. GroupController::members()
        # takes $pageSize specifically so this app's own bounds and error
        # shape are what's actually observed, on every supported version.
        for name, size in (("negative", -1), ("zero", 0), ("excessive", 10000)):
            st, d = call("GET", f"{api}/groups/{grp}/members?pageSize={size}")
            check(f"members GM-10: {name} pageSize is 400, not 500",
                  st == 400 and d.get("code") == "INVALID_PAGINATION", f"{st} {d}")
        st, d = call("GET", f"{api}/groups/{grp}/members?offset=-1")
        check("members GM-10: negative offset is 400, not 500",
              st == 400 and d.get("code") == "INVALID_PAGINATION", f"{st} {d}")
        st, d = call("GET", f"{api}/groups/{grp}/members")
        check("members GM-10: an omitted pageSize is still bounded (<=200 back)",
              st == 200 and len(d.get("members", [])) <= 200, f"{st} {d}")
        # Nextcloud's own dispatcher quirk worked around above (pageSize, not
        # limit) is specific to controller parameters literally still named
        # "limit" -- candidates()/searchAssignable() still are, and are not
        # rewritten to dodge it: out of this app's own GM-10 scope, which the
        # audit reproduced only against /members. An extreme out-of-range
        # limit there hits the same framework-level 500 on NC34/35. Left as
        # a documented, version-dependent gap, not exercised as a check here.

        for name, tokens in (
            ("nested array", [["wrong"]]),
            ("object", [{"a": 1}]),
            ("number", [123]),
        ):
            st, d = call("POST", f"{api}/groups/{grp}/resolve-list", {"tokens": tokens})
            check(f"resolve-list GM-10: {name} entry is 400, not 500",
                  st == 400 and d.get("code") == "INVALID_TOKENS", f"{st} {d}")

        st, d = call("GET", f"{api}/groups/{grp}/candidates?search=gmu_{run}")
        cand = [u["uid"] for u in d.get("users", [])] if st == 200 else []
        check("candidates: finds non-members, excludes members", ub in cand and ua not in cand, f"{st} {d}")

        # GM-06: candidate groups report their own memberCount (not an
        # overlap-derived newMemberCount, which needed enumerating every
        # candidate group's full membership per keystroke).
        st, d = call("GET", f"{api}/groups/{grp}/candidates?search=admin_staff")
        admin_staff = next((g for g in d.get("groups", []) if g.get("id") == "admin_staff"), None) if st == 200 else None
        check("candidates GM-06: group entries carry memberCount, not newMemberCount",
              admin_staff is not None and "memberCount" in admin_staff and "newMemberCount" not in admin_staff
              and isinstance(admin_staff["memberCount"], int), f"{st} {d}")

        st, d = call("GET", f"{api}/groups/{grp}/expand-group?sourceGid=ship_crew")
        check("expand: LDAP group into a local one",
              st == 200 and uids(d.get("members")) == ["bender", "fry", "leela"], f"{st} {d}")

        st, d = call("POST", f"{api}/groups/{grp}/resolve-list", {"tokens": [ub, "fry", ua, f"nobody_{run}"]})
        res = [(r["token"], r["matched"]) for r in d.get("results") or []] if st == 200 else []
        check("resolve-list: matches local + LDAP users, flags unknown",
              res == [(ub, True), ("fry", True), (ua, True), (f"nobody_{run}", False)], f"{st} {d}")
        # GM-07: alreadyMember is $grp's real membership, not a loaded page --
        # ua is a current member, ub and fry are not (yet), the unknown token
        # has no account to be a member with.
        already = {r["token"]: r["alreadyMember"] for r in d.get("results") or []} if st == 200 else {}
        check("resolve-list GM-07: alreadyMember matches real membership",
              already == {ub: False, "fry": False, ua: True, f"nobody_{run}": False}, f"{st} {d}")

        st, d = call("POST", f"{api}/groups/{grp}/members", {"uid": "fry"})
        check("member: add an LDAP user to a local group", st == 200 and d.get("uid") == "fry", f"{st} {d}")
        st, d = call("DELETE", f"{api}/groups/{grp}/members/fry")
        check("member: remove", st == 200, f"{st} {d}")
        st, d = call("GET", f"{api}/groups/{grp}/members")
        check("member: gone after remove", st == 200 and uids(d.get("members")) == [ua], f"{st} {d}")

        st, d = call("PUT", f"{api}/groups/{grp}", {"displayName": f"Renamed {run}"})
        check("local group: rename", st == 200 and d.get("displayName") == f"Renamed {run}", f"{st} {d}")

        # ---- access control ------------------------------------------------------
        plain_pw = None
        if unon in created_users:
            # the password was random and not kept; set a known one
            plain_pw = f"Pl4in-{uuid.uuid4().hex}"
            call("PUT", f"{base}/ocs/v1.php/cloud/users/{unon}", {"key": "password", "value": plain_pw}, form=True)
            st, d = call("GET", f"{api}/groups", auth=(unon, plain_pw))
            check("access: non-admin is refused", st == 403, f"{st} {d}")
        st, d = call("GET", f"{api}/groups", auth=None)
        check("access: anonymous is refused", st in (401, 403), f"{st}")

        # ---- admin page and its bundle ------------------------------------------
        req = urllib.request.Request(f"{base}/settings/admin/group_manager", headers={
            "Authorization": "Basic " + base64.b64encode(f"{admin[0]}:{admin[1]}".encode()).decode()})
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                page, page_st = r.read().decode(errors="replace"), r.status
        except urllib.error.HTTPError as e:
            page, page_st = "", e.code
        check("admin page: 200", page_st == 200, str(page_st))
        import re
        m = re.search(r'src="([^"]*group_manager/js/[^"]+\.js[^"]*)"', page)
        if m:
            try:
                with urllib.request.urlopen(base + m.group(1), timeout=60) as r:
                    check("admin page: JS bundle served", r.status == 200 and len(r.read()) > 1000)
            except urllib.error.HTTPError as e:
                check("admin page: JS bundle served", False, f"{e.code} {m.group(1)}")
        else:
            check("admin page: script tag present", False, "no group_manager/js script found")

        # ---- Team Folders --------------------------------------------------------
        st, d = call("GET", f"{api}/groups/{grp}")
        # Only a *successful* answer that says Team Folders is off is a reason to
        # skip. A failing group detail is a failure of its own: with Team Folders
        # installed this is the call that counts a group's folders.
        check("group detail loads", st == 200, f"{st} {d}")
        if st == 200 and d.get("foldersEnabled") is not True:
            for n in ("create folder", "permissions", "quota", "assign/unassign", "ACL flag"):
                skip(f"folders: {n}", "Team Folders not enabled on this instance")
        elif st != 200:
            for n in ("create folder", "permissions", "quota", "assign/unassign", "ACL flag"):
                skip(f"folders: {n}", "group detail did not load (see the failure above)")
        else:
            st, d = call("GET", f"{api}/groups/{grp}/folders")
            check("folders: none for a new group", st == 200 and d == {"folders": []}, f"{st} {d}")

            st, f = call("POST", f"{api}/groups/{grp}/folders/create", {"mountPoint": mount})
            fid = f.get("id") if isinstance(f, dict) else None
            if fid is not None:
                created_folders.append(fid)
            shape_ok = (st == 200 and fid is not None and f.get("mountPoint") == mount
                        and isinstance(f.get("acl"), bool) and isinstance(f.get("size"), int)
                        and isinstance(f.get("quota"), int)
                        and f.get("permissions") == {"write": True, "share": True, "delete": True})
            check("folders: create (new folder, default permissions)", shape_ok, f"{st} {f}")

            # Exercises mountPointExists(): on groupfolders pre-20 this is our
            # own query against its table (that method doesn't exist there),
            # on 20+ the same check groupfolders' own FolderManager makes —
            # either way the app must still refuse a duplicate name.
            st, d = call("POST", f"{api}/groups/{grp}/folders/create", {"mountPoint": mount})
            check("folders: duplicate mount point rejected (409)",
                  st == 409 and d.get("code") == "FOLDER_ALREADY_EXISTS", f"{st} {d}")

            if fid is not None:
                st, d = call("GET", f"{api}/groups/{grp}/folders")
                check("folders: listed for the group", st == 200 and [x["id"] for x in d.get("folders", [])] == [fid], f"{st} {d}")
                st, d = call("GET", f"{api}/groups/{grp}")
                check("folders: group folderCount reflects it", d.get("folderCount") == 1, str(d.get("folderCount")))

                # ---- GM-02: no read or write against a group that doesn't exist ----
                ghost = f"gmchk_ghost_{run}"
                st, d = call("GET", f"{api}/groups/{ghost}/folders")
                check("folders GM-02: list for nonexistent group is 404",
                      st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")
                st, d = call("GET", f"{api}/groups/{ghost}/folders/search?search={mount}")
                check("folders GM-02: search for nonexistent group is 404",
                      st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")
                st, d = call("POST", f"{api}/groups/{ghost}/folders/{fid}")
                check("folders GM-02: assign to nonexistent group is 404",
                      st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")
                st, d = call("PUT", f"{api}/groups/{ghost}/folders/{fid}/permissions",
                             {"write": True, "share": True, "delete": True})
                check("folders GM-02: set permissions for nonexistent group is 404",
                      st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")
                st, d = call("PUT", f"{api}/groups/{ghost}/folders/{fid}/quota", {"quota": 1073741824})
                check("folders GM-02: set quota for nonexistent group is 404",
                      st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")
                ghost_mount = f"{mount}_ghost"
                st, d = call("POST", f"{api}/groups/{ghost}/folders/create", {"mountPoint": ghost_mount})
                check("folders GM-02: create for nonexistent group is 404, no folder left behind",
                      st == 404 and d.get("code") == "GROUP_NOT_FOUND", f"{st} {d}")
                occ_out = occ("groupfolders:list", "--output=json").stdout
                orphan_created = any(row.get("mountPoint") == ghost_mount for row in json.loads(occ_out or "[]"))
                check("folders GM-02: rejected create did not create an orphan folder",
                      not orphan_created, occ_out)

                st, d = call("PUT", f"{api}/groups/{grp}/folders/{fid}/permissions",
                             {"write": True, "share": False, "delete": False})
                check("folders: set permissions",
                      st == 200 and d.get("permissions") == {"write": True, "share": False, "delete": False}, f"{st} {d}")
                st, d = call("GET", f"{api}/groups/{grp}/folders")
                check("folders: permissions persisted",
                      st == 200 and (d.get("folders") or [{}])[0].get("permissions") == {"write": True, "share": False, "delete": False}, f"{st} {d}")

                st, d = call("PUT", f"{api}/groups/{grp}/folders/{fid}/quota", {"quota": 1073741824})
                check("folders: set quota (1 GiB)", st == 200 and d.get("quota") == 1073741824, f"{st} {d}")
                st, d = call("PUT", f"{api}/groups/{grp}/folders/{fid}/quota", {"quota": -3})
                check("folders: set quota unlimited (-3)", st == 200 and d.get("quota") == -3, f"{st} {d}")
                st, d = call("PUT", f"{api}/groups/{grp}/folders/{fid}/quota", {"quota": -5})
                check("folders: negative quota rejected (400)", st == 400 and d.get("code") == "INVALID_QUOTA", f"{st} {d}")

                call("POST", f"{api}/groups", {"gid": grp2})
                st, d = call("GET", f"{api}/groups/{grp2}/folders/search?search={mount}")
                check("folders: assignable search finds it from another group",
                      st == 200 and fid in [x["id"] for x in d.get("folders", [])], f"{st} {d}")
                st, d = call("POST", f"{api}/groups/{grp2}/folders/{fid}")
                check("folders: assign existing folder to another group", st == 200 and d.get("id") == fid, f"{st} {d}")
                st, d = call("POST", f"{api}/groups/ship_crew/folders/{fid}")
                check("folders: assign to an LDAP group", st == 200 and d.get("id") == fid, f"{st} {d}")
                st, d = call("GET", f"{api}/groups/ship_crew/folders")
                check("folders: listed for the LDAP group", st == 200 and fid in [x["id"] for x in d.get("folders", [])], f"{st} {d}")
                st, d = call("DELETE", f"{api}/groups/ship_crew/folders/{fid}")
                check("folders: unassign from the LDAP group", st == 200, f"{st} {d}")
                st, d = call("GET", f"{api}/groups/ship_crew/folders")
                check("folders: gone from the LDAP group", st == 200 and fid not in [x["id"] for x in d.get("folders", [])], f"{st} {d}")

                r = occ("groupfolders:permissions", str(fid), "--enable")
                st, d = call("GET", f"{api}/groups/{grp}/folders")
                check("folders: ACL flag follows advanced permissions",
                      r.returncode == 0 and st == 200 and (d.get("folders") or [{}])[0].get("acl") is True, f"rc={r.returncode} {d}")
    finally:
        # ---- cleanup ---------------------------------------------------------------
        for fid in created_folders:
            occ("groupfolders:delete", "-f", str(fid))
        for g in (grp, grp2):
            call("DELETE", f"{api}/groups/{g}")
        for u in created_users:
            call("DELETE", f"{base}/ocs/v1.php/cloud/users/{u}")

    failed = sum(1 for s, _ in results if s == "FAIL")
    skipped = sum(1 for s, _ in results if s == "SKIP")
    passed = sum(1 for s, _ in results if s == "PASS")
    print(f"  => {passed} passed, {failed} failed, {skipped} skipped")
    return 1 if failed else 0


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(f"usage: {sys.argv[0]} <nc-version> <port>")
    sys.exit(main(sys.argv[1], sys.argv[2]))
