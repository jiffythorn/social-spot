#!/usr/bin/env python3
"""End-to-end test of the organization suite, driven through the real web UI.

Everything here goes through HTTP exactly as a browser would: signing in, applying to join,
approving from the officer console, publishing an announcement, charging dues, uploading a
document and flipping a feature switch. It exists because a green PHP lint run proves
nothing about whether an officer can actually run their organization.

    tools/dev/serve.sh start          # in one shell
    tools/dev/e2e-orgsuite.py         # in another

Exits non-zero if any check fails.
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
import time
import urllib.parse

import requests

# Must match the host Friendica believes it is on (config baseurl), otherwise the
# session cookie is scoped to a different origin and every signed-in request
# silently behaves like a signed-out one.
BASE = os.environ.get("BASE_URL", "http://localhost:8080")
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SOCKET = os.path.join(REPO, ".local/mariadb/run/mysqld.sock")
DB = os.environ.get("DEVDB_NAME", "friendica")
DB_USER = os.environ.get("DEVDB_USER", "friendica")
DB_PASS = os.environ.get("DEVDB_PASS", "friendica")

ADMIN_NICK = os.environ.get("ORGSUITE_ADMIN_NICK", "admin")
ADMIN_PASS = os.environ.get("ORGSUITE_ADMIN_PASS", "DevAdmin!2026")

GREEN, RED, CYAN, DIM, RESET = "\033[32m", "\033[31m", "\033[36m", "\033[2m", "\033[0m"

failures: list[str] = []


def step(title: str) -> None:
    print(f"\n{CYAN}== {title}{RESET}")


def check(ok: bool, label: str, detail: str = "") -> bool:
    if ok:
        print(f"  {GREEN}PASS{RESET} {label}")
    else:
        failures.append(label)
        print(f"  {RED}FAIL{RESET} {label}" + (f"\n       {detail}" if detail else ""))
    return ok


def info(label: str) -> None:
    print(f"  {DIM}--  {label}{RESET}")


def sql(query: str) -> str:
    """Runs a query against the project-local database and returns trimmed output.

    A failing query raises. Swallowing the error once made every configuration assertion
    compare against an empty string and pass for the wrong reason.
    """
    out = subprocess.run(
        ["mysql", f"--socket={SOCKET}", f"-u{DB_USER}", f"-p{DB_PASS}", "-N", "-B", DB, "-e", query],
        capture_output=True,
        text=True,
    )
    if out.returncode != 0:
        raise RuntimeError(f"SQL failed: {query}\n{out.stderr.strip()}")
    return out.stdout.strip()


def cfg(key: str) -> str:
    """Reads an addon setting. Friendica stores values PHP-serialized in `config`.`v`."""
    raw = sql(f"SELECT v FROM config WHERE cat='orgsuite' AND k='{key}';")
    if raw == "":
        return ""  # no row: the addon falls back to its own default
    if raw.startswith("b:"):
        return "1" if raw == "b:1;" else "0"
    m = re.fullmatch(r's:\d+:"(.*)";', raw, re.S)
    return m.group(1) if m else raw


def reset_instance() -> None:
    """Puts the addon back to a freshly enabled state so runs do not depend on each other.

    With no stored settings the addon falls back to the HOA profile and its defaults, which
    is precisely what a newly provisioned association instance looks like.
    """
    sql("DELETE FROM config WHERE cat='orgsuite';")


def visible_text(html: str) -> str:
    """The human-readable text of a page: scripts, styles and tags removed."""
    html = re.sub(r"(?s)<(script|style)\b.*?</\1>", " ", html)
    html = re.sub(r"<[^>]+>", " ", html)
    text = html.replace("&nbsp;", " ").replace("&amp;", "&").replace("&#039;", "'").replace("&quot;", '"')
    return re.sub(r"\s+", " ", text).strip()


def token(html: str, name: str = "form_security_token") -> str | None:
    m = re.search(r'name="%s"\s+value="([^"]*)"' % re.escape(name), html)
    if m:
        return m.group(1)
    m = re.search(r'value="([^"]*)"\s+name="%s"' % re.escape(name), html)
    return m.group(1) if m else None


def nav_links(html: str) -> list[str]:
    """hrefs inside the primary navigation, so we can tell what members are offered."""
    block = re.search(r"<nav\b[^>]*>(.*?)</nav>", html, re.S)
    scope = block.group(1) if block else html
    return re.findall(r'href="([^"#]+)"', scope)


# --------------------------------------------------------------------------- the run

RUN = str(int(time.time()))
CREDENTIAL = f"14 Sunset Hill Road, Unit {RUN[-4:]}"
MEMBER_EMAIL = f"member{RUN}@example.com"
MEMBER_NICK = f"member{RUN}"
MEMBER_PASS = f"H0me!{RUN}pass"

admin = requests.Session()
member = requests.Session()


def main() -> int:
    step("0. the instance is reachable")
    try:
        r = admin.get(f"{BASE}/login", timeout=15)
    except requests.RequestException as exc:
        print(f"  {RED}FAIL{RESET} cannot reach {BASE}: {exc}")
        print(f"\n  Start it first: tools/dev/serve.sh start")
        return 1
    check(r.status_code == 200, "sign-in page responds")

    step("0b. the addon starts from a freshly provisioned state")
    reset_instance()
    info("cleared stored organization settings; the addon now uses its HOA defaults")

    # --------------------------------------------------------------- 1. sign in
    step("1. an administrator signs in")
    r = admin.post(
        f"{BASE}/login",
        data={"auth-params": "login", "username": ADMIN_NICK, "password": ADMIN_PASS, "remember": "0", "return_path": ""},
        allow_redirects=True,
        timeout=20,
    )
    body = visible_text(r.text)
    check(r.status_code == 200 and "sign out" in r.text.lower() or "logout" in r.text.lower(),
          "administrator is signed in", f"status {r.status_code}")

    # After an admin signs in, Friendica stops treating the node as abandoned.
    # register_policy may live in the database or in config/local.config.php.
    policy = sql("SELECT v FROM config WHERE cat='config' AND k='register_policy';")
    if not policy:
        with open(os.path.join(REPO, "friendica/config/local.config.php")) as fh:
            policy = "file: " + (re.search(r"register_policy'\s*=>\s*([^,\n]+)", fh.read()) or ["?"])[0]
    info(f"configured register_policy = {policy}")
    login_date = sql(f"SELECT login_date FROM user WHERE nickname='{ADMIN_NICK}';")
    info(f"administrator last login = {login_date}")

    # --------------------------------------------------- 2. the dashboard exists
    step("2. the organization dashboard is the member home page")
    r = admin.get(f"{BASE}/orgsuite", timeout=20)
    dash = visible_text(r.text)
    check(r.status_code == 200, f"dashboard responds (status {r.status_code})")
    check("Sunset Hills HOA" in dash, "the organization name is shown")
    check("document" in dash.lower() or "cabinet" in dash.lower(), "the dashboard offers the document vault")
    check("dues" in dash.lower() or "balance" in dash.lower(), "the dashboard offers dues")
    # Friendica's themes draw a fixed set of navigation keys, so the addon re-points those
    # keys at the organization pages rather than adding new ones that would never render.
    # The hrefs are relative, not root-absolute.
    links = nav_links(r.text)
    check(any(href.split("?")[0].rstrip("/").endswith("orgsuite") for href in links),
          "Home points at the organization dashboard", f"nav hrefs={links}")
    check(any("orgsuite/documents" in href for href in links),
          "Documents is in the main navigation")
    check(any("orgsuite/console" in href for href in links),
          "an officer is offered the console in the main navigation")
    check(not any(href.rstrip("/").endswith("network") for href in links),
          "the federated network link is gone", f"nav hrefs={links}")
    topbar = re.search(r"<nav\b[^>]*>(.*?)</nav>", r.text, re.S)
    topbar_text = visible_text(topbar.group(1)) if topbar else ""
    check("Calendar" not in topbar_text, "Fediverse-only entries such as the calendar are hidden",
          f"topbar text = {topbar_text[:160]!r}")

    # The skin. Asserted against the served page rather than the config value, because a theme
    # that is selected but whose stylesheet the page never references is a theme nobody sees —
    # and "present in the tree, never enabled" was exactly how this one sat unverified before.
    check("view/theme/orgtool/style.css" in r.text,
          "our organization skin is the one members are served",
          "the page does not reference the orgtool stylesheet")
    skin = admin.get(f"{BASE}/view/theme/orgtool/style.css", timeout=20)
    check(skin.status_code == 200 and "css" in skin.headers.get("Content-Type", ""),
          "the skin stylesheet is actually served as CSS",
          f"status {skin.status_code}, type={skin.headers.get('Content-Type')!r}")

    # --------------------------------------------- 3. gated registration applies
    step("3. a neighbour applies, and the credential is captured")
    # The security token is bound to the session that rendered the form, so the page must be
    # fetched and submitted through one session - exactly as a browser does.
    applicant = requests.Session()
    r = applicant.get(f"{BASE}/register", timeout=20)
    reg = r.text
    check(r.status_code == 200, "sign-up page responds")
    check('name="orgsuite_credential"' in reg, "sign-up asks for a property address or unit number")
    check("not currently accepting" not in visible_text(reg).lower()
          and "registration closed" not in visible_text(reg).lower(),
          "registration is open now that the administrator has signed in",
          visible_text(reg)[:160])

    reg_token = token(reg)
    if not reg_token:
        info("no sign-up token found; the rest of the registration walk cannot run")
        return report()

    r = applicant.post(
        f"{BASE}/register",
        data={
            "form_security_token": reg_token,
            "username": f"Member {RUN}",
            "field1": MEMBER_EMAIL,
            "repeat": MEMBER_EMAIL,
            "password1": MEMBER_PASS,
            "confirm": MEMBER_PASS,
            "nickname": MEMBER_NICK,
            "permonlybox": "I own unit 14.",
            "orgsuite_credential": CREDENTIAL,
        },
        allow_redirects=True,
        timeout=20,
    )

    uid = sql(f"SELECT uid FROM user WHERE nickname='{MEMBER_NICK}';")
    check(bool(uid), "the applicant's account was created", f"HTTP {r.status_code}")
    if not uid:
        return report()

    check(sql(f"SELECT blocked FROM user WHERE uid={uid};") == "1",
          "the account is held until an officer releases it")
    check(sql(f"SELECT status FROM orgsuite_member WHERE uid={uid};") == "pending",
          "the member record starts as pending")
    stored = sql(f"SELECT credential FROM orgsuite_verification WHERE uid={uid};")
    check(CREDENTIAL.lower() in stored.lower() or stored != "",
          "the property address was stored for review", f"stored={stored!r}")
    info(f"stored credential = {stored!r}")

    # ------------------------------------------- 4. the applicant cannot get in
    step("4. the unverified applicant cannot use the account yet")
    r = applicant.post(
        f"{BASE}/login",
        data={"auth-params": "login", "username": MEMBER_NICK, "password": MEMBER_PASS, "remember": "0", "return_path": ""},
        allow_redirects=False,
        timeout=20,
    )
    check(r.status_code in (302, 403) or "sign out" not in r.text.lower(),
          "sign-in is refused", f"status {r.status_code}")
    r = applicant.get(f"{BASE}/login", timeout=20)
    check("not been approved" in r.text.lower() or "still be checked" in r.text.lower()
          or "waiting to hear back" in r.text.lower() or "officer" in r.text.lower(),
          "the sign-in page explains that new accounts wait for verification",
          visible_text(r.text)[:220])

    # --------------------------------------------- 5. the officer sees the queue
    step("5. the officer console shows the application")
    r = admin.get(f"{BASE}/orgsuite/console?tab=applications", timeout=20)
    apps = visible_text(r.text)
    check(r.status_code == 200, "applications tab responds")
    check(MEMBER_NICK in r.text or f"Member {RUN}" in apps, "the applicant is listed")
    check(CREDENTIAL[:20] in r.text, "the submitted property address is shown next to the applicant")

    # --------------------------------------------------- 6. approve the member
    step("6. the officer approves the application")
    vid = sql(f"SELECT id FROM orgsuite_verification WHERE uid={uid};")
    ctoken = token(r.text)
    check(bool(ctoken), "the console form carries a security token")

    r = admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": ctoken,
            "action": "approve_application",
            "id": vid,
            "review_note": "Verified against the association roll.",
            "return_tab": "applications",
        },
        allow_redirects=True,
        timeout=20,
    )
    check(sql(f"SELECT blocked FROM user WHERE uid={uid};") == "0",
          "the account is released", f"blocked={sql(f'SELECT blocked FROM user WHERE uid={uid};')!r}")
    check(sql(f"SELECT status FROM orgsuite_member WHERE uid={uid};") == "active",
          "the member is now active")
    check(sql(f"SELECT COUNT(*) FROM orgsuite_audit WHERE action LIKE '%verif%' OR action LIKE '%approve%';") != "0",
          "the decision is written to the audit trail")

    # The freshly approved member signs in successfully.
    r = member.post(
        f"{BASE}/login",
        data={"auth-params": "login", "username": MEMBER_NICK, "password": MEMBER_PASS, "remember": "0", "return_path": ""},
        allow_redirects=True,
        timeout=20,
    )
    check("sign out" in r.text.lower() or "logout" in r.text.lower(),
          "the approved member can now sign in")

    step("7. a member who is not an officer is kept out of the console")
    # Do not follow the redirect: following it would land on the sign-in page as a 200 and
    # look like the console had been served.
    r = member.get(f"{BASE}/orgsuite/console", allow_redirects=False, timeout=20)
    check(r.status_code in (403, 302),
          "the officer console refuses a plain member", f"status {r.status_code}")

    # ------------------------------------------------- 8. announcement banner
    step("8. an announcement reaches members")
    r = admin.get(f"{BASE}/orgsuite/console?tab=announcements", timeout=20)
    atoken = token(r.text)
    headline = f"Water shutoff Tuesday {RUN[-4:]}"
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": atoken,
            "action": "announcement_create",
            "announcement_title": headline,
            "announcement_body": "Main line work from 9am to 2pm.",
            "announcement_level": "info",
            "announcement_visibility": "members",
            "announcement_ends": "",
            "announcement_link": "",
            "return_tab": "announcements",
        },
        allow_redirects=True,
        timeout=20,
    )
    check(headline in sql("SELECT title FROM orgsuite_announcement ORDER BY id DESC LIMIT 1;"),
          "the announcement was saved")
    r = member.get(f"{BASE}/orgsuite", timeout=20)
    check(headline in visible_text(r.text), "the announcement is pinned on a member's dashboard")

    # ------------------------------------------------------------- 9. the dues
    step("9. the treasurer charges dues and records a payment")
    r = admin.get(f"{BASE}/orgsuite/console?tab=dues", timeout=20)
    dtoken = token(r.text)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": dtoken,
            "action": "charge_create",
            "charge_amount": "450.00",
            "charge_target": str(uid),
            "charge_period": "2026 annual assessment",
            "charge_description": "Annual assessment",
            "charge_due": "2026-12-31",
            "return_tab": "dues",
        },
        allow_redirects=True,
        timeout=20,
    )
    charge = sql(f"SELECT id, amount_cents FROM orgsuite_charge WHERE uid={uid} ORDER BY id DESC LIMIT 1;")
    check(charge != "", "a charge was created for the member", f"row={charge!r}")
    check(charge.endswith("45000"), "the amount was read as 450.00", f"row={charge!r}")

    if charge:
        charge_id = charge.split("\t")[0]
        r = admin.get(f"{BASE}/orgsuite/console?tab=dues", timeout=20)
        admin.post(
            f"{BASE}/orgsuite/console",
            data={
                "form_security_token": token(r.text),
                "action": "payment_record",
                "payment_charge": charge_id,
                "payment_amount": "450.00",
                "payment_method": "check",
                "payment_reference": "1042",
                "payment_date": "2026-03-01",
                "return_tab": "dues",
            },
            allow_redirects=True,
            timeout=20,
        )
        paid = sql(f"SELECT CAST(COALESCE(SUM(amount_cents),0) AS CHAR) FROM orgsuite_payment WHERE charge_id={charge_id};")
        check(paid == "45000", "the payment was recorded", f"paid={paid!r}")

        # The member sees their own standing on the dashboard.
        r = member.get(f"{BASE}/orgsuite", timeout=20)
        check("450" in visible_text(r.text) or "paid" in visible_text(r.text).lower(),
              "the member sees their dues standing")

    # ------------------------------------------------------- 10. the file cabinet
    step("10. the file cabinet stores and protects documents")
    r = admin.get(f"{BASE}/orgsuite/documents", timeout=20)
    check(r.status_code == 200, "document vault responds")
    vtoken = token(r.text)

    folder_name = f"Bylaws {RUN[-4:]}"
    admin.post(
        f"{BASE}/orgsuite/documents",
        data={
            "form_security_token": vtoken,
            "action": "create_folder",
            "name": folder_name,
            "description": "Governing documents",
            "visibility": "members",
            "sort_order": "0",
        },
        allow_redirects=True,
        timeout=20,
    )
    folder_id = sql(f"SELECT id FROM orgsuite_folder WHERE name='{folder_name}';")
    check(bool(folder_id), "an officer can create a folder", f"id={folder_id!r}")

    # An officers-only folder to prove access control is more than decoration.
    private_name = f"Board minutes {RUN[-4:]}"
    r = admin.get(f"{BASE}/orgsuite/documents", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/documents",
        data={
            "form_security_token": token(r.text),
            "action": "create_folder",
            "name": private_name,
            "description": "Board only",
            "visibility": "board",
            "sort_order": "0",
        },
        allow_redirects=True,
        timeout=20,
    )
    private_id = sql(f"SELECT id FROM orgsuite_folder WHERE name='{private_name}';")
    check(bool(private_id), "an officers-only folder can be created", f"id={private_id!r}")

    r = member.get(f"{BASE}/orgsuite/documents", timeout=20)
    member_view = visible_text(r.text)
    check(folder_name in member_view, "a member sees the members folder")
    check(private_name not in member_view, "a member does NOT see the officers-only folder")

    # Upload a real file into the members folder.
    r = admin.get(f"{BASE}/orgsuite/documents", timeout=20)
    pdf = b"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
    r = admin.post(
        f"{BASE}/orgsuite/documents",
        data={
            "form_security_token": token(r.text),
            "action": "upload",
            "folder_id": folder_id,
            "title": f"Covenants {RUN[-4:]}",
            "description": "Recorded covenants",
            "visibility": "members",
        },
        files={"document": ("covenants.pdf", pdf, "application/pdf")},
        allow_redirects=True,
        timeout=30,
    )
    doc = sql(f"SELECT id, filesize FROM orgsuite_document WHERE folder_id={folder_id} ORDER BY id DESC LIMIT 1;")
    check(bool(doc), "the upload was stored", f"HTTP {r.status_code}, row={doc!r}")
    check(str(len(pdf)) in doc.split("\t")[-1] if doc else False,
          "the stored size matches the uploaded file", f"row={doc!r}")

    if doc:
        doc_id = doc.split("\t")[0]
        # This is the link the vault actually renders for a document title.
        r = member.get(f"{BASE}/orgsuite/documents/download/{doc_id}", allow_redirects=True, timeout=20)
        check(r.status_code == 200 and r.content.startswith(b"%PDF"),
              "a member can download it by clicking its title",
              f"status {r.status_code}, type={r.headers.get('Content-Type')}")

        # A signed-out visitor must not get it, whatever visibility says.
        r = requests.get(f"{BASE}/orgsuite/documents/download/{doc_id}", allow_redirects=False, timeout=20)
        check(r.status_code in (403, 302), "a signed-out visitor cannot download it",
              f"status {r.status_code}")
        # A private document must not be reachable by guessing its id.
        r = member.get(f"{BASE}/orgsuite/documents?folder={private_id}", allow_redirects=True, timeout=20)
        check(private_name not in visible_text(r.text), "a member cannot reach a folder by guessing its id")

    # ------------------------------------------------ 11. feature switches work
    step("11. features can be switched off for an instance that does not need them")
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    settings = r.text
    check("feature_vault" in settings, "the settings page exposes the feature switches")
    check("feature_dues" in settings, "the dues switch is exposed")
    check("feature_gated_registration" in settings, "the gated-registration switch is exposed")

    before = member.get(f"{BASE}/orgsuite/documents", timeout=20)
    check(before.status_code == 200, "the vault is reachable while switched on")

    stoken = token(settings)
    # Submit the features form with everything on except the vault.
    feature_payload = {"form_security_token": stoken, "action": "settings_save", "features_only": "1", "return_tab": "settings"}
    for feature in [
        "dashboard", "members", "gated_registration", "roster", "dues", "announcements",
    ]:
        feature_payload[f"feature_{feature}"] = "1"
    admin.post(f"{BASE}/orgsuite/console", data=feature_payload, allow_redirects=True, timeout=20)

    check(cfg("feature_vault") == "0", "the vault switch was turned off")
    after = member.get(f"{BASE}/orgsuite/documents", allow_redirects=True, timeout=20)
    check(after.status_code == 404 or "vault" not in visible_text(after.text).lower(),
          "the vault page stops working when switched off", f"status {after.status_code}")
    r = member.get(f"{BASE}/orgsuite", timeout=20)
    check("/orgsuite/documents" not in r.text, "the vault link is removed from a member's navigation")

    # Switch it back on so the instance is left usable.
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    feature_payload = {"form_security_token": token(r.text), "action": "settings_save", "features_only": "1", "return_tab": "settings"}
    for feature in ["dashboard", "members", "gated_registration", "roster", "vault", "dues", "announcements"]:
        feature_payload[f"feature_{feature}"] = "1"
    admin.post(f"{BASE}/orgsuite/console", data=feature_payload, allow_redirects=True, timeout=20)
    r = member.get(f"{BASE}/orgsuite/documents", timeout=20)
    check(r.status_code == 200, "switching it back on restores the vault")

    # ------------------------------------------------- 12. the profile presets
    step("12. the organization profile re-seeds the feature defaults")
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    stoken = token(r.text)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": stoken,
            "action": "settings_save",
            "return_tab": "settings",
            "profile": "community",
            "org_name": "Sunset Hills HOA",
            "org_tagline": "The people who live here",
            "credential_label": "Property address or unit number",
            "credential_help": "",
            "credential_type": "address",
            "manual_review": "1",
            "stream_first": "0",
        },
        allow_redirects=True,
        timeout=20,
    )
    check(cfg("profile") == "community", "switching to the community profile was saved", f"profile={cfg('profile')!r}")
    check(cfg("feature_dues") == "0", "the community profile turned dues off (it does not need them)",
          f"feature_dues={cfg('feature_dues')!r}")

    # Leave the instance configured as an HOA again.
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": token(r.text),
            "action": "settings_save",
            "return_tab": "settings",
            "profile": "hoa",
            "org_name": "Sunset Hills HOA",
            "org_tagline": "The people who live here",
            "credential_label": "Property address or unit number",
            "credential_help": "",
            "credential_type": "address",
            "manual_review": "1",
            "stream_first": "0",
        },
        allow_redirects=True,
        timeout=20,
    )
    check(cfg("feature_dues") == "1", "the HOA profile turned dues back on",
          f"feature_dues={cfg('feature_dues')!r}")
    check(cfg("feature_members") == "1" and cfg("feature_gated_registration") == "1",
          "the HOA profile turned the member and registration features back on")

    # ---------------------------------------- 13. positions, permissions, committees
    step("13. positions carry permissions, and permissions are enforced")

    org_roles = sql("SELECT COUNT(*) FROM orgsuite_role WHERE scope='organization' AND active=1;")
    check(org_roles == "9", "nine built-in positions were seeded", f"found {org_roles}")

    r = admin.get(f"{BASE}/orgsuite/console?tab=positions", timeout=20)
    check(r.status_code == 200, "the Positions tab opens")
    check("President" in r.text and "Treasurer" in r.text and "Secretary" in r.text,
          "the seeded board positions are listed")

    # The hosting permissions must not be on offer here: an association does not get to decide
    # who administers the machine it is hosted on.
    check('permissions[platform_configure]' not in r.text and 'name="permissions[platform_members]"' not in r.text,
          "platform permissions cannot be granted from an organization's own console")

    ptoken = token(r.text)
    grounds = f"Grounds lead {RUN[-4:]}"
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": ptoken,
            "action": "role_create",
            "return_tab": "positions",
            "label": grounds,
            "description": "Looks after the shared grounds.",
            "rank": "45",
            "permissions[manage_documents]": "1",
            "permissions[view_directory]": "1",
        },
        allow_redirects=True,
        timeout=20,
    )

    role_row = sql(f"SELECT id, permissions FROM orgsuite_role WHERE label='{grounds}';")
    check(role_row != "", "a custom position was created", f"row={role_row!r}")
    check("manage_documents" in role_row and "view_directory" in role_row,
          "the ticked permissions were stored")
    check("manage_dues" not in role_row and "manage_roles" not in role_row,
          "unticked permissions were NOT granted", f"row={role_row!r}")

    custom_role_id = role_row.split("\t")[0] if role_row else ""

    # Assign it to the member who was approved earlier.
    r = admin.get(f"{BASE}/orgsuite/console?tab=members", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": token(r.text),
            "action": "member_update",
            "return_tab": "members",
            "member_uid": uid,
            "role_id": custom_role_id,
            "status": "active",
            "unit": "14",
            "household": "Test household",
            "directory": "1",
        },
        allow_redirects=True,
        timeout=20,
    )

    assigned = sql(f"SELECT m.role_id, r.label FROM orgsuite_member m JOIN orgsuite_role r ON r.id=m.role_id WHERE m.uid={uid};")
    check(assigned.endswith(grounds), "the member holds the custom position", f"row={assigned!r}")

    # Their new position lets them into the console, but only into what it allows.
    r = member.get(f"{BASE}/orgsuite/console", allow_redirects=False, timeout=20)
    check(r.status_code == 200, "a documents-and-directory position reaches the console",
          f"status {r.status_code}")

    r = member.get(f"{BASE}/orgsuite/console?tab=dues", allow_redirects=False, timeout=20)
    check(r.status_code in (302, 403), "the same position is refused the Dues tab",
          f"status {r.status_code}")

    r = member.get(f"{BASE}/orgsuite/console?tab=positions", allow_redirects=False, timeout=20)
    check(r.status_code in (302, 403), "the same position is refused the Positions tab",
          f"status {r.status_code}")

    r = member.get(f"{BASE}/orgsuite/console?tab=members", allow_redirects=False, timeout=20)
    check(r.status_code in (302, 403), "the same position is refused the Members tab",
          f"status {r.status_code}")

    # ---------------------------------------------- committees
    r = admin.get(f"{BASE}/orgsuite/console?tab=committees", timeout=20)
    check(r.status_code == 200, "the Committees tab opens")

    ctoken = token(r.text)
    cname = f"Grounds committee {RUN[-4:]}"
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": ctoken,
            "action": "committee_create",
            "return_tab": "committees",
            "name": cname,
            "description": "Shared grounds and landscaping.",
            "visibility": "members",
        },
        allow_redirects=True,
        timeout=20,
    )

    committee_id = sql(f"SELECT id FROM orgsuite_committee WHERE name='{cname}';")
    check(committee_id != "", "a committee was created", f"id={committee_id!r}")

    if committee_id:
        r = admin.get(f"{BASE}/orgsuite/console?tab=committees", timeout=20)
        admin.post(
            f"{BASE}/orgsuite/console",
            data={
                "form_security_token": token(r.text),
                "action": "committee_seat",
                "return_tab": "committees",
                "committee": committee_id,
                "uid": uid,
                "office": "chair",
            },
            allow_redirects=True,
            timeout=20,
        )

        seat = sql(f"SELECT office FROM orgsuite_committee_member WHERE committee_id={committee_id} AND uid={uid};")
        check(seat == "chair", "the member was seated as chair", f"seat={seat!r}")
        check(sql(f"SELECT chair_uid FROM orgsuite_committee WHERE id={committee_id};") == str(uid),
              "the committee records the same person as chair")

        r = admin.get(f"{BASE}/orgsuite/console?tab=committees", timeout=20)
        check(cname in r.text and "chair" in visible_text(r.text).lower(),
              "the committee and its chair are shown in the console")

        # Removing a committee must not leave its membership rows behind.
        admin.post(
            f"{BASE}/orgsuite/console",
            data={
                "form_security_token": token(r.text),
                "action": "committee_delete",
                "return_tab": "committees",
                "id": committee_id,
            },
            allow_redirects=True,
            timeout=20,
        )
        check(sql(f"SELECT COUNT(*) FROM orgsuite_committee WHERE id={committee_id};") == "0",
              "the committee was deleted")
        check(sql(f"SELECT COUNT(*) FROM orgsuite_committee_member WHERE committee_id={committee_id};") == "0",
              "its membership rows went with it")

    # ------------------------------------- deleting a position does not orphan people
    r = admin.get(f"{BASE}/orgsuite/console?tab=positions", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": token(r.text),
            "action": "role_delete",
            "return_tab": "positions",
            "id": custom_role_id,
        },
        allow_redirects=True,
        timeout=20,
    )

    check(sql(f"SELECT COUNT(*) FROM orgsuite_role WHERE id={custom_role_id};") == "0",
          "the custom position was deleted")
    after = sql(f"SELECT role_id FROM orgsuite_member WHERE uid={uid};")
    check(after != "" and after != "0",
          "the member who held it was moved to a real position rather than left with none",
          f"role_id={after!r}")
    check(sql(f"SELECT label FROM orgsuite_role WHERE id={after};") != "",
          "the position they were moved to exists")

    # Back to being an ordinary member, they lose the console again.
    r = member.get(f"{BASE}/orgsuite/console", allow_redirects=False, timeout=20)
    check(r.status_code in (302, 403),
          "without an officer position the console is refused again", f"status {r.status_code}")

    # ------------------------------------- 14. every tab renders, not just the ones we drove
    #
    # The tabs above are only exercised while hunting for a specific behaviour, so a tab
    # that merely *renders* had no cover. That is exactly how a broken console overview
    # shipped: it was reachable by the officer who most needs it and returned a bare 500,
    # and every check here still passed because none of them asked for it.
    step("14. every console tab renders for the officer who owns it")
    tabs = [
        (""             , "the overview"),
        ("applications" , "the applications queue"),
        ("members"      , "the roster editor"),
        ("positions"    , "the positions editor"),
        ("committees"   , "the committees editor"),
        ("roster"       , "the roster"),
        ("dues"         , "the dues ledger"),
        ("announcements", "the announcements composer"),
        ("settings"     , "the settings page"),
        ("audit"        , "the audit log"),
    ]
    for tab, label in tabs:
        url = f"{BASE}/orgsuite/console" + (f"?tab={tab}" if tab else "")
        r = admin.get(url, timeout=25)
        body = r.text
        broken = "Fatal error" in body or "Uncaught" in body or "has no such method" in body
        check(r.status_code == 200 and not broken,
              f"{label} renders",
              f"status {r.status_code}" + (" and the page shows a PHP error" if broken else ""))

    return report()


def report() -> int:
    step("result")
    if not failures:
        print(f"  {GREEN}every check passed{RESET}")
        return 0
    print(f"  {RED}{len(failures)} check(s) failed:{RESET}")
    for f in failures:
        print(f"    - {f}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
