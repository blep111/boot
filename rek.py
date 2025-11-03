#!/usr/bin/env python3
"""
facebook_helper_live.py
Termux-friendly CLI tool that attempts to perform real Facebook actions.

Features:
- Extract post/profile IDs from URLs (same logic as your PHP)
- Use either Graph API (access_token) OR cookie-based web requests
- Actions: validate token (Graph), post comment, send reaction (Graph), follow (Graph)
- Persistent JSON storage for cooldowns and stats (rek_live_data.json)
- Interactive prompts; prints results/logs to terminal

Requirements:
pip install requests bs4
(If running in Termux: pkg install python -y; pip install requests bs4)
"""

import os, time, json, re, requests, secrets, sys
from urllib.parse import urlparse
from bs4 import BeautifulSoup

# ---------- Config ----------
DATA_FILE = "rek_live_data.json"
USER_AGENT = "Mozilla/5.0 (Linux; Android 10; Mobile) FacebookAutomation/1.0"
COOLDOWN_SECONDS = 10 * 60  # 10 minutes (same as PHP)
REQUEST_TIMEOUT = 12

# ---------- Persistence ----------
if os.path.exists(DATA_FILE):
    with open(DATA_FILE, "r") as f:
        DATA = json.load(f)
else:
    DATA = {"cooldowns": {}, "stats": {"runs": 0, "success": 0, "fail": 0}}

def save_data():
    with open(DATA_FILE, "w") as f:
        json.dump(DATA, f, indent=2)

# ---------- Helpers (ID extraction; mirrors PHP patterns) ----------
def extract_post_id(url):
    patterns = [
        r'/posts/([\w\d]+)/',
        r'groups/(\d+)/permalink/(\d+)/',
        r'story\.php\?story_fbid=([0-9]+)',
        r'photo\.php\?fbid=([0-9]+)',
        r'permalink\.php\?story_fbid=([0-9]+)',
        r'/videos/([0-9a-zA-Z]+)/',
        r'fbid=([0-9]+)'
    ]
    for p in patterns:
        m = re.search(p, url)
        if m:
            return m.group(1)
    parsed = urlparse(url)
    path = parsed.path or ''
    m = re.search(r'(\d+)_(\d+)', path)
    if m:
        return m.group(0)
    m = re.search(r'(\d+)', path)
    if m:
        return m.group(1)
    return None

def extract_profile_id(url):
    patterns = [
        r'facebook\.com/(?:profile\.php\?id=)?(\d+)',
        r'fbid=(\d+)',
        r'facebook\.com/([^\/?]+)'
    ]
    for p in patterns:
        m = re.search(p, url)
        if m:
            return m.group(1)
    return None

def validate_facebook_url(url):
    return bool(re.search(r'facebook\.com/.+|fb\.com/.+|web\.facebook\.com/.+', url))

# ---------- Cooldown logic (persisted) ----------
def get_remaining_cooldown(last_used_timestamp):
    now = int(time.time())
    elapsed = now - last_used_timestamp
    remaining = COOLDOWN_SECONDS - elapsed
    return max(0, remaining)

def get_id_cooldown_info(id_value):
    rec = DATA.get("cooldowns", {}).get(id_value)
    if rec:
        remaining = get_remaining_cooldown(int(rec))
        if remaining > 0:
            remaining_minutes = (remaining + 59) // 60
            return {"in_cooldown": True, "remaining_time": remaining_minutes}
    return {"in_cooldown": False, "remaining_time": 0}

def check_cooldown(id_value):
    DATA.setdefault("cooldowns", {})[id_value] = int(time.time())
    save_data()

# ---------- Graph API helpers (use access_token) ----------
def validate_token_graph(access_token):
    url = f"https://graph.facebook.com/me?fields=id,name&access_token={access_token}"
    try:
        r = requests.get(url, headers={"User-Agent": USER_AGENT}, timeout=REQUEST_TIMEOUT)
        j = r.json()
        return j if "id" in j else None
    except Exception as e:
        print("Graph validate error:", e)
        return None

def post_comment_graph(post_id, message, access_token):
    url = f"https://graph.facebook.com/{post_id}/comments"
    data = {"access_token": access_token, "message": message}
    try:
        r = requests.post(url, data=data, headers={"User-Agent": USER_AGENT}, timeout=REQUEST_TIMEOUT)
        j = r.json() if r.content else {}
        return (200 <= r.status_code < 300) and ("id" in j)
    except Exception as e:
        print("Graph post_comment error:", e)
        return False

def send_reaction_graph(post_id, reaction_type, access_token):
    url = f"https://graph.facebook.com/{post_id}/reactions"
    data = {"access_token": access_token, "type": reaction_type}
    try:
        r = requests.post(url, data=data, headers={"User-Agent": USER_AGENT}, timeout=REQUEST_TIMEOUT)
        return r.status_code == 200
    except Exception as e:
        print("Graph send_reaction error:", e)
        return False

def follow_user_graph(user_id, access_token):
    url = f"https://graph.facebook.com/{user_id}/subscribers"
    data = {"access_token": access_token}
    try:
        r = requests.post(url, data=data, headers={"User-Agent": USER_AGENT}, timeout=REQUEST_TIMEOUT)
        return r.status_code == 200
    except Exception as e:
        print("Graph follow_user error:", e)
        return False

# ---------- Cookie-based web helpers (best-effort; brittle) ----------
# These functions attempt to use mobile.facebook.com to post comments.
# They parse fb_dtsg and necessary form fields from the page and then send the POST.
# This is fragile and may fail if Facebook changes markup.

def build_cookie_dict(cookie_header):
    # Accepts cookie string like "cookie1=val; cookie2=val; ...", returns dict
    parts = [p.strip() for p in cookie_header.split(";") if "=" in p]
    return dict(p.split("=", 1) for p in parts)

def get_fb_dtsg_and_jazoest(session, url):
    # GET the page and parse fb_dtsg and jazoest
    try:
        r = session.get(url, timeout=REQUEST_TIMEOUT)
        if r.status_code != 200:
            return None, None
        soup = BeautifulSoup(r.text, "html.parser")
        fb_dtsg = None
        jazoest = None
        for inp in soup.find_all("input"):
            name = inp.get("name", "")
            if name == "fb_dtsg":
                fb_dtsg = inp.get("value", "")
            if name == "jazoest":
                jazoest = inp.get("value", "")
        return fb_dtsg, jazoest
    except Exception as e:
        print("Error fetching fb_dtsg:", e)
        return None, None

def post_comment_cookie(post_url, message, cookie_header):
    session = requests.Session()
    session.headers.update({"User-Agent": USER_AGENT})
    session.cookies.update(build_cookie_dict(cookie_header))
    # attempt to fetch fb_dtsg
    fb_dtsg, jazoest = get_fb_dtsg_and_jazoest(session, post_url)
    if not fb_dtsg:
        print("Could not extract fb_dtsg/jazoest from post page. Cookie method may fail.")
        return False
    # post endpoint: use /ufi/add/comment/ or the mobile endpoint present in page forms
    # We will attempt a POST to /a/comment.php (older) or use the comment form action if found
    try:
        # Re-get the page and find a form with action containing "comment" or "composer"
        r = session.get(post_url, timeout=REQUEST_TIMEOUT)
        soup = BeautifulSoup(r.text, "html.parser")
        form_action = None
        for form in soup.find_all("form"):
            action = form.get("action", "")
            if "comment" in action or "composer" in action or "add" in action:
                form_action = action
                break
        if not form_action:
            # fallback to a common endpoint
            form_action = "/a/comment.php"
        # ensure full url
        if form_action.startswith("/"):
            comment_url = "https://m.facebook.com" + form_action
        elif form_action.startswith("http"):
            comment_url = form_action
        else:
            comment_url = post_url.rstrip("/") + "/" + form_action
        payload = {
            "fb_dtsg": fb_dtsg,
            "jazoest": jazoest,
            "comment_text": message,
            "submit": "Send"
        }
        r2 = session.post(comment_url, data=payload, timeout=REQUEST_TIMEOUT, allow_redirects=True)
        # consider success if we get 200 or 302
        return r2.status_code in (200, 302)
    except Exception as e:
        print("Cookie post error:", e)
        return False

# ---------- CLI / Interactive flows ----------
def choose_credential_mode():
    print("Choose credential mode:")
    print(" 1) access_token (Graph API) - preferred if you have a valid token")
    print(" 2) cookie (web UI) - brittle; use only if you don't have a token")
    mode = input("Mode (1/2): ").strip()
    if mode == "1":
        token = input("Enter access_token: ").strip()
        return ("token", token)
    else:
        cookie = input("Enter cookie header (format: key=value; key2=value2; ...): ").strip()
        return ("cookie", cookie)

def do_comment_flow(cred_type, cred_value):
    url = input("Enter Facebook post URL or post ID: ").strip()
    if validate_facebook_url(url) or re.match(r'^\d+(_\d+)?$', url):
        pid = extract_post_id(url) if not re.match(r'^\d+(_\d+)?$', url) else url
        if not pid:
            print("Could not extract post ID from URL. Try a direct post ID.")
            return
    else:
        print("Invalid Facebook URL.")
        return
    # cooldown check
    cd = get_id_cooldown_info(pid)
    if cd["in_cooldown"]:
        print(f"ID is in cooldown for {cd['remaining_time']} more minute(s). Aborting.")
        return
    message = input("Enter comment text: ").strip()
    print("Posting comment...")
    ok = False
    if cred_type == "token":
        ok = post_comment_graph(pid, message, cred_value)
    else:
        # If user gave a URL, ensure we have a usable page URL for parsing
        page_url = url if url.startswith("http") else f"https://m.facebook.com/{pid}"
        ok = post_comment_cookie(page_url, message, cred_value)
    if ok:
        print("Comment posted successfully.")
        DATA["stats"]["success"] += 1
        check_cooldown(pid)
    else:
        print("Failed to post comment.")
        DATA["stats"]["fail"] += 1
    DATA["stats"]["runs"] += 1
    save_data()

def do_react_flow(cred_type, cred_value):
    url = input("Enter Facebook post URL or post ID: ").strip()
    pid = extract_post_id(url) if not re.match(r'^\d+(_\d+)?$', url) else url
    if not pid:
        print("Could not extract post ID.")
        return
    reaction = input("Enter reaction type (LIKE, LOVE, WOW, SAD, ANGRY): ").strip().upper()
    ok = False
    if cred_type == "token":
        ok = send_reaction_graph(pid, reaction, cred_value)
    else:
        print("Cookie-based reaction is not implemented reliably here. Use access_token for reactions.")
        return
    if ok:
        print("Reaction sent.")
        DATA["stats"]["success"] += 1
    else:
        print("Failed to send reaction.")
        DATA["stats"]["fail"] += 1
    DATA["stats"]["runs"] += 1
    save_data()

def do_follow_flow(cred_type, cred_value):
    url = input("Enter Facebook profile URL or profile ID: ").strip()
    pid = extract_profile_id(url) if not re.match(r'^\d+$', url) else url
    if not pid:
        print("Could not extract profile ID.")
        return
    ok = False
    if cred_type == "token":
        ok = follow_user_graph(pid, cred_value)
    else:
        print("Cookie-based follow is not implemented reliably here. Use access_token for follow.")
        return
    if ok:
        print("Follow (subscribe) succeeded.")
        DATA["stats"]["success"] += 1
    else:
        print("Follow failed.")
        DATA["stats"]["fail"] += 1
    DATA["stats"]["runs"] += 1
    save_data()

def do_validate_token_flow(token):
    info = validate_token_graph(token)
    if info:
        print("Token valid. User info:")
        print(json.dumps(info, indent=2))
    else:
        print("Token invalid or expired.")

# ---------- Main ----------
def main():
    print("="*40)
    print("Facebook Live Helper (Termux)")
    print("IMPORTANT: Only use credentials for accounts YOU OWN.")
    print("="*40)
    mode, cred = choose_credential_mode()
    while True:
        print("\nActions:")
        print(" 1) Comment on a post")
        print(" 2) Send reaction (Graph token only)")
        print(" 3) Follow user (Graph token only)")
        print(" 4) Validate access token (Graph only)")
        print(" 5) Show stats")
        print(" 6) Exit")
        choice = input("Choose action (1-6): ").strip()
        if choice == "1":
            do_comment_flow(mode, cred)
        elif choice == "2":
            do_react_flow(mode, cred)
        elif choice == "3":
            do_follow_flow(mode, cred)
        elif choice == "4":
            if mode != "token":
                print("Validation requires an access_token. Rerun and choose token mode.")
            else:
                do_validate_token_flow(cred)
        elif choice == "5":
            print("Stats:", json.dumps(DATA.get("stats", {}), indent=2))
            print("Cooldowns keys:", list(DATA.get("cooldowns", {}).keys())[:20])
        elif choice == "6":
            print("Exiting.")
            break
        else:
            print("Invalid choice.")

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nInterrupted. Exiting.")
        save_data()
        sys.exit(0)
