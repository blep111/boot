import requests, random, time, threading, uuid, os
from faker import Faker

fake = Faker()

WORDS = [
    "hi", "hello", "lol", "bruh", "xd", "pog", "yeet", "sheesh",
    "sus", "among us", "roblox", "minecraft", "ez", "gg", "noob",
    "pro", "lmao", "rofl", "btw", "ngl", "fr", "on god", "deadass",
    "bussin", "cap", "no cap", "ratio", "cope", "seethe", "mald"
]

URL = "https://ngl.link/api/submit"

def show_menu():
    print("\n" + "-"*30)
    print("       NGL SPAM TOOL")
    print("-"*30)
    print("[0] Start Spam")
    print("[1] About Creator")
    print("[2] Exit")
    print("-"*30)
    return input("\nChoose [0/1/2]: ").strip()

def show_creator():
    os.system('cls' if os.name == 'nt' else 'clear')
    print("\n" + "-"*30)
    print("         CREATOR")
    print("-"*30)
    print("Name: JHAMES RHONNIELLE MARTIN")
    print("Title: Web developer")
    print("Level: Beginner")
    print("FB: fb.com/jhames.rhonnielle.martin")
    print("-"*30)
    print("Dont Change the author name")
    
    url = "https://www.facebook.com/jhames.rhonnielle.martin"
    print(f"\nURL: {url}")
    
    try:
        if 'TERMUX_VERSION' in os.environ:
            os.system(f"termux-open-url '{url}'")
        elif os.name == 'nt':
            os.system(f"start {url}")
        else:
            os.system(f"xdg-open {url}")
        print("Opened in browser.")
    except:
        print("Open manually.")
    
    input("\nPress ENTER...")
    main()

def get_session(target):
    s = requests.Session()
    s.headers.update({
        "Content-Type": "application/json",
        "User-Agent": fake.user_agent(),
        "Origin": "https://ngl.link",
        "Referer": f"https://ngl.link/{target}",
        "Accept": "application/json"
    })
    return s

def spam_thread(target, count, delay):
    global sent, failed
    s = get_session(target)
    done = 0
    while done < count and sent < MSG_COUNT:
        try:
            msg = random.choice(WORDS)
            data = {
                "username": target,
                "question": msg,
                "deviceId": str(uuid.uuid4()),
                "gameSlug": ""
            }
            r = s.post(URL, json=data, timeout=10)
            if r.status_code == 200:
                sent += 1
                done += 1
                print(f"[OK] {sent}/{MSG_COUNT} -> {msg}")
            elif r.status_code == 429:
                print("Rate limit. Wait 10s...")
                time.sleep(10)
            else:
                failed += 1
                print(f"Fail [{r.status_code}]")
                time.sleep(2)
            time.sleep(delay + random.uniform(0.2, 0.8))
        except:
            failed += 1
            time.sleep(3)
            s = get_session(target)

def start_spam():
    global TARGET, MSG_COUNT, DELAY, THREADS, sent, failed, WORDS
    
    os.system('cls' if os.name == 'nt' else 'clear')
    print("\n" + "-"*30)
    print("        SPAM SETUP")
    print("-"*30)
    
    TARGET = input("\nUsername: ").strip()
    if not TARGET:
        print("No username.")
        return
    
    try:
        MSG_COUNT = int(input("Messages (1-5000): ") or 100)
        DELAY = float(input("Delay (0.5-3): ") or 1.0)
        THREADS = min(int(input("Threads (1-8): ") or 4), 8)
    except:
        MSG_COUNT, DELAY, THREADS = 100, 1.0, 4
    
    choice = input("\n[0] Random\n[1] Custom\nChoose: ").strip()
    if choice == "1":
        msg = input("Custom message: ").strip()
        if msg:
            WORDS = [msg]
    
    sent = failed = 0
    
    print(f"\nTarget: @{TARGET}")
    print(f"Config: {MSG_COUNT} msg | {THREADS} threads | {DELAY}s delay")
    
    for i in range(3, 0, -1):
        print(f"Start in {i}...", end="\r")
        time.sleep(1)
    
    print("\nSpamming... Ctrl+C to stop\n")
    
    threads = []
    for _ in range(THREADS):
        t = threading.Thread(target=spam_thread, args=(TARGET, MSG_COUNT//THREADS + 1, DELAY), daemon=True)
        t.start()
        threads.append(t)
        time.sleep(0.2)
    
    try:
        while any(t.is_alive() for t in threads) and sent < MSG_COUNT:
            time.sleep(1)
            print(f"Sent: {sent} | Fail: {failed} | Active: {threading.active_count()-1}", end="\r")
        print("\n\nDone!")
        print(f"Sent: {sent}, Failed: {failed}")
    except KeyboardInterrupt:
        print("\n\nStopped.")
        print(f"Sent: {sent}, Failed: {failed}")
    
    input("\nPress ENTER...")
    main()

def main():
    os.system('cls' if os.name == 'nt' else 'clear')
    while True:
        c = show_menu()
        if c == "0":
            start_spam()
        elif c == "1":
            show_creator()
        elif c == "2":
            print("\nThank U salamuch for using my Code \nNote: Dont Change my name or Creator's Name!")
            break
        else:
            print("Invalid.")
            time.sleep(1)

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nExit.")