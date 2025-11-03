import json

def cookie_to_appstate(cookie_str):
    cookies = {}
    for part in cookie_str.split(";"):
        part = part.strip()
        if not part or "=" not in part:
            continue
        k, v = part.split("=", 1)
        cookies[k.strip()] = v.strip()

    appstate = []
    for k, v in cookies.items():
        appstate.append({
            "key": k,
            "value": v,
            "domain": ".facebook.com",
            "path": "/",
            "hostOnly": False,
            "creation": 0,
            "lastAccessed": 0
        })
    return appstate


print("📘 Facebook Appstate Generator\n")
cookie_input = input("Paste your Facebook cookies (example: c_user=...; xs=...; fr=...):\n\n> ").strip()

if not cookie_input:
    print("\n❌ No cookies provided! Exiting.")
else:
    try:
        appstate = cookie_to_appstate(cookie_input)
        json_data = json.dumps(appstate, indent=4)
        print("\n✅ Generated appstate.json:\n")
        print(json_data)
        with open("appstate.json", "w") as f:
            f.write(json_data)
        print("\n💾 Saved to appstate.json successfully!")
    except Exception as e:
        print(f"\n❌ Error: {e}")
