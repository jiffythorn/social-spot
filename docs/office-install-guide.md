# Setting up the community site (step-by-step guide)

This guide installs your organization's private website on an office computer.
No technical background is needed - just follow the steps in order and read
what the screen actually says. It takes about half an hour, most of which is
waiting for downloads to finish.

**What you are installing:** a website for your organization that runs on this
computer. Members open it in their browsers to see announcements, pay dues,
read documents, and manage membership. You are the person who sets it up, not
the person who has to maintain software afterwards.

## Before you start

You need:

- A computer that can stay switched on and connected to the internet whenever
  members should reach the site (a shared office computer is ideal; a laptop
  that goes home in a bag is not).
- The password you use to install programs on that computer. If installing
  programs normally asks someone else (an IT person), ask them to sit with you
  for steps 1 and 2.
- About 3 GB of free disk space and a reasonably fast internet connection.

One decision first: **will only you use the site on this computer, or should
colleagues open it from their own computers too?**

- *Just me, on this computer:* accept every default the installer offers.
- *Colleagues on the office network:* you will type this computer's network
  address during setup instead of accepting the default (step 4 explains how
  to find it). Everyone must be on the same office Wi-Fi or wired network.

## Step 1 - install Docker Desktop

Docker is the engine the site runs inside. You install it once and never think
about it again, except for starting it (step 6).

**On Windows:**

1. Go to <https://www.docker.com/products/docker-desktop/> and click the big
   download button for Windows.
2. Open the downloaded file and click through the installer (all defaults).
3. If it asks you to restart the computer, restart it.
4. After restarting, it may ask to install an extra Windows piece (WSL 2).
   Say yes and let it finish.
5. Open Docker Desktop from the Start menu. The first start takes a few
   minutes and may ask you to accept some terms - accept them.
6. Wait until the little whale icon at the bottom of the Docker window stops
   animating. That means the engine is running. Leave Docker Desktop open.

**On a Mac:**

1. Go to <https://www.docker.com/products/docker-desktop/> and download the
   version for your Mac (Apple chip or Intel - the site tells you which).
2. Open the downloaded file and drag the Docker icon into Applications.
3. Open Docker from Applications and accept the terms.
4. Wait until the whale icon at the top of the screen stops animating. Leave
   Docker running.

**On Linux:**

1. Open the software centre (on Ubuntu it is called **Ubuntu Software**) and
   install **Docker**. If you would rather use a terminal, the packages for
   Ubuntu and Debian are `sudo apt install docker.io docker-compose-v2`, and
   for Fedora `sudo dnf install docker docker-compose`.
2. Start it now, and arrange for it to start whenever the computer is switched
   on: `sudo systemctl enable --now docker`.
3. Let your own account talk to it, so the steps below do not each need the
   word `sudo` in front: `sudo usermod -aG docker "$USER"`, then **sign out and
   back in** - that is what makes the change take effect.

If Docker was already on this computer, step 2 is usually the only thing
missing - and if it is missing, the installer now says so before asking you
anything, rather than failing at the end.

## Step 2 - download the site software

1. Open the download link your administrator sent you. It is a file named
   something like `social-spot-2026.10.17.zip`.
2. Save it somewhere easy to find, for example your Documents folder.
3. Open the folder containing it, right-click the file, and choose
   **Extract All** (Windows) or double-click it (Mac). You now have a folder
   called `social-spot-...` - remember where it is.

## Step 3 - run the installer

**On Windows:** open the `social-spot-...` folder, then open the `packaging`
folder inside it, and double-click **`Install-Windows`**. If Windows asks
"do you want to allow this?", click Yes. A dark window opens - that is the
installer talking to you.

**On a Mac:** open the Terminal app (find it with Spotlight: press Cmd+Space,
type `Terminal`, press Enter). Then:

1. Type `cd ` (the letters c, d, and a space - don't press Enter yet).
2. Open the `social-spot-...` folder, then the `packaging` folder inside it,
   and drag that `packaging` folder into the Terminal window. The address
   appears after your `cd `. Now press Enter.
3. Type `bash install.sh` and press Enter.

**On Linux:** open the Files app, open the `social-spot-...` folder and then the
`packaging` folder inside it, right-click an empty part of the window and choose
**Open in Terminal** (on some desktops the entry is called **Open Terminal
Here**). If there is no such entry, open a Terminal from the applications menu
instead, then:

1. Type `cd ` (the letters c, d, and a space - don't press Enter yet).
2. Drag the `packaging` folder onto the Terminal window. The address appears
   after your `cd `. Now press Enter.
3. Type `bash install.sh` and press Enter.

Whichever system you are on, `./install.sh` does the same thing if you prefer
it - the script is marked executable, so double-clicking it in a file manager
that offers to run scripts also works.

## Step 4 - answer five questions

The installer asks one thing at a time. Press Enter to accept the suggestion
shown in [brackets], or type your own answer first.

1. **Port** - accept the default (8080). Only change it if the installer says
   the port is busy.
2. **Public address** - this is what members type into their browsers.
   - Just you on this computer: accept the default.
   - Colleagues on the office network: type
     `http://THIS-COMPUTER:8080`, replacing THIS-COMPUTER with this
     computer's address. To find it on Windows, open a Command Prompt
     (Start menu, type `cmd`), type `ipconfig`, press Enter, and use the
     number on the `IPv4 Address` line (it looks like 192.168.1.20). On a
     Mac, it is in System Settings under Wi-Fi, next to "IP address".
3. **Organization name** - your association, club, or community name, exactly
   as members should see it.
4. **Kind of organization** - type the number: 1 for a homeowners
   association, 2 for a club, 3 for a non-profit, 4 for a general community.
   This only sets which features start switched on; everything can be changed
   later in the Officer console.
5. **Administrator account** - your account name, your email, and a password.
   Leave the password empty and a safe one is made for you - **write it down
   now**, it is shown once. (The remaining passwords it makes for the
   database are stored automatically; you never type them.)

## Step 5 - wait, then open the site

The installer downloads everything, builds the server, and starts the site.
The first time takes a while - roughly fifteen to forty-five minutes depending
on the computer, most of it the one-time build of the server's own components.
Later runs reuse that work and take a minute or two. When it finishes, it prints
your site address in green. Open that address in your browser and sign in with
the administrator account. You should see your organization's home page.

Click **Console** in the navigation. That is the Officer console - the one
place where membership, dues, documents, announcements, and settings live.
Have a look around; you cannot break anything by looking.

## Step 6 - invite your members

1. Give members the site address. They click it, choose to register, and fill
   in the form (including the address or membership number it asks for).
2. Their accounts wait until you approve them: open the Console, go to
   **Applications**, check each one, and click Approve.
3. Give a second trusted person an officer position (Console, Members tab) so
   the organization does not depend on one computer *and* one person.

### Members on their phones: no app to build or install

Members do organization business (dues, documents, directory, announcements)
in their phone's browser at the site address - it is designed to work there.
No custom app is needed, ever, so never pay anyone to build one.

If a member wants the social feed on their phone too, free apps connect
straight to your site: **Tusky** or **Friendiqa** (both free). They install
the app, type your site address as the server, and sign in with their normal
account. Two limits to tell them: signing up must happen in the browser
first (the address check and your approval live only there), and dues,
documents, and the directory always stay on the site itself, never in the
app. Full details: `docs/mobile-apps.md`.

**The easier route, which needs no app at all:** on any page of your site there
is a button that says **Add [your organization] to your home screen**. A member
taps it — on an iPhone it shows the Share → Add to Home Screen steps; on
Android it installs straight away — and the site appears on their home screen
with your icon, opening straight to the dashboard. Tell them this one first:
all of the organization's business works that way.

### Uploading your own logo

The icon members see — in their browser tab, and on their home screen — starts
as a built-in mark. To use your own: open the **Console**, **Organization
settings**, **Site icon**, choose a square image (PNG or JPEG, under 4 MB) and
click **Use this logo**. It is resized for every place it appears; nothing to
upload twice and no file to replace. **Remove** puts the built-in mark back.
Anyone whose position includes **Change organization settings** can do this —
it does not need the person who set up the server.

### Email (password resets and approval notices)

Out of the box the site cannot send email, which means two things do not work
until someone fills this in: **password resets** and **"your account is
approved" notices**. Announcements, dues, documents and the directory all work
without it.

When the club knows which mailbox the site should send from (a Gmail address,
an Outlook mailbox, your provider's mail - any mailbox you already own), an
officer opens the **Console**, **Organization settings**, **Email**, copies in
the provider's details and clicks **Send test email**. The full table of
settings for Gmail, Outlook, Yahoo, iCloud and web hosts - including where to
find the app password each one needs - is in `docs/email-setup.md`. Until that
test arrives, tell newly approved members yourself that they can sign in, and
plan for password resets to need a human instead of a link.

## Living with it

- **After restarting the computer**, open Docker Desktop first and wait for
  the whale to settle. The site starts itself a minute later - there is
  nothing else to click.
- **Back up once a week.** In the `packaging` folder, double-click
  **`Backup-Windows`** (Windows) or run `bash backup.sh` from that folder
  (Mac). It writes a dated backup file into `packaging/backups`. Copy the
  newest file somewhere that is not this computer - a USB stick, a shared
  drive, or cloud storage. A backup that lives only on this computer helps
  nobody if the computer dies.
- **Updating** means repeating steps 2 and 3 with the new version someone
  sends you, with one extra move in between: copy the file named `.env` from
  the *old* `packaging` folder into the *new* one first. That file holds all
  your answers and passwords, so the installer reuses them and your data is
  untouched. (Never delete the old folder until the new site opens correctly.)
- **Switching features on or off** (dues, committees, announcements...) is
  done in the Console under Organization settings, by an officer - no
  reinstalling, no new downloads.

## If something goes wrong

| What you see | What to do |
|---|---|
| The installer says Docker is missing or not running | Open Docker Desktop and wait for the whale to settle, then run the installer again. This fixes the large majority of problems. |
| The browser says the site can't be reached | Check Docker Desktop is running (above). Then check the computer didn't fall asleep - the site only answers while the computer is awake. |
| The installer says the port is busy | Something else uses 8080. Run the installer again and type a different port (e.g. 8081) at the first question - and adjust the address in question 2 to match. |
| A colleague can't open the site but you can | They are probably not on the same network, or the address still says `localhost` (which means "this computer only"). Redo step 4, question 2 with this computer's network address. |
| You forgot the administrator password | Anyone who can run commands can reset it (see `docs/packaging.md`), but this is a two-person job: ask whoever set the server up, or whoever sent you the download link. |
| An update or antivirus warning appears during install | Allow it - Docker and the installer are doing exactly what installers do (downloading programs, opening a local port). When in doubt, ask your IT person rather than blocking it. |

If none of this helps, write down the last thing the installer printed (a
photo of the screen is perfect) and send it to whoever gave you the download
link, along with whether the Docker whale is settled or still animating.
