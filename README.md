<p align="center">
  <img src="docs/assets/img/chilemon-banner.svg" alt="ChileMon Banner">
</p>

<p align="center">
<img src="docs/assets/img/dashboard-main.png" width="900">
</p>

##

<p align="center">
Modern dashboard for monitoring and controlling AllStarLink nodes
</p>

<p align="center">
<img src="https://img.shields.io/badge/version-0.2.3-blue">
<img src="https://img.shields.io/badge/php-8.2+-blue">
<img src="https://img.shields.io/badge/database-SQLite-green">
<img src="https://img.shields.io/badge/ASL3-compatible-green">
<img alt="Static Badge" src="https://img.shields.io/badge/Bootstrap-5-purple">
<img alt="Static Badge" src="https://img.shields.io/badge/Java-Script-EFD81C">
<img alt="Static Badge" src="https://img.shields.io/badge/License-MIT-blue">
</p>

#
# 
<p align="center">
  <img src="docs/assets/img/chile-flag-brush.png" alt="ChileMon Banner" style="width:130px; height:auto;">
</p>

A modern dashboard for monitoring and controlling **AllStarLink (ASL3)** nodes. ChileMon was created as a modern alternative inspired by **Supermon**, designed to provide a clearer, more modular, and easier-to-install interface for AllStar node operators.

The goal of the project is to provide a simple and secure tool to view and control the activity of an AllStarLink node from a web browser.

## 🌐 Languages

🇪🇸 Spanish documentation: `README_ES.md`  
🇬🇧 English documentation: `README.md`

---

# 📡 What is ChileMon?

ChileMon is a web application that allows you to:

- monitor connected nodes
- connect remote nodes
- disconnect nodes
- visualize the linked node network
- review node statistics
- manage favorite nodes
- view recent activity

All from a modern web interface.

---

# ✨ Main Features

ChileMon currently includes:

- Web dashboard for AllStarLink node monitoring
- Remote node connection and disconnection
- Real-time visualization of connected nodes
- Node statistics obtained from Asterisk
- Modal window to visualize the linked node network
- Favorite node management
- Recent activity logging
- Secure command execution through a wrapper
- Automatic installer for ASL3 systems

---

# 🧠 Project Philosophy

ChileMon was designed with the following principles:

- **Do not interfere with AllStarLink**
- **Do not modify Asterisk**
- **Do not replace Supermon**
- **Be a modular tool**
- **Be easy to install**
- **Be secure**

ChileMon works as an **additional module**, without altering the node’s base installation.

---

# 🏗 Architecture

ChileMon is built using simple and robust technologies.

### Backend

- PHP 8+
- SQLite

### Frontend

- Bootstrap 5
- JavaScript
- AJAX

### Server

- Apache

---

# 🔐 Security

ChileMon **does not execute Asterisk commands directly from PHP**.

Instead, it uses a secure wrapper:

```php
/usr/local/bin/chilemon-rpt
```

This wrapper allows only specific commands to be executed:

 - rpt nodes
 - rpt stats
 - rpt connect
 - rpt disconnect

The commands are executed through a restricted sudo rule for the user:

```php
www-data
```

This prevents arbitrary command execution on the system.

---

## ⚙ How ChileMon Works

ChileMon obtains node information by running Asterisk rpt commands.

Example:

```php
sudo -u www-data sudo -n chilemon-rpt nodes 494780
```

The output is processed by the service:

```php
src/Services/AslRptService.php
```

This service interprets the Asterisk output and extracts information such as:

 - connected nodes
 - node status
 - system statistics

The processed data is then displayed on the web dashboard.

---

## 🖥 Dashboard

The main dashboard allows you to view:

 - local node
 - connected nodes
 - system status
 - linked node network
 - recent activity

It also allows you to:

 - connect nodes
 - disconnect nodes
 - manage favorites

---

## 🔎 ChileMon vs Supermon

Supermon is the classic interface used by many AllStarLink nodes.

ChileMon is inspired by that concept, but introduces a more modern architecture.


| Feature	| Supermon	| ChileMon |
|---|---|---|
| Interface	| Classic HTML	| Modern dashboard |
| Authentication | Basic login | User system |
| Database | No | SQLite |
| Installation | Manual | Automatic installer |
| Architecture | Monolithic | Modular |
| Extensibility | Limited | Designed for expansion |

ChileMon **does not replace Supermon**, but instead offers a modern alternative for node monitoring and control.

---

## 📷 Screenshots

### Dashboard

![Dashboard](docs/assets/img/screenshot01.png)

### Red de nodos

![Network](docs/assets/img/network.png)

### Favoritos

![Favorites](docs/assets/img/favorites.png)

---


## README.md

# 🚀 Quick Installation (v0.2.x)

To install ChileMon on an ASL3 node (Debian), simply run this block in your terminal:

```bash
# 1. Clone the repository
sudo git clone https://github.com/gismodes37/Chilemon.git /opt/chilemon

# 2. Enter the directory
cd /opt/chilemon

# 3. Run the automatic installer
# (Make sure to have your node ID and AMI password ready)
sudo bash install/install_chilemon.sh
```

The installer will automatically:
- Install dependencies (PHP, Apache, SQLite).
- Configure your local node.
- Install the secure Asterisk wrapper (v0.2.3).
- Configure database permissions.
- Set up Apache to be ready at `http://your_ip/chilemon`.

 ---

 ## 📂 Project Structure


    chilemon/
     │
     ├── app/
     │   └── Core/
     │       └── Database.php
     │
     ├── config/
     │   ├── app.php
     │   └── database.php
     │
     ├── data/
     │   └── chilemon.sqlite   (should not be versioned)
     │
     ├── logs/
     │
     ├── public/
     │   ├── index.php
     │   ├── login.php
     │   ├── logout.php
     │   ├── api/
     │   │   ├── log-call.php
     │   │   ├── nodes.php
     │   │   └── stats.php
     │   └── assets/
     │
     ├── install/
     ├── bin/
     ├── README_ES.md
     ├── README.md
     ├── CHANGELOG.md
     ├── LICENSE.md
     └── .gitignore

---

## 📈 Roadmap

<img src="https://img.shields.io/badge/version-0.1.0-blue">

Initial functional release

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.2.0-blue">

Real-time RX/TX activity

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.3.0-blue">

Dashboard improvements

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.4.0-blue">

Extended events and statistics

<img alt="Static Badge" src="https://img.shields.io/badge/Version-1.0-green">

Stable release

---

## 📦 Release

### v0.1.0

First functional release of ChileMon.

Includes:

 - Operational dashboard
 - Node connection and disconnection
 - Node network visualization
 - Favorites management
 - Recent activity
 - Automatic installer
 - AllStarLink integration

 ---

 ## ❤️ Support ChileMon

ChileMon is an independent project developed for the **amateur radio** and **AllStarLink** community. If this project is useful to you, you can support its development with a **voluntary donation**.

### What do donations help with?

ChileMon is developed independently. Donations help sustain the project and allow it to continue improving.

- Continuous dashboard development
- Testing on real AllStarLink nodes
- Security and stability improvements
- Project documentation and website
- Maintenance and future evolution

### Ways to support

- **PayPal:** [Support Chilemon](https://www.paypal.com/ncp/payment/J56JZF5CPRBVG)
- **GitHub Sponsors:** [gismodes37](https://github.com/sponsors/gismodes37)

You can also support the project by contributing code, reporting bugs, or testing ChileMon on real nodes.

> ChileMon is a community project for amateur radio operators. Donations are completely voluntary and help sustain the development of the project.

---


 ## 🤝 Contributions

Contributions are welcome.

If you would like to collaborate:

 1. Fork the repository
 2. Create a new branch
 3. Submit a pull request

 ---

 ## 📄 License

ChileMon is distributed under the MIT

<img alt="Static Badge" src="https://img.shields.io/badge/License-MIT-blue">

---

