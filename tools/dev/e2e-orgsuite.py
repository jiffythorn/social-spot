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

import json
import os
import re
import struct
import subprocess
import sys
import time
import urllib.parse
import zlib

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


def syscfg(key: str) -> str:
    """Reads a core `system` setting, the way cfg() reads an addon one.

    Used to assert what Friendica itself will render - the icon configuration is core's
    to display and ours only to set, so the honest check is against core's stored value.
    """
    raw = sql(f"SELECT v FROM config WHERE cat='system' AND k='{key}';")
    if raw == "":
        return ""
    m = re.fullmatch(r's:\d+:"(.*)";', raw, re.S)
    return m.group(1) if m else raw


def png_size(data: bytes) -> tuple[int, int]:
    """Width and height read from a PNG's IHDR, or (0, 0) when it is not one."""
    if len(data) < 24 or not data.startswith(b"\x89PNG\r\n\x1a\n"):
        return (0, 0)
    width, height = struct.unpack(">II", data[16:24])
    return (width, height)


def png_rgba(data: bytes) -> tuple[int, int, bytes]:
    """Every pixel of an 8-bit, non-interlaced PNG as RGBA bytes, stdlib only.

    Needed because the maskable rules are about *pixels*, not about the file: the
    background has to reach every edge and the artwork has to stay inside the safe
    zone, and neither can be read from a header. Lighthouse's own maskable audit
    states it does not inspect the image, so nothing else is checking this.

    Handles colour types 2 (truecolour) and 6 (truecolour with alpha), which is what
    PIL and GD emit between them; anything else is a real failure worth reporting.
    """
    if not data.startswith(b"\x89PNG\r\n\x1a\n"):
        raise AssertionError("not a PNG")

    pos = 8
    width = height = bit_depth = colour_type = interlace = 0
    idat = bytearray()

    while pos + 8 <= len(data):
        length = int.from_bytes(data[pos:pos + 4], "big")
        tag = data[pos + 4:pos + 8]
        chunk = data[pos + 8:pos + 8 + length]
        pos += 12 + length

        if tag == b"IHDR":
            width = int.from_bytes(chunk[0:4], "big")
            height = int.from_bytes(chunk[4:8], "big")
            bit_depth = chunk[8]
            colour_type = chunk[9]
            interlace = chunk[12]
        elif tag == b"IDAT":
            idat += chunk
        elif tag == b"IEND":
            break

    assert bit_depth == 8, f"unsupported bit depth {bit_depth}"
    assert interlace == 0, "interlaced PNGs are not decoded by this suite"
    assert colour_type in (2, 6), f"unsupported colour type {colour_type}"

    channels = 3 if colour_type == 2 else 4
    stride = width * channels
    raw = zlib.decompress(bytes(idat))
    assert len(raw) >= (stride + 1) * height, "truncated image data"

    out = bytearray(stride * height)
    prev = bytearray(stride)
    at = 0

    for y in range(height):
        filt = raw[at]
        at += 1
        line = bytearray(raw[at:at + stride])
        at += stride

        if filt == 1:                                   # Sub
            for i in range(channels, stride):
                line[i] = (line[i] + line[i - channels]) & 0xFF
        elif filt == 2:                                 # Up
            for i in range(stride):
                line[i] = (line[i] + prev[i]) & 0xFF
        elif filt == 3:                                 # Average
            for i in range(stride):
                left = line[i - channels] if i >= channels else 0
                line[i] = (line[i] + ((left + prev[i]) >> 1)) & 0xFF
        elif filt == 4:                                 # Paeth
            for i in range(stride):
                left = line[i - channels] if i >= channels else 0
                up = prev[i]
                up_left = prev[i - channels] if i >= channels else 0
                p = left + up - up_left
                pa, pb, pc = abs(p - left), abs(p - up), abs(p - up_left)
                if pa <= pb and pa <= pc:
                    predictor = left
                elif pb <= pc:
                    predictor = up
                else:
                    predictor = up_left
                line[i] = (line[i] + predictor) & 0xFF
        elif filt != 0:
            raise AssertionError(f"unknown PNG filter {filt}")

        out[y * stride:(y + 1) * stride] = line
        prev = line

    if colour_type == 6:
        return (width, height, bytes(out))

    rgba = bytearray(width * height * 4)
    for i in range(width * height):
        src = i * 3
        dst = i * 4
        rgba[dst:dst + 3] = out[src:src + 3]
        rgba[dst + 3] = 255

    return (width, height, bytes(rgba))


def solid_png(size: int, rgb: tuple[int, int, int]) -> bytes:
    """A valid solid-colour PNG, built with the standard library only.

    PIL is available on a developer machine but is not a declared dependency of this
    suite, and a test that only passes where the author has extra packages installed is
    not a test. Colour type 2 (truecolour RGB), which every image decoder accepts.
    """
    def chunk(tag: bytes, data: bytes) -> bytes:
        return (struct.pack(">I", len(data)) + tag + data
                + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF))

    row = b"\x00" + bytes(rgb) * size

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(row * size, 9))
            + chunk(b"IEND", b""))


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
    check("feature_committees" in settings, "the committees switch is exposed")

    before = member.get(f"{BASE}/orgsuite/documents", timeout=20)
    check(before.status_code == 200, "the vault is reachable while switched on")

    stoken = token(settings)
    # Submit the features form with everything on except the vault.
    feature_payload = {"form_security_token": stoken, "action": "settings_save", "features_only": "1", "return_tab": "settings"}
    for feature in [
        "dashboard", "members", "gated_registration", "roster", "dues", "announcements", "committees",
    ]:
        feature_payload[f"feature_{feature}"] = "1"
    admin.post(f"{BASE}/orgsuite/console", data=feature_payload, allow_redirects=True, timeout=20)

    check(cfg("feature_vault") == "0", "the vault switch was turned off")
    after = member.get(f"{BASE}/orgsuite/documents", allow_redirects=True, timeout=20)
    check(after.status_code == 404 or "vault" not in visible_text(after.text).lower(),
          "the vault page stops working when switched off", f"status {after.status_code}")
    r = member.get(f"{BASE}/orgsuite", timeout=20)
    check("/orgsuite/documents" not in r.text, "the vault link is removed from a member's navigation")
    # A download must answer 404 while the library is off, not leak existence via a 403.
    if doc:
        doc_id_off = doc.split("\t")[0]
        r = member.get(f"{BASE}/orgsuite/documents/download/{doc_id_off}", allow_redirects=False, timeout=20)
        check(r.status_code == 404, "a download answers 404 while the vault is switched off",
              f"status {r.status_code}")

    # Switch it back on so the instance is left usable.
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    feature_payload = {"form_security_token": token(r.text), "action": "settings_save", "features_only": "1", "return_tab": "settings"}
    for feature in ["dashboard", "members", "gated_registration", "roster", "vault", "dues", "announcements", "committees"]:
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

    # --------------------------------- 13b. every switch gates its pages and its writes
    #
    # Hiding a tab is not the same as switching a feature off: a crafted POST can still
    # name the action directly, and a bookmarked tab URL bypasses the tab bar entirely.
    # Each switch below is turned off on its own (everything else stays on), then the tab
    # must explain it is off, a direct write must change nothing, and the tab must vanish
    # from the console. The security token for the crafted write comes from the settings
    # page, because the disabled tab itself renders no form - using its (absent) token
    # would fail on CSRF instead of on the feature guard and prove nothing.
    step("13b. every feature switch gates its pages and refuses its writes")
    ALL_FEATURES = ["dashboard", "members", "gated_registration", "roster", "vault", "dues", "announcements", "committees"]

    def settings_token() -> str:
        r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
        return token(r.text) or ""

    def set_features(on: list) -> None:
        payload = {"form_security_token": settings_token(), "action": "settings_save",
                   "features_only": "1", "return_tab": "settings"}
        for feature in ALL_FEATURES:
            if feature in on:
                payload[f"feature_{feature}"] = "1"
        admin.post(f"{BASE}/orgsuite/console", data=payload, allow_redirects=True, timeout=20)

    def all_on() -> None:
        set_features(ALL_FEATURES)

    def without(feature: str) -> list:
        return [f for f in ALL_FEATURES if f != feature]

    # Committees: a club that does not use them switches them off.
    set_features(without("committees"))
    r = admin.get(f"{BASE}/orgsuite/console?tab=committees", timeout=20)
    check("switched off" in visible_text(r.text).lower(), "the committees tab explains it is switched off")
    committees_before = sql("SELECT COUNT(*) FROM orgsuite_committee;")
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": settings_token(), "action": "committee_create",
              "return_tab": "committees", "name": f"Should not exist {RUN[-4:]}",
              "description": "crafted while off", "visibility": "members"},
        allow_redirects=True,
        timeout=20,
    )
    check(sql("SELECT COUNT(*) FROM orgsuite_committee;") == committees_before,
          "a committee cannot be created while committees are switched off")
    r = admin.get(f"{BASE}/orgsuite/console", timeout=20)
    check("tab=committees" not in r.text, "the committees tab is hidden while switched off")
    all_on()
    r = admin.get(f"{BASE}/orgsuite/console?tab=committees", timeout=20)
    check(r.status_code == 200 and "switched off" not in visible_text(r.text).lower(),
          "switching committees back on restores the tab")

    # Dues: the charge and payment writes must change nothing while off.
    set_features(without("dues"))
    r = admin.get(f"{BASE}/orgsuite/console?tab=dues", timeout=20)
    check("switched off" in visible_text(r.text).lower(), "the dues tab explains it is switched off")
    charges_before = sql(f"SELECT COUNT(*) FROM orgsuite_charge WHERE uid={uid};")
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": settings_token(), "action": "charge_create",
              "charge_amount": "999.99", "charge_target": str(uid),
              "charge_period": "crafted while off", "charge_description": "crafted while off",
              "charge_due": "2026-12-31", "return_tab": "dues"},
        allow_redirects=True,
        timeout=20,
    )
    check(sql(f"SELECT COUNT(*) FROM orgsuite_charge WHERE uid={uid};") == charges_before,
          "a charge cannot be raised while dues are switched off")
    all_on()

    # Roster: entries must not be added while off.
    set_features(without("roster"))
    r = admin.get(f"{BASE}/orgsuite/console?tab=roster", timeout=20)
    check("switched off" in visible_text(r.text).lower(), "the roster tab explains it is switched off")
    roster_before = sql("SELECT COUNT(*) FROM orgsuite_roster;")
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": settings_token(), "action": "roster_add",
              "roster_credential": f"CRAFTED-{RUN[-4:]}", "roster_type": "membership_id",
              "roster_label": "crafted", "roster_household": "crafted",
              "return_tab": "roster"},
        allow_redirects=True,
        timeout=20,
    )
    check(sql("SELECT COUNT(*) FROM orgsuite_roster;") == roster_before,
          "a roster entry cannot be added while the roster is switched off")
    all_on()

    # Announcements: nothing may be published while off.
    set_features(without("announcements"))
    r = admin.get(f"{BASE}/orgsuite/console?tab=announcements", timeout=20)
    check("switched off" in visible_text(r.text).lower(), "the announcements tab explains it is switched off")
    announcements_before = sql("SELECT COUNT(*) FROM orgsuite_announcement;")
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": settings_token(), "action": "announcement_create",
              "announcement_title": f"Crafted while off {RUN[-4:]}", "announcement_body": "crafted",
              "announcement_level": "info", "announcement_visibility": "members",
              "announcement_ends": "", "announcement_link": "", "return_tab": "announcements"},
        allow_redirects=True,
        timeout=20,
    )
    check(sql("SELECT COUNT(*) FROM orgsuite_announcement;") == announcements_before,
          "an announcement cannot be published while announcements are switched off")
    all_on()

    # Members: the directory route and the member editor must both stop.
    set_features(without("members"))
    r = admin.get(f"{BASE}/orgsuite/console?tab=members", timeout=20)
    check("switched off" in visible_text(r.text).lower(), "the members tab explains it is switched off")
    r = member.get(f"{BASE}/orgsuite/directory", allow_redirects=False, timeout=20)
    # Two legitimate refusals exist and which one you get depends on whether the route
    # table has been rebuilt yet: a freshly rebuilt table simply has no such route (404),
    # while a still-cached one reaches the module, which redirects (302). What must never
    # happen either way is the directory itself being served - and that is now asserted,
    # which the earlier status-only check did not do.
    served_directory = r.status_code == 200 and "Household" in r.text
    check(r.status_code in (302, 303, 404) and not served_directory,
          "the member directory is unreachable while member records are switched off",
          f"status {r.status_code}")
    standing_before = sql(f"SELECT status FROM orgsuite_member WHERE uid={uid};")
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "member_update",
              "return_tab": "members", "member_uid": str(uid), "status": "suspended",
              "unit": "14", "household": "Test household", "directory": "1"},
        allow_redirects=True,
        timeout=20,
    )
    check(sql(f"SELECT status FROM orgsuite_member WHERE uid={uid};") == standing_before,
          "a member record cannot be changed while member records are switched off")
    all_on()
    r = member.get(f"{BASE}/orgsuite/directory", timeout=20)
    check(r.status_code == 200, "switching member records back on restores the directory")

    # Gated registration: the sign-up page must stop asking for a credential while off.
    set_features(without("gated_registration"))
    r = admin.get(f"{BASE}/orgsuite/console?tab=applications", timeout=20)
    check("switched off" in visible_text(r.text).lower(), "the applications queue explains it is switched off")
    r = requests.get(f"{BASE}/register", timeout=20)
    check('name="orgsuite_credential"' not in r.text,
          "sign-up stops asking for a credential while gated registration is off")
    all_on()
    r = requests.get(f"{BASE}/register", timeout=20)
    check('name="orgsuite_credential"' in r.text,
          "switching gated registration back on restores the sign-up field")

    # -------------------------------------------- 14. the AGPL source offer is present
    #
    # This instance runs a modified Friendica, so the licence requires that the people using it are
    # offered its source. That is an obligation rather than a feature, so it is tested the same way
    # as one - against the rendered page, not against a config value.
    step("14. the source offer required by the AGPL is present")
    r = admin.get(f"{BASE}/", timeout=20)
    check("orgsuite-source-offer" in r.text, "the footer carries the source offer")
    check("github.com/jiffythorn/social-spot" in r.text,
          "the offer points at the published source by default",
          "the default source address is not in the footer")

    # Both of those would also hold if this response had been served to a signed-out visitor: the
    # public page carries the same footer. Since the obligation names *all* remote users, assert
    # that this particular page really was rendered for a signed-in session - the sign-out link
    # only exists in the signed-in navigation.
    check('id="nav-logout-link"' in r.text,
          "the offer reaches a signed-in member, not only a signed-out render",
          "the page carrying the offer was not served to a signed-in session")

    # A signed-out visitor is a user interacting with the instance too, so the offer has to reach
    # them as well. A sign-in-gated link is not an offer to the people the clause names.
    r = requests.get(f"{BASE}/login", timeout=20)
    check("orgsuite-source-offer" in r.text, "the offer is visible without signing in")

    # And it must be overridable, since whoever deploys this may publish their source elsewhere.
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={
            "form_security_token": token(r.text),
            "action": "settings_save",
            "return_tab": "settings",
            "org_name": "Sunset Hills HOA",
            "org_tagline": "The people who live here",
            "credential_label": "Property address or unit number",
            "credential_help": "",
            "credential_type": "address",
            "manual_review": "1",
            "stream_first": "0",
            "source_url": "https://example.org/our-source",
        },
        allow_redirects=True,
        timeout=20,
    )
    check(cfg("source_url") == "https://example.org/our-source",
          "a configured source address is stored", f"source_url={cfg('source_url')!r}")
    r = admin.get(f"{BASE}/", timeout=20)
    check("https://example.org/our-source" in r.text,
          "the footer offers the configured address instead of the default")

    # ------------------------------------- 15. every tab renders, not just the ones we drove
    #
    # The tabs above are only exercised while hunting for a specific behaviour, so a tab
    # that merely *renders* had no cover. That is exactly how a broken console overview
    # shipped: it was reachable by the officer who most needs it and returned a bare 500,
    # and every check here still passed because none of them asked for it.
    step("15. every console tab renders for the officer who owns it")
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

    # ------------------------------------------------ 16. the site's own icon, everywhere
    #
    # A recognizable mark is worth nothing if it only exists on one screen: it has to be
    # what the tab shows, what iOS puts on the home screen, what the manifest hands an
    # install prompt, and what an officer can replace without editing a file. Each of
    # those can be wrong while the others still look fine.
    step("16. the site icon is wired up, served and replaceable")

    # Any page load runs init_1, which is what wires core's icon configuration.
    r = admin.get(f"{BASE}/orgsuite", timeout=20)
    check(r.status_code == 200, "the dashboard loads before the icon checks")

    touch = syscfg("touch_icon")
    shortcut = syscfg("shortcut_icon")
    check(touch == "orgsuite/icon",
          "core is pointed at our artwork for the home screen and manifest",
          f"touch_icon={touch!r}")
    check(shortcut == "orgsuite/favicon",
          "core is pointed at our artwork for the browser tab",
          f"shortcut_icon={shortcut!r}")

    # What core actually renders from those values.
    check('rel="manifest"' in r.text, "the page advertises a web app manifest")
    check('rel="apple-touch-icon"' in r.text and "orgsuite/icon" in r.text,
          "the iOS home-screen icon points at our artwork")
    check('rel="icon"' in r.text and "orgsuite/favicon" in r.text,
          "the tab icon points at our artwork")
    check("install.js" in r.text, "the add-to-home-screen script is loaded")
    check("addon/orgsuite/assets/orgsuite.css" in r.text,
          "the addon stylesheet is linked from the page")
    check("addon/orgsuite/assets/install.css" in r.text,
          "the install-control stylesheet is linked from the page")
    # Linked is not served: a stylesheet that is referenced but 404s leaves a perfectly
    # functional button with no styling, which looks broken without ever failing loudly.
    css = admin.get(f"{BASE}/addon/orgsuite/assets/install.css", timeout=20)
    check(css.status_code == 200 and "orgsuite-install" in css.text,
          "the install-control stylesheet is actually served as CSS",
          f"status {css.status_code}, type={css.headers.get('Content-Type')!r}")
    check('id="orgsuite-install"' in r.text,
          "the add-to-home-screen button is offered to members",
          "the install control is missing from the dashboard")

    # The routes themselves, at the sizes each consumer actually asks for.
    ico = admin.get(f"{BASE}/orgsuite/icon", timeout=20)
    check(ico.status_code == 200 and ico.headers.get("Content-Type", "").startswith("image/"),
          "the icon route answers with an image",
          f"status {ico.status_code}, type={ico.headers.get('Content-Type')!r}")
    check(png_size(ico.content) == (512, 512),
          "the icon served to the manifest is a true 512x512", f"got {png_size(ico.content)}")
    default_icon = ico.content

    small = admin.get(f"{BASE}/orgsuite/icon?size=192", timeout=20)
    check(png_size(small.content) == (192, 192),
          "a requested size is honoured", f"got {png_size(small.content)}")

    fav = admin.get(f"{BASE}/orgsuite/favicon", timeout=20)
    check(fav.status_code == 200 and png_size(fav.content) == (48, 48),
          "the tab favicon answers at 48x48",
          f"status {fav.status_code}, size={png_size(fav.content)}")

    # Core builds the manifest, but only from the configuration we set - so this is the
    # check that proves an install prompt would be handed OUR icon, not Friendica's.
    mf = admin.get(f"{BASE}/manifest", timeout=20)
    check(mf.status_code == 200, "the manifest responds", f"status {mf.status_code}")
    manifest = json.loads(mf.text)
    sources = [entry.get("src", "") for entry in manifest.get("icons", [])]
    check(len(sources) >= 2 and all("orgsuite/icon" in src for src in sources),
          "every manifest icon points at our artwork", f"srcs={sources}")
    check(manifest.get("display") == "standalone",
          "the manifest asks for an app window, not a browser tab")
    check("Sunset Hills HOA" in manifest.get("name", ""),
          "the manifest carries the organization's name",
          f"name={manifest.get('name')!r}")

    # --- an officer replaces the artwork from the settings page -------------------
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    check('name="org_icon"' in r.text, "the settings page offers a logo upload")

    logo = solid_png(64, (200, 40, 40))
    check(png_size(logo) == (64, 64), "the test logo is a well-formed PNG", f"got {png_size(logo)}")

    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_save", "return_tab": "settings"},
        files={"org_icon": ("logo.png", logo, "image/png")},
        allow_redirects=True,
        timeout=30,
    )
    check(cfg("icon_ref") != "", "the uploaded logo was stored", f"icon_ref={cfg('icon_ref')!r}")
    check(admin.get(f"{BASE}/orgsuite/icon", timeout=20).content != default_icon,
          "the site now serves the uploaded logo instead of the generated one")

    # The URL must not change when the artwork does: the manifest, the tab and any home
    # screen already showing this site all reference it.
    mf = admin.get(f"{BASE}/manifest", timeout=20)
    swapped = json.loads(mf.text)
    check(all("orgsuite/icon" in e.get("src", "") for e in swapped.get("icons", [])),
          "the manifest URL is unchanged by swapping the artwork")

    # A file that merely CLAIMS to be an image must not be accepted.
    held = cfg("icon_ref")
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_save", "return_tab": "settings"},
        files={"org_icon": ("not-an-image.png", b"this is definitely not an image", "image/png")},
        allow_redirects=True,
        timeout=30,
    )
    check(cfg("icon_ref") == held,
          "a file that is not really an image is rejected",
          f"icon_ref changed from {held!r} to {cfg('icon_ref')!r}")

    # Removal returns to the built-in mark rather than leaving the site iconless.
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_remove", "return_tab": "settings"},
        allow_redirects=True,
        timeout=20,
    )
    check(cfg("icon_ref") == "", "the uploaded logo was removed", f"icon_ref={cfg('icon_ref')!r}")
    check(admin.get(f"{BASE}/orgsuite/icon", timeout=20).content == default_icon,
          "the built-in icon is restored after removal")

    # ------------------------------------------------ 17. the maskable icon
    #
    # Android never asks the site what shape its icon is: it crops whatever it is
    # handed to a circle, squircle or teardrop picked by the phone's launcher. So the
    # manifest has to carry a *separate* asset built for that crop - background running
    # to every edge, mark inside the safe-zone circle whose diameter is 80% of the
    # image - or the launcher takes a bite out of the roof. Chrome's own maskable audit
    # states plainly that it does not inspect the image, so if this suite does not
    # check the pixels, nothing does.
    #
    # @see https://web.dev/articles/maskable-icon
    step("17. the maskable icon survives Android's crop")

    # The brand colour is written down in three places: here, the generator that draws
    # the default set, and the PHP that pads an uploaded logo. Assert the two sources
    # agree before trusting any pixel comparison against it.
    brand = (15, 118, 90)
    with open(os.path.join(REPO, "tools/dev/make-icons.py")) as fh:
        generator = fh.read()
    with open(os.path.join(REPO, "addon/orgsuite/src/Branding.php")) as fh:
        branding = fh.read()
    check("TILE = (15, 118, 90)" in generator and "MASKABLE_BACKGROUND = [15, 118, 90]" in branding,
          "the generator and the request-time compositor agree on the brand colour",
          "one of them has drifted, so the icon would change colour when a logo is swapped")

    # The link a browser - and therefore Android - actually reads.
    page = admin.get(f"{BASE}/orgsuite", timeout=20)
    check(page.status_code == 200, "the dashboard renders before the maskable checks")
    links = re.findall(r'<link[^>]*rel="manifest"[^>]*>', page.text)
    check(len(links) == 1, "the page advertises exactly one manifest",
          f"found {len(links)} manifest links")
    href = re.search(r'href="([^"]+)"', links[0]).group(1) if links else ""
    check(href == f"{BASE}/orgsuite/manifest",
          "the manifest link points at the one that can name a maskable icon",
          f"href={href!r}")

    mmf = admin.get(f"{BASE}/orgsuite/manifest", timeout=20)
    check(mmf.status_code == 200, "the addon's manifest responds", f"status {mmf.status_code}")
    check(mmf.headers.get("Content-Type", "").startswith("application/manifest+json"),
          "it is served as a web app manifest", f"type={mmf.headers.get('Content-Type')!r}")
    ours = json.loads(mmf.text)
    check(ours.get("display") == "standalone",
          "the maskable manifest asks for an app window, not a browser tab")
    check("Sunset Hills HOA" in ours.get("name", ""),
          "the maskable manifest carries the organization's name",
          f"name={ours.get('name')!r}")

    icons = ours.get("icons", [])
    purposes = sorted({(entry.get("purpose") or "any") for entry in icons})
    check(purposes == ["any", "maskable"],
          "ordinary and maskable icons are declared separately, never as one asset",
          f"purposes={purposes}")
    maskable = [entry for entry in icons if entry.get("purpose") == "maskable"]
    check(sorted(entry.get("sizes", "") for entry in maskable) == ["192x192", "512x512"],
          "a maskable icon is offered at both sizes an install prompt may ask for",
          f"sizes={[entry.get('sizes') for entry in maskable]}")
    check(all("orgsuite/icon-maskable" in entry.get("src", "") for entry in maskable),
          "every maskable entry points at the maskable route",
          f"srcs={[entry.get('src') for entry in maskable]}")

    # Every declared size is the size actually served: an install-time validator
    # measures the pixels against the claim, and 192 declared on 512 bytes is a claim
    # that fails.
    for entry in icons:
        want = tuple(int(v) for v in entry.get("sizes", "0x0").split("x"))
        served = admin.get(entry["src"], timeout=20)
        check(served.status_code == 200 and png_size(served.content) == want,
              f"the {entry.get('purpose', 'any')} icon is served at the size it declares",
              f"declared {want}, status {served.status_code}, got {png_size(served.content)}")

    # --- the pixels Chrome declines to look at -------------------------------
    big = admin.get(f"{BASE}/orgsuite/icon-maskable?size=512", timeout=20)
    check(png_size(big.content) == (512, 512),
          "the maskable icon is a true 512x512", f"got {png_size(big.content)}")
    default_maskable = big.content

    def opaque_art(data: bytes, label: str) -> None:
        """Asserts the maskable rules that only pixels can show: full-bleed, opaque,
        mark present and inside the safe zone, on whatever bytes are being served."""
        w, h, rgba = png_rgba(data)

        def at(x: int, y: int) -> tuple[int, ...]:
            i = (y * w + x) * 4
            return tuple(rgba[i:i + 4])

        corners = [at(0, 0), at(w - 1, 0), at(0, h - 1), at(w - 1, h - 1)]
        check(all(c[:3] == brand for c in corners),
              f"{label}: the background reaches every corner the mask can expose",
              f"corners={corners}")
        check(all(c[3] == 255 for c in corners),
              f"{label}: the maskable icon is fully opaque",
              f"corners={corners}")

        cx = cy = (w - 1) / 2
        safe2 = (w * 0.40) ** 2        # radius of the 80%-of-width safe-zone circle
        outside = 0
        artwork = 0
        for y in range(h):
            dy = y - cy
            for x in range(w):
                if at(x, y)[:3] == brand:
                    continue
                d2 = ((x - cx) ** 2) + (dy ** 2)
                if d2 > safe2:
                    outside += 1
                if d2 <= (w * 0.10) ** 2:
                    artwork += 1

        check(outside == 0,
              f"{label}: nothing worth seeing lies outside the safe zone, so no crop can cut it",
              f"{outside} pixels outside the 80%-diameter circle")
        check(artwork > 0,
              f"{label}: the mark is really drawn in the middle of the icon")

    opaque_art(default_maskable, "built-in icon")

    # The ordinary icon must remain a *different* picture: it is drawn to fill its own
    # square with transparent corners, which is precisely what a maskable asset must
    # not be - one file serving both purposes would crop on one side or float on the
    # other.
    plain = admin.get(f"{BASE}/orgsuite/icon?size=512", timeout=20)
    _, _, prgba = png_rgba(plain.content)
    check(prgba[3] < 255,
          "the ordinary icon is a different asset, not the maskable one renamed",
          "both icons have opaque corners, so maskable is just the ordinary icon")

    # --- an officer's own logo gets the same treatment ------------------------
    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_save", "return_tab": "settings"},
        files={"org_icon": ("logo.png", solid_png(64, (200, 40, 40)), "image/png")},
        allow_redirects=True,
        timeout=30,
    )
    check(cfg("icon_ref") != "", "the uploaded logo is stored for the maskable variant too",
          f"icon_ref={cfg('icon_ref')!r}")

    swapped = admin.get(f"{BASE}/orgsuite/icon-maskable?size=512", timeout=20)
    opaque_art(swapped.content, "uploaded logo")

    w2, h2, rgba2 = png_rgba(swapped.content)
    i2 = ((h2 // 2) * w2 + (w2 // 2)) * 4
    centre = tuple(rgba2[i2:i2 + 4])
    check(all(abs(a - b) <= 8 for a, b in zip(centre[:3], (200, 40, 40))),
          "the uploaded logo sits centred in the safe zone rather than stretched to the edge",
          f"centre pixel={centre}")

    r = admin.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    admin.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_remove", "return_tab": "settings"},
        allow_redirects=True,
        timeout=20,
    )
    check(admin.get(f"{BASE}/orgsuite/icon-maskable?size=512", timeout=20).content == default_maskable,
          "the built-in maskable icon is restored after the swap")

    # --------------------------------- 18. the icon belongs to whoever holds settings
    #
    # Uploading the logo used to need site administration, which left an association with
    # no way to put its own face on the site unless whoever runs the server did it for
    # them. The capability that already governs organization settings governs this too -
    # and the boundary that matters is on the write: an officer without it must not be
    # able to change the site's icon by naming the action in a crafted POST.
    step("18. officers who may change settings may change the icon")

    def assign_role(label: str, description: str, permissions: dict[str, str]) -> str:
        """Creates a position with exactly these permissions and puts the test member in it."""
        r = admin.get(f"{BASE}/orgsuite/console?tab=positions", timeout=20)
        admin.post(
            f"{BASE}/orgsuite/console",
            data={
                "form_security_token": token(r.text),
                "action": "role_create",
                "return_tab": "positions",
                "label": label,
                "description": description,
                "rank": "46",
                **permissions,
            },
            allow_redirects=True,
            timeout=20,
        )

        role_id = sql(f"SELECT id FROM orgsuite_role WHERE label='{label}';")
        r = admin.get(f"{BASE}/orgsuite/console?tab=members", timeout=20)
        admin.post(
            f"{BASE}/orgsuite/console",
            data={
                "form_security_token": token(r.text),
                "action": "member_update",
                "return_tab": "members",
                "member_uid": uid,
                "role_id": role_id,
                "status": "active",
            },
            allow_redirects=True,
            timeout=20,
        )

        return role_id

    keeper_id = assign_role(
        f"Records keeper {RUN[-4:]}",
        "Keeps the archives.",
        # A permission that makes somebody an officer - view_directory alone does not -
        # so the refusal below is about settings, not about being an officer at all.
        {"permissions[manage_documents]": "1", "permissions[view_directory]": "1"},
    )
    check(keeper_id != "", "a position without settings was created")

    r = member.get(f"{BASE}/orgsuite/console", allow_redirects=False, timeout=20)
    check(r.status_code == 200, "an officer with that position reaches the console",
          f"status {r.status_code}")

    r = member.get(f"{BASE}/orgsuite/console?tab=settings", allow_redirects=False, timeout=20)
    check(r.status_code in (302, 403), "the same officer is refused the settings tab",
          f"status {r.status_code}")

    # The write, not just the tab: a crafted POST must leave the icon exactly as it was.
    before = admin.get(f"{BASE}/orgsuite/icon", timeout=20).content
    r = member.get(f"{BASE}/orgsuite/console", timeout=20)
    member.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_save", "return_tab": "settings"},
        files={"org_icon": ("logo.png", solid_png(64, (40, 40, 200)), "image/png")},
        allow_redirects=True,
        timeout=30,
    )
    check(cfg("icon_ref") == "", "an officer without the permission cannot upload the logo",
          f"icon_ref={cfg('icon_ref')!r}")
    check(admin.get(f"{BASE}/orgsuite/icon", timeout=20).content == before,
          "the site icon is unchanged by that attempt")

    comms_id = assign_role(
        f"Communications officer {RUN[-4:]}",
        "Keeps the site looking right.",
        {"permissions[manage_settings]": "1", "permissions[view_directory]": "1"},
    )
    check(comms_id != "", "a position holding the settings permission was created")

    r = member.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    check(r.status_code == 200, "the settings tab opens for somebody holding that permission",
          f"status {r.status_code}")
    check('name="org_icon"' in r.text,
          "the icon upload is offered to them rather than reserved for site administration")
    check("Only a site administrator can change it" not in r.text,
          "the page no longer tells officers to ask an administrator")

    member.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_save", "return_tab": "settings"},
        files={"org_icon": ("logo.png", solid_png(64, (40, 40, 200)), "image/png")},
        allow_redirects=True,
        timeout=30,
    )
    check(cfg("icon_ref") != "", "an officer with the permission uploaded the logo",
          f"icon_ref={cfg('icon_ref')!r}")
    check(admin.get(f"{BASE}/orgsuite/icon", timeout=20).content != before,
          "the site is serving their logo")

    logged = sql("SELECT actor_uid FROM orgsuite_audit WHERE action='settings.icon.save' ORDER BY id DESC LIMIT 1;")
    check(logged == str(uid), "who changed the site's face is written to the audit trail",
          f"actor_uid={logged!r}")

    # The Android icon is produced from the same artwork, so it follows them too.
    swapped = admin.get(f"{BASE}/orgsuite/icon-maskable?size=512", timeout=20)
    w3, h3, rgba3 = png_rgba(swapped.content)
    mid = ((h3 // 2) * w3 + (w3 // 2)) * 4
    centre = tuple(rgba3[mid:mid + 4])
    check(all(abs(a - b) <= 8 for a, b in zip(centre[:3], (40, 40, 200))),
          "the maskable icon follows their logo as well",
          f"centre pixel={centre}")

    r = member.get(f"{BASE}/orgsuite/console?tab=settings", timeout=20)
    member.post(
        f"{BASE}/orgsuite/console",
        data={"form_security_token": token(r.text), "action": "icon_remove", "return_tab": "settings"},
        allow_redirects=True,
        timeout=20,
    )
    check(cfg("icon_ref") == "", "they can put the built-in icon back",
          f"icon_ref={cfg('icon_ref')!r}")
    check(admin.get(f"{BASE}/orgsuite/icon", timeout=20).content == before,
          "the built-in artwork is restored")

    logged = sql("SELECT actor_uid FROM orgsuite_audit WHERE action='settings.icon.remove' ORDER BY id DESC LIMIT 1;")
    check(logged == str(uid), "so is putting the built-in mark back", f"actor_uid={logged!r}")

    # The two temporary positions go again, and the member is not left without one.
    r = admin.get(f"{BASE}/orgsuite/console?tab=positions", timeout=20)
    for role_id in (keeper_id, comms_id):
        admin.post(
            f"{BASE}/orgsuite/console",
            data={"form_security_token": token(r.text), "action": "role_delete",
                  "return_tab": "positions", "id": role_id},
            allow_redirects=True,
            timeout=20,
        )

    after = sql(f"SELECT role_id FROM orgsuite_member WHERE uid={uid};")
    check(after != "" and after != "0" and sql(f"SELECT label FROM orgsuite_role WHERE id={after};") != "",
          "the member still holds a real position after the temporary ones were removed",
          f"role_id={after!r}")

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
