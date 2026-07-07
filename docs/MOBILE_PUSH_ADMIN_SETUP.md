# Mobile push — super admin setup guide

This guide is for **platform super administrators** configuring push notifications in the Centrix web app.

**Where to configure:** Platform → **Mobile push** (`/platform/push`)

**Cost:** Firebase Cloud Messaging (FCM) is **free** — no per-message charges.

---

## What push notifications do

| App | Who receives | When |
|-----|----------------|------|
| **Centrix Manager** | Managers / approvers | A new approval is waiting (discount, cancellation, etc.) |
| **Centrix Mobile** | Field sales reps | Their discount request was approved or rejected |

Users still get in-app alerts if push is not configured; push delivers notifications when the app is in the background or closed.

---

## Before you start

You need:

- A Google account with access to [Firebase Console](https://console.firebase.google.com/)
- Super admin login to Centrix web
- Your mobile developer (or release build pipeline) to place Firebase config files in the Manager and Mobile apps

One Firebase project covers **both** apps.

---

## Step 1 — Create a Firebase project

1. Open [Firebase Console](https://console.firebase.google.com/)
2. Click **Add project**
3. Name it (e.g. `Centrix Production`)
4. Complete the wizard (Google Analytics is optional)

---

## Step 2 — Register Android apps

In the same Firebase project, add **two** Android apps:

| App | Package name |
|-----|----------------|
| Centrix Manager | `com.centrix.centrix_manager_app` |
| Centrix Mobile | `com.centrix.mobile` |

For each app:

1. Project overview → **Add app** → **Android**
2. Enter the package name **exactly** as shown above
3. Download `google-services.json`
4. Give the file to your mobile developer (they add it before building the app)

---

## Step 3 — Register iOS apps (if applicable)

| App | Bundle ID |
|-----|-----------|
| Centrix Manager | `com.centrix.centrixManagerApp` |
| Centrix Mobile | `com.centrix.mobile` |

Download `GoogleService-Info.plist` for each and hand off to your mobile developer. iOS push requires a physical device and the Push Notifications capability in Xcode.

---

## Step 4 — Copy your Firebase project ID

1. Firebase → **Project settings** (gear icon)
2. Copy **Project ID** (e.g. `centrix-production`)

You will paste this on the Centrix **Mobile push** settings page.

---

## Step 5 — Create a service account key

The Centrix server sends push messages using a Google service account.

1. Open [Google Cloud Console](https://console.cloud.google.com/) and select the **same project** as Firebase
2. **IAM & Admin** → **Service Accounts** → **Create service account**
   - Name: e.g. `centrix-fcm`
3. Open the new account → **Keys** → **Add key** → **JSON** → download
4. **APIs & Services** → **Library** → search **Firebase Cloud Messaging API** → **Enable**

Keep the JSON file secure — treat it like a password.

---

## Step 6 — Configure Centrix (Platform → Mobile push)

1. Log in as **super admin**
2. Go to **Platform → Mobile push**
3. Click **Setup guide** if you want the in-app walkthrough
4. Fill in:
   - **Enable push notifications** — on
   - **Firebase project ID** — from step 4
   - **Service account JSON** — open the downloaded JSON in a text editor, select all, paste
5. Click **Save push settings**
6. Check **Diagnostics** — status should show **Ready**

Centrix stores the JSON securely on the server. You do not need to edit `.env` files unless you prefer that workflow.

---

## Step 7 — Users register their devices

Push only works after a phone has logged in and allowed notifications:

1. Install **Centrix Manager** or **Centrix Mobile** from your distribution channel (Play Store, TestFlight, APK, etc.)
2. Log in with a real user account
3. Allow notifications when the app asks (Android 13+: Settings → Apps → Centrix → Notifications if declined)

---

## Step 8 — Send a test push

On **Platform → Mobile push**, scroll to **Send test push**:

1. Enter the user’s numeric **User ID** (find in Platform → Active users or your user admin)
2. Choose **Centrix Manager** or **Centrix Mobile (sales)**
3. Click **Send test push**

The device should receive a notification within a few seconds.

---

## Step 9 — Verify a real workflow

1. **Manager push:** Create an order or action that triggers an approval → assigned manager should get a push
2. **Sales push:** Approve or reject a discount from the manager app → the sales rep who requested it should get a push on Centrix Mobile

---

## Handoff to your mobile developer

After Firebase apps are registered, the developer should:

```bash
# Centrix Manager
cd centrix_manager_app && flutterfire configure

# Centrix Mobile field sales
cd centrixerpmobileapp && flutterfire configure
```

Rebuild and release both apps so `google-services.json` / plist files are bundled.

Technical reference: `centrix_manager_app/docs/FIREBASE_SETUP.md`

---

## Troubleshooting

| Problem | What to check |
|---------|----------------|
| Diagnostics not **Ready** | FCM API enabled in Google Cloud; correct project ID; valid JSON pasted |
| OAuth token failed | Wrong or expired service account key; re-download JSON and save again |
| Test push: no tokens | User must open the app and log in; check notification permission on the phone |
| Push works in test but not on approval | Push enabled; user has a real FCM token (not `mgr-local-*` / `mob-local-*`) |
| Android: no notification | Notification permission granted; app not force-stopped |
| iOS: no notification | Physical device (not simulator); Push capability enabled; production `aps-environment` for App Store builds |

Server logs (for your technical team):

```bash
tail -f storage/logs/laravel.log | grep -i fcm
```

Artisan checks (optional):

```bash
php artisan manager:push status
php artisan manager:push test --user-id=1 --app=manager
```

---

## Production checklist

- [ ] Firebase project is production (not a personal test project)
- [ ] Both Android apps registered with correct package names
- [ ] FCM API enabled in Google Cloud
- [ ] Platform → Mobile push shows **Ready**
- [ ] Manager and Mobile apps rebuilt with Firebase config files
- [ ] Test approval and discount outcome flows verified on real devices
- [ ] Service account JSON not committed to source control
